<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionStopPhraseFilter;
use Illuminate\Support\Str;

/**
 * Bóc candidate anchor từ nội dung thật (highlight → noun phrase → heading fallback).
 * Không AI / embedding. Không đổi logic tìm internal link / semantic score bài đích.
 */
final class ArticleLinkSuggestionContentPhraseExtractor
{
    /** Điểm ưu tiên nguồn candidate (heading luôn thấp nhất). */
    public const SCORE_STRONG = 10;

    public const SCORE_MARK = 9;

    public const SCORE_EMPHASIS = 8;

    public const SCORE_ENTITY = 7;

    public const SCORE_NOUN_PHRASE = 6;

    public const SCORE_HEADING = 2;

    /**
     * Tiền tố heading bỏ đi để chỉ giữ cụm chính.
     *
     * @var list<string>
     */
    private const HEADING_PREFIXES = [
        'huong dan',
        'hướng dẫn',
        'cach',
        'cách',
        'tai sao',
        'tại sao',
        'luu y',
        'lưu ý',
        'cac',
        'các',
        'nhung',
        'những',
        'top',
        'so sanh',
        'so sánh',
        'bang',
        'bảng',
        'gioi thieu',
        'giới thiệu',
        'tong quan',
        'tổng quan',
        'loi ich',
        'lợi ích',
        'uu diem',
        'ưu điểm',
        'nhuoc diem',
        'nhược điểm',
        'ket luan',
        'kết luận',
        'tom tat',
        'tóm tắt',
        'danh sach',
        'danh sách',
        'how to',
        'why',
        'what is',
        'what are',
        'best',
        'guide',
        'tips',
    ];

    /**
     * @param  list<string>  $excludePhrases  Phrase đã suggestion / đã link (case-insensitive)
     * @param  list<string>  $priorityPhrases Focus / secondary keywords (entity-like) nếu có trong bài
     * @return list<array{phrase: string, source: string, offset: int, source_score: int}>
     */
    public function extract(string $html, array $excludePhrases = [], array $priorityPhrases = []): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $maxPhrases = max(1, (int) config('seo-content-ai.link_suggestions.fallback_phrase_limit', 10));
        $minWords = max(1, (int) config('seo-content-ai.link_suggestions.fallback_min_words', 2));
        $maxWords = max($minWords, (int) config('seo-content-ai.link_suggestions.fallback_max_words', 6));
        $repeatMin = max(2, (int) config('seo-content-ai.link_suggestions.fallback_repeated_ngram_min_count', 2));

        $excludeNorm = $this->normalizeSet($excludePhrases);
        foreach ($this->extractLinkedAnchorTexts($html) as $linked) {
            $excludeNorm[$this->normKey($linked)] = true;
        }

        $htmlWithoutLinks = $this->stripAnchorTagsKeepTextAsSpace($html);
        $candidates = [];

        // 1) Highlight — strong/b, mark, em/i
        $this->collectHighlightCandidates(
            $candidates,
            $htmlWithoutLinks,
            $excludeNorm,
            $minWords,
            $maxWords,
        );

        // 2) Focus/secondary keywords trong bài (entity-like), không phải heading outline
        foreach ($priorityPhrases as $priority) {
            $priority = $this->normalizeDisplayPhrase((string) $priority);
            if ($priority === '') {
                continue;
            }
            $wordCount = KeywordPhraseMatcher::countWords($priority);
            $allowSingle = $wordCount === 1 && mb_strlen(KeywordPhraseMatcher::normalize($priority)) >= 4;
            if ($wordCount < $minWords && ! $allowSingle) {
                continue;
            }
            if ($wordCount > $maxWords) {
                continue;
            }
            $this->pushCandidate(
                $candidates,
                $priority,
                'entity',
                self::SCORE_ENTITY,
                $htmlWithoutLinks,
                $excludeNorm,
            );
        }

        // 3) Noun phrase / entity heuristic trong paragraph (không lấy nguyên câu)
        if (count($candidates) < $maxPhrases) {
            $this->collectParagraphNounPhrases(
                $candidates,
                $htmlWithoutLinks,
                $excludeNorm,
                $minWords,
                $maxWords,
                $repeatMin,
            );
        }

        // 4) Heading chỉ khi vẫn thiếu — strip tiền tố «Hướng dẫn…», «Các…»
        if (count($candidates) < $maxPhrases) {
            $this->collectHeadingFallback(
                $candidates,
                $htmlWithoutLinks,
                $excludeNorm,
                $minWords,
                $maxWords,
            );
        }

        usort($candidates, static function (array $a, array $b): int {
            $sa = (int) ($a['source_score'] ?? 0);
            $sb = (int) ($b['source_score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return mb_strlen($b['phrase']) <=> mb_strlen($a['phrase']);
        });

        $out = [];
        $seen = [];
        foreach ($candidates as $row) {
            $key = $this->normKey($row['phrase']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            // Heading không bao giờ đứng đầu nếu còn candidate nội dung.
            if (($row['source'] ?? '') === 'heading' && $this->hasNonHeadingCandidate($out)) {
                // vẫn cho vào sau cùng nếu còn slot — sort đã xếp score thấp
            }
            $seen[$key] = true;
            $out[] = $row;
            if (count($out) >= $maxPhrases) {
                break;
            }
        }

        return $out;
    }

    /**
     * Normalize anchor trước khi đưa vào suggestion.
     * Chỉ trim punctuation đầu/cuối — giữ USB-C, Wi-Fi, TP.HCM, 2-in-1 ở giữa.
     */
    public function normalizeAnchorText(string $phrase): string
    {
        $phrase = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $phrase = strip_tags($phrase);
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';
        $phrase = trim($phrase);
        if ($phrase === '') {
            return '';
        }

        $edge = [
            ':', ';', ',', '.', '!', '?', '…', '-', '–', '—', '•', '*',
            '(', ')', '[', ']', '{', '}', '"', "'", '«', '»', '/', '\\', '|',
        ];
        $edgeMap = array_fill_keys($edge, true);

        $changed = true;
        while ($changed && $phrase !== '') {
            $changed = false;
            $first = mb_substr($phrase, 0, 1);
            if (isset($edgeMap[$first]) || preg_match('/\s/u', $first) === 1) {
                $phrase = trim(mb_substr($phrase, 1));
                $changed = true;
            }
            if ($phrase === '') {
                break;
            }
            $last = mb_substr($phrase, -1);
            if (isset($edgeMap[$last]) || preg_match('/\s/u', $last) === 1) {
                $phrase = trim(mb_substr($phrase, 0, mb_strlen($phrase) - 1));
                $changed = true;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);
    }

    /**
     * Xác nhận phrase còn tồn tại nguyên văn (sau normalize matcher) trong content
     * và không nằm trọn trong anchor đã link.
     *
     * @return array{phrase: string, offset: int}|null
     */
    public function findVerbatimOccurrence(string $html, string $phrase): ?array
    {
        $phrase = $this->normalizeAnchorText($phrase);
        if ($phrase === '' || $this->isStopPhrase($phrase)) {
            return null;
        }

        $htmlWithoutLinks = $this->stripAnchorTagsKeepTextAsSpace($html);
        $plain = $this->plainTextFromHtml($htmlWithoutLinks);
        if ($plain === '' || ! KeywordPhraseMatcher::contains($plain, $phrase)) {
            return null;
        }

        $haystack = KeywordPhraseMatcher::normalize($plain);
        $needle = KeywordPhraseMatcher::normalize($phrase);
        $offset = mb_strpos($haystack, $needle);
        if ($offset === false) {
            return null;
        }

        return [
            'phrase' => $phrase,
            'offset' => (int) $offset,
        ];
    }

    /**
     * Bỏ tiền tố heading dạng «Hướng dẫn…», «Các…» → cụm chính.
     */
    public function stripHeadingPrefix(string $heading): string
    {
        $heading = $this->normalizeDisplayPhrase($heading);
        if ($heading === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $heading, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return '';
        }

        // Thử khớp prefix 1–3 từ đầu (có dấu / không dấu).
        for ($len = min(3, count($tokens)); $len >= 1; $len--) {
            $head = implode(' ', array_slice($tokens, 0, $len));
            $norm = KeywordPhraseMatcher::normalize($head);
            $ascii = $this->toAsciiSpaced($norm);
            foreach (self::HEADING_PREFIXES as $prefix) {
                $pNorm = KeywordPhraseMatcher::normalize($prefix);
                $pAscii = $this->toAsciiSpaced($pNorm);
                if ($norm === $pNorm || $ascii === $pAscii) {
                    $rest = trim(implode(' ', array_slice($tokens, $len)));

                    return $rest !== '' ? $rest : '';
                }
            }
        }

        return $heading;
    }

    /**
     * @param  list<array{phrase: string, source: string, offset: int, source_score: int}>  $candidates
     * @param  array<string, true>  $excludeNorm
     */
    private function collectHighlightCandidates(
        array &$candidates,
        string $htmlWithoutLinks,
        array &$excludeNorm,
        int $minWords,
        int $maxWords,
    ): void {
        $groups = [
            [['strong', 'b'], 'strong', self::SCORE_STRONG],
            [['mark'], 'mark', self::SCORE_MARK],
            [['em', 'i'], 'em', self::SCORE_EMPHASIS],
        ];

        foreach ($groups as [$tags, $source, $score]) {
            foreach ($this->extractTaggedPhrases($htmlWithoutLinks, $tags) as $text) {
                foreach ($this->phraseWindows($text, $minWords, $maxWords) as $window) {
                    // Không lấy cả câu dài highlight — chỉ cửa sổ 2–max từ.
                    if ($this->looksLikeFullSentence($window)) {
                        continue;
                    }
                    $this->pushCandidate(
                        $candidates,
                        $window,
                        $source,
                        $score,
                        $htmlWithoutLinks,
                        $excludeNorm,
                        $minWords,
                        $maxWords,
                    );
                }
            }
        }
    }

    /**
     * @param  list<array{phrase: string, source: string, offset: int, source_score: int}>  $candidates
     * @param  array<string, true>  $excludeNorm
     */
    private function collectParagraphNounPhrases(
        array &$candidates,
        string $htmlWithoutLinks,
        array &$excludeNorm,
        int $minWords,
        int $maxWords,
        int $repeatMin,
    ): void {
        $paragraphHtml = $this->stripHeadingTags($htmlWithoutLinks);
        $plain = $this->plainTextFromHtml($paragraphHtml);
        if ($plain === '') {
            return;
        }

        // Entity / brand heuristic: cụm Title Case hoặc nhiều token viết hoa đầu.
        foreach ($this->extractCapitalizedPhrases($plain, $minWords, $maxWords) as $entity) {
            $this->pushCandidate(
                $candidates,
                $entity,
                'entity',
                self::SCORE_ENTITY,
                $htmlWithoutLinks,
                $excludeNorm,
                $minWords,
                $maxWords,
            );
        }

        foreach ($this->repeatedNgrams($plain, $minWords, $maxWords, $repeatMin) as $ngram) {
            if ($this->looksLikeFullSentence($ngram)) {
                continue;
            }
            $this->pushCandidate(
                $candidates,
                $ngram,
                'noun_phrase',
                self::SCORE_NOUN_PHRASE,
                $htmlWithoutLinks,
                $excludeNorm,
                $minWords,
                $maxWords,
            );
        }
    }

    /**
     * @param  list<array{phrase: string, source: string, offset: int, source_score: int}>  $candidates
     * @param  array<string, true>  $excludeNorm
     */
    private function collectHeadingFallback(
        array &$candidates,
        string $htmlWithoutLinks,
        array &$excludeNorm,
        int $minWords,
        int $maxWords,
    ): void {
        foreach ($this->extractTaggedPhrases($htmlWithoutLinks, ['h2', 'h3', 'h4']) as $heading) {
            $core = $this->stripHeadingPrefix($heading);
            if ($core === '') {
                continue;
            }
            foreach ($this->phraseWindows($core, $minWords, min($maxWords, 4)) as $window) {
                if ($this->looksLikeFullSentence($window)) {
                    continue;
                }
                $this->pushCandidate(
                    $candidates,
                    $window,
                    'heading',
                    self::SCORE_HEADING,
                    $htmlWithoutLinks,
                    $excludeNorm,
                    $minWords,
                    $maxWords,
                );
            }
        }
    }

    /**
     * @param  list<array{phrase: string, source: string, offset: int, source_score: int}>  $out
     */
    private function hasNonHeadingCandidate(array $out): bool
    {
        foreach ($out as $row) {
            if (($row['source'] ?? '') !== 'heading') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{phrase: string, source: string, offset: int, source_score: int}>  $candidates
     * @param  array<string, true>  $excludeNorm
     */
    private function pushCandidate(
        array &$candidates,
        string $phrase,
        string $source,
        int $sourceScore,
        string $htmlWithoutLinks,
        array &$excludeNorm,
        ?int $minWords = null,
        ?int $maxWords = null,
    ): void {
        $phrase = $this->normalizeAnchorText($phrase);
        if ($phrase === '') {
            return;
        }

        $minWords ??= max(1, (int) config('seo-content-ai.link_suggestions.fallback_min_words', 2));
        $maxWords ??= max($minWords, (int) config('seo-content-ai.link_suggestions.fallback_max_words', 8));

        $words = KeywordPhraseMatcher::countWords($phrase);
        if ($words < $minWords || $words > $maxWords) {
            return;
        }

        if (! $this->isAcceptablePhrase($phrase)) {
            return;
        }

        $key = $this->normKey($phrase);
        if ($key === '' || isset($excludeNorm[$key])) {
            return;
        }

        $occurrence = $this->findVerbatimOccurrence($htmlWithoutLinks, $phrase);
        if ($occurrence === null) {
            return;
        }

        $excludeNorm[$key] = true;
        $candidates[] = [
            'phrase' => $phrase,
            'source' => $source,
            'offset' => (int) $occurrence['offset'],
            'source_score' => $sourceScore,
        ];
    }

    private function isAcceptablePhrase(string $phrase): bool
    {
        if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase($phrase)) {
            return false;
        }

        if ($this->isStopPhrase($phrase)) {
            return false;
        }

        if (preg_match('/^[\d\s.+()-]+$/u', $phrase) === 1) {
            return false;
        }

        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $phrase) === 1) {
            return false;
        }

        $tokens = preg_split('/\s+/u', KeywordPhraseMatcher::normalize($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        if (count($tokens) === 1) {
            $ascii = $this->toAscii($tokens[0]);
            if (mb_strlen($ascii) <= 3) {
                return false;
            }
        }

        return true;
    }

    private function isStopPhrase(string $phrase): bool
    {
        return LinkSuggestionStopPhraseFilter::isStopPhrase($phrase);
    }

    private function looksLikeFullSentence(string $phrase): bool
    {
        $trimmed = trim($phrase);
        if ($trimmed === '') {
            return true;
        }

        if (preg_match('/[.!?…]$/u', $trimmed) === 1) {
            return true;
        }

        // Quá dài / nhiều dấu phẩy → gần như cả câu.
        if (KeywordPhraseMatcher::countWords($trimmed) > 8) {
            return true;
        }

        return substr_count($trimmed, ',') >= 2;
    }

    /**
     * @return list<string>
     */
    private function phraseWindows(string $phrase, int $minWords, int $maxWords): array
    {
        $phrase = $this->normalizeDisplayPhrase($phrase);
        if ($phrase === '') {
            return [];
        }

        $displayTokens = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = count($displayTokens);
        if ($words >= $minWords && $words <= $maxWords) {
            return [$phrase];
        }

        if ($words < $minWords) {
            return [];
        }

        $out = [];
        for ($size = min($maxWords, $words); $size >= $minWords; $size--) {
            for ($i = 0; $i <= $words - $size; $i++) {
                $out[] = implode(' ', array_slice($displayTokens, $i, $size));
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function extractTaggedPhrases(string $html, array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $quoted = preg_quote(strtolower($tag), '/');
            if (preg_match_all('/<'.$quoted.'\b[^>]*>(.*?)<\/'.$quoted.'>/is', $html, $matches) === false) {
                continue;
            }

            foreach ($matches[1] ?? [] as $inner) {
                $text = $this->normalizeDisplayPhrase($this->plainTextFromHtml((string) $inner));
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractLinkedAnchorTexts(string $html): array
    {
        if (preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $html, $matches) === false) {
            return [];
        }

        $out = [];
        foreach ($matches[1] ?? [] as $inner) {
            $text = $this->normalizeDisplayPhrase($this->plainTextFromHtml((string) $inner));
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    private function stripAnchorTagsKeepTextAsSpace(string $html): string
    {
        return preg_replace('/<a\b[^>]*>.*?<\/a>/is', ' ', $html) ?? $html;
    }

    private function stripHeadingTags(string $html): string
    {
        $stripped = preg_replace('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $html) ?? $html;

        return $stripped;
    }

    /**
     * @return list<string>
     */
    private function extractCapitalizedPhrases(string $plain, int $minWords, int $maxWords): array
    {
        // Chuỗi ≥2 token viết hoa chữ cái đầu (Latin) — brand / product heuristic.
        if (preg_match_all('/\b(?:[A-ZĐ][\p{L}\p{N}\']+(?:\s+[A-ZĐ][\p{L}\p{N}\']+){1,5})\b/u', $plain, $matches) === false) {
            return [];
        }

        $out = [];
        foreach ($matches[0] ?? [] as $match) {
            $text = $this->normalizeDisplayPhrase((string) $match);
            $words = KeywordPhraseMatcher::countWords($text);
            if ($words >= $minWords && $words <= $maxWords) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function repeatedNgrams(string $plain, int $minWords, int $maxWords, int $repeatMin): array
    {
        $normalized = KeywordPhraseMatcher::normalize($plain);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < $minWords) {
            return [];
        }

        $counts = [];
        $countTokens = count($tokens);
        for ($size = $maxWords; $size >= $minWords; $size--) {
            for ($i = 0; $i <= $countTokens - $size; $i++) {
                $slice = array_slice($tokens, $i, $size);
                if ($this->isStopwordOnlyTokens($slice)) {
                    continue;
                }
                // Bỏ ngram mở đầu bằng stopword yếu kiểu «cac / các / huong dan».
                $firstAscii = $this->toAscii($slice[0]);
                if (in_array($firstAscii, ['cac', 'nhung', 'mot'], true)
                    || in_array($slice[0], ['các', 'những', 'một'], true)) {
                    continue;
                }
                $gram = implode(' ', $slice);
                $counts[$gram] = ($counts[$gram] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($counts as $gram => $count) {
            if ($count >= $repeatMin) {
                $out[] = $gram;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function isStopwordOnlyTokens(array $tokens): bool
    {
        $stop = [
            'va', 'và', 'cua', 'của', 'cho', 'voi', 'với', 'la', 'là', 'cac', 'các',
            'mot', 'một', 'the', 'and', 'or', 'to', 'in', 'on', 'of', 'for', 'a', 'an',
            'nhung', 'những', 'nhu', 'như', 'de', 'để', 'khi', 'nay', 'này',
        ];
        foreach ($tokens as $token) {
            $ascii = $this->toAscii($token);
            if (! in_array($token, $stop, true) && ! in_array($ascii, $stop, true)) {
                return false;
            }
        }

        return true;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function normalizeDisplayPhrase(string $phrase): string
    {
        return $this->normalizeAnchorText($phrase);
    }

    private function normKey(string $phrase): string
    {
        return KeywordPhraseMatcher::normalize($phrase);
    }

    /**
     * @param  list<string>  $phrases
     * @return array<string, true>
     */
    private function normalizeSet(array $phrases): array
    {
        $out = [];
        foreach ($phrases as $phrase) {
            $key = $this->normKey((string) $phrase);
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return $out;
    }

    private function toAscii(string $text): string
    {
        $ascii = Str::ascii($text, 'vi');
        $ascii = mb_strtolower(trim($ascii), 'UTF-8');
        $ascii = preg_replace('/[^a-z0-9]+/u', '', $ascii) ?? $ascii;

        return $ascii;
    }

    private function toAsciiSpaced(string $text): string
    {
        $ascii = Str::ascii($text, 'vi');
        $ascii = mb_strtolower(trim($ascii), 'UTF-8');
        $ascii = preg_replace('/[^a-z0-9\s]+/u', ' ', $ascii) ?? $ascii;

        return trim(preg_replace('/\s+/u', ' ', $ascii) ?? $ascii);
    }
}
