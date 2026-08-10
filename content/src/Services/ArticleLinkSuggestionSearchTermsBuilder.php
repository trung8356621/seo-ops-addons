<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Illuminate\Support\Str;

/**
 * Sinh search terms từ anchor + context (không quá rộng).
 */
final class ArticleLinkSuggestionSearchTermsBuilder
{
    /** @var list<string> */
    private const STOPWORDS = [
        'va', 'và', 'cua', 'của', 'cho', 'voi', 'với', 'la', 'là', 'cac', 'các', 'mot', 'một',
        'nhung', 'những', 'nhung', 'những', 'the', 'and', 'or', 'to', 'in', 'on', 'of', 'for',
        'a', 'an', 'tai', 'tại', 'tu', 'từ', 'nhu', 'như', 'de', 'để', 'duoc', 'được', 'khi',
        'nay', 'này', 'do', 'đó', 'hay', 'thi', 'thì', 'se', 'sẽ', 'bi', 'bị', 've', 'về',
    ];

    /**
     * @param  array{
     *     title?: string,
     *     focus_keyword?: string,
     *     secondary_keywords?: list<string>,
     *     meta_title?: string,
     *     meta_description?: string,
     *     slug?: string,
     *     category?: string,
     *     tags?: list<string>,
     *     headings?: list<string>,
     *     paragraph_context?: string
     * }  $context
     * @return list<string>
     */
    public function build(string $anchor, array $context = [], ?int $maxTerms = null): array
    {
        $maxTerms = $maxTerms ?? (int) config('seo-content-ai.link_suggestions.max_search_terms_per_anchor', 12);
        $minLength = (int) config('seo-content-ai.link_suggestions.min_term_length', 3);

        $terms = [];
        $this->pushTerm($terms, $anchor, $minLength);

        $normalized = KeywordPhraseMatcher::normalize($anchor);
        $this->pushTerm($terms, $normalized, $minLength);

        $ascii = $this->toAscii($normalized);
        $this->pushTerm($terms, $ascii, $minLength);

        foreach ($this->meaningfulNgrams($normalized, $minLength) as $ngram) {
            $this->pushTerm($terms, $ngram, $minLength);
        }

        foreach ([
            $context['title'] ?? '',
            $context['focus_keyword'] ?? '',
            $context['meta_title'] ?? '',
            $context['slug'] ?? '',
            $context['category'] ?? '',
        ] as $value) {
            $this->pushTerm($terms, (string) $value, $minLength);
        }

        foreach (($context['secondary_keywords'] ?? []) as $secondary) {
            $this->pushTerm($terms, (string) $secondary, $minLength);
        }

        foreach (($context['tags'] ?? []) as $tag) {
            $this->pushTerm($terms, (string) $tag, $minLength);
        }

        $paragraph = trim((string) ($context['paragraph_context'] ?? ''));
        if ($paragraph !== '') {
            foreach ($this->meaningfulNgrams(KeywordPhraseMatcher::normalize($paragraph), $minLength, 4) as $ngram) {
                // Context n-grams chỉ giữ cụm ≥2 từ để tránh quá rộng.
                if (str_contains($ngram, ' ')) {
                    $this->pushTerm($terms, $ngram, $minLength);
                }
            }
        }

        $metaDescription = KeywordPhraseMatcher::normalize((string) ($context['meta_description'] ?? ''));
        if ($metaDescription !== '') {
            foreach ($this->meaningfulNgrams($metaDescription, $minLength, 3) as $ngram) {
                if (str_contains($ngram, ' ')) {
                    $this->pushTerm($terms, $ngram, $minLength);
                }
            }
        }

        // Ưu tiên cụm dài hơn (relevance cao hơn khi search).
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice(array_values($terms), 0, max(1, $maxTerms));
    }

    /**
     * Lấy đoạn văn / câu quanh anchor trong plain text.
     */
    public function extractParagraphContext(string $plainText, string $anchor, ?int $maxChars = null): string
    {
        $maxChars = $maxChars ?? (int) config('seo-content-ai.link_suggestions.max_context_chars', 280);
        $plainText = trim($plainText);
        $anchor = trim($anchor);
        if ($plainText === '' || $anchor === '') {
            return '';
        }

        $haystack = KeywordPhraseMatcher::normalize($plainText);
        $needle = KeywordPhraseMatcher::normalize($anchor);
        if ($haystack === '' || $needle === '') {
            return '';
        }

        $pos = mb_strpos($haystack, $needle);
        if ($pos === false) {
            return '';
        }

        $half = (int) floor($maxChars / 2);
        $start = max(0, $pos - $half);
        $snippet = mb_substr($haystack, $start, $maxChars);

        return trim($snippet);
    }

    /**
     * @param  list<string>  $terms
     */
    private function pushTerm(array &$terms, string $term, int $minLength): void
    {
        $term = KeywordPhraseMatcher::normalize($term);
        if ($term === '' || mb_strlen($term) < $minLength) {
            return;
        }

        if ($this->isStopwordOnly($term)) {
            return;
        }

        if (! in_array($term, $terms, true)) {
            $terms[] = $term;
        }
    }

    /**
     * @return list<string>
     */
    private function meaningfulNgrams(string $normalized, int $minLength, int $maxGram = 4): array
    {
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            fn (string $token): bool => mb_strlen($token) >= $minLength && ! $this->isStopwordOnly($token),
        ));

        if ($tokens === []) {
            return [];
        }

        $out = [];
        $count = count($tokens);
        for ($size = min($maxGram, $count); $size >= 1; $size--) {
            for ($i = 0; $i <= $count - $size; $i++) {
                $gram = implode(' ', array_slice($tokens, $i, $size));
                if ($gram !== '' && ! in_array($gram, $out, true)) {
                    $out[] = $gram;
                }
            }
        }

        return $out;
    }

    private function isStopwordOnly(string $term): bool
    {
        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return true;
        }

        foreach ($tokens as $token) {
            $ascii = $this->toAscii($token);
            if (! in_array($token, self::STOPWORDS, true) && ! in_array($ascii, self::STOPWORDS, true)) {
                return false;
            }
        }

        return true;
    }

    private function toAscii(string $text): string
    {
        $ascii = Str::ascii($text, 'vi');
        $ascii = mb_strtolower(trim($ascii), 'UTF-8');
        $ascii = preg_replace('/[^a-z0-9\s]+/u', ' ', $ascii) ?? $ascii;

        return trim(preg_replace('/\s+/u', ' ', $ascii) ?? $ascii);
    }
}
