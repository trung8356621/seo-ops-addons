<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Fixed quality tags. A tag is a marker, not a trash verdict.
 */
final class KeywordQualityClassifier
{
    /** @var list<string> */
    private const CTA = [
        'mua ngay',
        'xem them',
        'click tai day',
        'lien he ngay',
        'bam vao day',
        'dat hang ngay',
        'goi ngay',
        'click here',
        'buy now',
    ];

    /** @var list<string> */
    private const GENERIC = [
        'tai day',
        'chi tiet',
        'san pham nay',
        'xem chi tiet',
        'doc them',
        'read more',
    ];

    /** @var list<string> */
    private const SENTENCE_FILLERS = [
        'su lua chon',
        'cua nguoi',
        'la mot',
        'duoc thiet ke',
        'phu hop voi',
        'danh cho nhung',
        'se giup',
        'khong chi',
        'khang dinh',
        'phong cach thoi trang',
        'ca tinh',
        'trai nghiem',
        'dang cap',
        'sang trong',
    ];

    /** @var list<string> */
    private const QUESTION_STARTS = [
        'lam sao',
        'lam the nao',
        'tai sao',
        'cach ',
        'o dau',
        'bao nhieu',
        'nhu the nao',
        'co nen',
    ];

    /** @var list<string> */
    private const CTA_SHORT = [
        'lien he',
        'bao gia',
        'dat hang',
        'nhan tu van',
        'nhan bao gia',
    ];

    /** @var list<string> */
    private const CTA_CONTEXT = [
        'bat dau',
        'chien dich',
        'ngay',
        'lien he',
        'bao gia',
        'dat hang',
        'tu van',
    ];

    public function __construct(
        private readonly KeywordNormalizer $normalizer = new KeywordNormalizer,
    ) {}

    /**
     * @param  array<int, string>  $normalizedById
     * @return list<int> keyword ids that are non-canonical duplicates
     */
    public function duplicateKeywordIds(array $normalizedById): array
    {
        $groups = [];
        foreach ($normalizedById as $id => $normalized) {
            $normalized = $this->comparisonKey(trim($normalized));
            if ($normalized === '' || (int) $id <= 0) {
                continue;
            }
            $groups[$normalized][] = (int) $id;
        }

        $duplicates = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            array_shift($ids);
            foreach ($ids as $id) {
                $duplicates[] = $id;
            }
        }

        return $duplicates;
    }

    /**
     * @return list<string>
     */
    public function classify(
        string $raw,
        bool $isDuplicateVariant = false,
        bool $isTruncatedCopy = false,
        bool $isContainedCopy = false,
        bool $hasTransactionalDestination = false,
    ): array {
        $normalized = $this->normalizer->normalize($raw)['normalized_text'];
        $folded = $this->normalizer->fold($normalized);
        $codes = [];

        if ($this->isCtaAnchor($folded, $hasTransactionalDestination)) {
            $codes[] = KeywordQualityCode::CTA_ANCHOR;
        } elseif ($this->matchesShortPhrase($folded, self::GENERIC)) {
            $codes[] = KeywordQualityCode::GENERIC_ANCHOR;
        }

        if ($this->isEncodingError($raw)) {
            $codes[] = KeywordQualityCode::ENCODING_ERROR;
        }

        if ($this->isMalformed($raw) || $isTruncatedCopy) {
            $codes[] = KeywordQualityCode::MALFORMED_TEXT;
        }

        if ($this->isSentenceLike($raw, $folded) || $isContainedCopy) {
            $codes[] = KeywordQualityCode::SENTENCE_LIKE;
        }

        if ($this->isTrailingSequenceNumber($folded)) {
            $codes[] = KeywordQualityCode::TRAILING_SEQUENCE_NUMBER;
        }

        if ($isDuplicateVariant) {
            $codes[] = KeywordQualityCode::DUPLICATE_VARIANT;
        }

        return $codes;
    }

    public function normalized(string $raw): string
    {
        return $this->normalizer->normalize($raw)['normalized_text'];
    }


    /**
     * Copy missing the first one or two characters of another keyword on the same site.
     *
     * @param  array<int, string>  $normalizedById
     * @return list<int>
     */
    public function truncatedCopyIds(array $normalizedById): array
    {
        $byText = [];
        foreach ($normalizedById as $id => $normalized) {
            $text = $this->comparisonKey(trim($normalized));
            if ($text === '' || (int) $id <= 0) {
                continue;
            }
            $byText[$text][] = (int) $id;
        }

        $hits = [];
        foreach (array_keys($byText) as $text) {
            if (mb_strlen($text) < 18) {
                continue;
            }
            foreach ([1, 2] as $drop) {
                $short = mb_substr($text, $drop);
                if (! isset($byText[$short])) {
                    continue;
                }
                $tokens = preg_split('/\s+/u', $short, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($tokens) < 4) {
                    continue;
                }
                foreach ($byText[$short] as $shortId) {
                    $hits[$shortId] = true;
                }
            }
        }

        return array_keys($hits);
    }


    /**
     * Longer keyword that pastes another keyword and adds a clause or rare token.
     *
     * @param  array<int, string>  $rawById
     * @return list<int>
     */
    public function containedCopyIds(array $rawById): array
    {
        $rows = [];
        foreach ($rawById as $id => $raw) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $folded = $this->normalizer->fold($this->normalized((string) $raw));
            $text = $this->comparisonKey($folded);
            $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === []) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'raw' => (string) $raw,
                'folded' => $folded,
                'text' => $text,
                'tokens' => $tokens,
            ];
        }

        $shorts = [];
        foreach ($rows as $row) {
            if (count($row['tokens']) < 3 || mb_strlen($row['text']) < 12) {
                continue;
            }
            $shorts[$row['text']] = true;
        }

        $hits = [];
        foreach ($rows as $row) {
            if (count($row['tokens']) < 5 || $this->isSearchQuestion($row['raw'], $row['folded'])) {
                continue;
            }
            foreach (array_keys($shorts) as $short) {
                if ($short === $row['text'] || mb_strlen($short) >= mb_strlen($row['text'])) {
                    continue;
                }
                if (! str_contains(' '.$row['text'].' ', ' '.$short.' ')) {
                    continue;
                }
                $rest = trim(str_replace($short, ' ', $row['text']));
                $extra = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($extra === []) {
                    continue;
                }
                if ($this->hasClauseParticle($rest) || count($extra) >= 6) {
                    $hits[$row['id']] = true;
                    break;
                }
            }
        }

        return array_keys($hits);
    }

    private function comparisonKey(string $normalized): string
    {
        $key = preg_replace('/[.\'’"“”\-–—\/\\\\]+/u', ' ', $normalized) ?? $normalized;
        $key = preg_replace('/\s+/u', ' ', trim($key)) ?? trim($key);

        return trim($key);
    }

    /**
     * @param  list<string>  $phrases
     */
    private function matchesShortPhrase(string $folded, array $phrases): bool
    {
        if ($folded === '') {
            return false;
        }

        $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokenCount = count($tokens);
        foreach ($phrases as $phrase) {
            if ($folded === $phrase) {
                return true;
            }
            if ($tokenCount === 0) {
                continue;
            }
            if (preg_match('/(?:^|\s)'.preg_quote($phrase, '/').'(?:\s|$)/u', $folded) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isEncodingError(string $raw): bool
    {
        return preg_match('/&(?:amp|lt|gt|quot|nbsp|#\d+|#x[0-9a-f]+);/iu', $raw) === 1
            || str_contains($raw, "\u{FFFD}");
    }

    private function isMalformed(string $raw): bool
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/[\x{200B}\x{FEFF}\x{00AD}]/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/[,:;]$/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^[,:;]/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/([,;:])\1/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/[|_\[\]{}\\\\]|--|__/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/[.!…]["\']?\s*$/u', $trimmed) === 1) {
            $tokens = preg_split('/\s+/u', trim($trimmed, " \t.!…\"'"), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens !== [] && count($tokens) <= 4) {
                return true;
            }
        }

        $quotes = preg_match_all('/["“”]/u', $trimmed);

        return is_int($quotes) && $quotes > 0 && $quotes % 2 === 1;
    }

    private function isSentenceLike(string $raw, string $folded): bool
    {
        if ($this->isSearchQuestion($raw, $folded)) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($tokens);
        if ($count < 5) {
            return false;
        }

        $hasClause = preg_match('/\p{L}{3,},\s+\p{L}/u', $raw) === 1;
        $endsSentence = preg_match('/[.!…]["\']?\s*$/u', trim($raw)) === 1;
        $hasTitleClause = preg_match('/\p{L}\s+[-–—]\s+\p{L}/u', $raw) === 1;
        $hasColon = preg_match('/\p{L}{3,}:\s+\p{L}/u', $raw) === 1;
        if ($hasClause || $endsSentence || $hasTitleClause || $hasColon) {
            return true;
        }

        $hasStrongFiller = false;
        foreach (['khong chi', 'su lua chon', 'trai nghiem', 'khang dinh', 'duoc thiet ke', 'se giup', 'danh cho'] as $filler) {
            if (str_contains($folded, $filler)) {
                $hasStrongFiller = true;
                break;
            }
        }
        if ($hasStrongFiller && $count >= 6) {
            return true;
        }

        $hasFiller = false;
        foreach (self::SENTENCE_FILLERS as $filler) {
            if (str_contains($folded, $filler)) {
                $hasFiller = true;
                break;
            }
        }
        if ($hasFiller && $count >= 7) {
            return true;
        }

        return $this->hasClauseParticle($folded) && $count >= 9;
    }

    private function isTrailingSequenceNumber(string $folded): bool
    {
        if (preg_match('/(?:^|\s)\d{1,2}$/u', $folded) !== 1) {
            return false;
        }

        if ($this->isRecognizedModel($folded)) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $before = $tokens[count($tokens) - 2] ?? '';

        return ! in_array($before, [
            'inch', 'inches', 'cm', 'mm', 'kg', 'ml', 'gb', 'tb', 'oz', 'size', 'co', 'lit',
            'liter', 'litre', 'mah', 'dpi', 'px', 'km', 'top', 'so', 'hang', 'no', 'number', 'stt', 'l', 'm',
            'tuoi', 'cap', 'lop', 'quan',
        ], true);
    }

    private function isRecognizedModel(string $folded): bool
    {
        return preg_match('/(?:air\s+max|air\s+force|air\s+jordan|ultra\s*boost|yeezy|stan\s+smith|superstar|air\s+zoom)\s+\d{1,2}$/u', $folded) === 1;
    }

    private function isSearchQuestion(string $raw, string $folded): bool
    {
        if (str_ends_with(trim($raw), '?')) {
            return true;
        }

        foreach (self::QUESTION_STARTS as $start) {
            if (str_starts_with($folded, $start)) {
                return true;
            }
        }

        return str_contains($folded, ' loai nao ')
            || str_contains($folded, ' nhu the nao')
            || str_contains($folded, ' la gi')
            || str_contains($folded, ' nao ')
            || str_starts_with($folded, 'huong dan')
            || str_starts_with($folded, 'nen ');
    }


    private function hasClauseParticle(string $folded): bool
    {
        foreach ([' cho ', ' cua ', ' voi ', ' trong ', ' moi ', ' nhung ', ' khi ', ' ve ', ' cung ', ' de ', ' danh '] as $particle) {
            if (str_contains(' '.$folded.' ', $particle)) {
                return true;
            }
        }

        return false;
    }

    private function isCtaAnchor(string $folded, bool $hasTransactionalDestination): bool
    {
        if ($this->matchesShortPhrase($folded, self::CTA)) {
            return true;
        }

        $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) > 0 && count($tokens) <= 6 && $this->matchesShortPhrase($folded, self::CTA_SHORT)) {
            return true;
        }

        if (! $hasTransactionalDestination) {
            return false;
        }

        foreach (self::CTA_CONTEXT as $cue) {
            if (preg_match('/(?:^|\s)'.preg_quote($cue, '/').'(?:\s|$)/u', $folded) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $links
     * @return list<int>
     */
    public function transactionalDestinationKeywordIds(iterable $links): array
    {
        $hits = [];
        foreach ($links as $link) {
            $keywordId = (int) (is_array($link) ? ($link['keyword_id'] ?? 0) : ($link->keyword_id ?? 0));
            if ($keywordId <= 0 || ! $this->linkIsTransactional($link)) {
                continue;
            }
            $hits[$keywordId] = true;
        }

        return array_keys($hits);
    }

    /**
     * @param  object|array<string, mixed>  $link
     */
    private function linkIsTransactional(object|array $link): bool
    {
        $url = trim((string) (is_array($link) ? ($link['target_external_url'] ?? '') : ($link->target_external_url ?? '')));
        $type = (string) (is_array($link) ? ($link['link_type'] ?? '') : ($link->link_type ?? ''));
        $kind = (string) (is_array($link) ? ($link['destination_kind'] ?? '') : ($link->destination_kind ?? ''));
        if (in_array($type, ['contact', 'social'], true) || in_array($kind, ['contact', 'social'], true)) {
            return true;
        }
        if ($url === '') {
            return false;
        }
        if (preg_match('/^(?:tel|mailto|sms|whatsapp|viber|callto):/i', $url) === 1) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return false;
        }
        $path = strtolower(str_replace('_', '-', trim($path, '/')));
        if ($path === '') {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['lien-he', 'contact', 'contact-us', 'bao-gia', 'quotation', 'quote', 'dat-hang', 'checkout', 'gio-hang', 'tu-van', 'nhan-bao-gia'], true)) {
                return true;
            }
            if (str_starts_with($segment, 'lien-he') || str_starts_with($segment, 'bao-gia') || str_starts_with($segment, 'contact-')) {
                return true;
            }
        }

        return false;
    }
}

