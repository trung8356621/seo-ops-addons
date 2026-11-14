<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\Linking\InternalLinkV2Guard;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;

/**
 * Link Assistant — ordinary keyword/lexical suggestions from content phrases.
 * Does not use Topic / Keyword Group / Semantic Grouping. Destination match =
 * ArticleInternalLinkSearchService (same-domain title/slug search).
 */
final class ArticleInternalLinkLexicalSuggester
{
    private const MAX_PHRASES = 28;

    private const MAX_SUGGESTIONS = 15;

    private const SEARCH_PER_PHRASE = 5;

    /** @var list<string> */
    private const STOP_TOKENS = [
        'và', 'của', 'cho', 'với', 'các', 'những', 'một', 'này', 'trong', 'để', 'là', 'có',
        'được', 'khi', 'từ', 'theo', 'về', 'trên', 'dưới', 'ngay', 'hơn', 'rất', 'the', 'and', 'for',
        'với', 'như', 'hay', 'hoặc', 'rằng', 'đã', 'sẽ', 'đang', 'bạn', 'chúng', 'tôi',
    ];

    /** @var list<string> */
    private const GENERIC_PHRASES = [
        'doanh nghiệp', 'chất liệu', 'liên hệ ngay', 'sản phẩm', 'khách hàng',
        'liên hệ', 'xem thêm', 'tại đây', 'công ty', 'dịch vụ',
    ];

    public function __construct(
        private readonly ArticleInternalLinkSearchService $search,
        private readonly InternalLinkV2Guard $guard = new InternalLinkV2Guard(),
    ) {}

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @return array{
     *     status: string,
     *     suggestions: list<array<string, mixed>>,
     *     metrics: array<string, mixed>,
     *     reason?: string|null,
     *     discovery?: array<string, mixed>
     * }
     */
    public function suggest(SeoArticle $article, string $content, array $existingInternal = []): array
    {
        $siteId = (int) ($article->site_id ?? 0);
        $sourceId = (int) $article->id;
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '');
        if ($siteId <= 0) {
            return $this->empty('site_unresolved');
        }
        if ($plain === '') {
            return $this->empty('content_unavailable');
        }

        $existingUrls = $this->occupiedUrls($existingInternal);
        $phrases = $this->extractPhrases($plain);
        $metrics = [
            'lexical_phrases' => count($phrases),
            'lexical_searches' => 0,
            'lexical_hits' => 0,
        ];

        if ($phrases === []) {
            return [
                'status' => 'empty',
                'suggestions' => [],
                'metrics' => $metrics,
                'reason' => 'no_match',
                'discovery' => ['exhausted' => true, 'excluded_urls' => array_keys($existingUrls)],
            ];
        }

        $suggestions = [];
        $usedUrls = $existingUrls;
        $usedAnchors = [];
        $candidateRows = [];

        foreach ($phrases as $phrase) {
            $metrics['lexical_searches']++;
            $hits = $this->search->search($siteId, $sourceId, $phrase, self::SEARCH_PER_PHRASE);
            if ($hits === []) {
                continue;
            }
            $metrics['lexical_hits']++;

            foreach ($hits as $hit) {
                $url = trim((string) ($hit['url'] ?? ''));
                $targetId = (int) ($hit['id'] ?? 0);
                if ($url === '' || $targetId <= 0 || $targetId === $sourceId) {
                    continue;
                }
                if (isset($usedUrls[$url])) {
                    continue;
                }
                $score = (int) ($hit['score'] ?? 0);
                if ($score <= 0) {
                    continue;
                }

                $candidateRows[] = [
                    'ref' => 'article:'.$targetId,
                    'url' => $url,
                    'eligible' => true,
                    'same_as_source' => false,
                    'already_linked_from_source' => false,
                    'phrase' => $phrase,
                    'title' => trim((string) ($hit['title'] ?? '')),
                    'article_id' => $targetId,
                    'score' => $score,
                    'match_reason' => (string) ($hit['match_reason'] ?? 'lexical'),
                ];
            }
        }

        usort(
            $candidateRows,
            static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
        );

        $filtered = $this->guard->filter('article:'.$sourceId, $candidateRows);
        foreach ($filtered['accepted'] as $row) {
            $phrase = trim((string) ($row['phrase'] ?? ''));
            $anchor = $this->verbatimSpan($plain, $phrase);
            if ($anchor === null || $this->isGenericPhrase($anchor) || isset($usedAnchors[mb_strtolower($anchor)])) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || isset($usedUrls[$url])) {
                continue;
            }

            $usedUrls[$url] = true;
            $usedAnchors[mb_strtolower($anchor)] = true;
            $suggestions[] = [
                'text' => $anchor,
                'keyword_id' => null,
                'href' => $url,
                'target_url' => $url,
                'target_article_id' => (int) ($row['article_id'] ?? 0),
                'destination_resolved' => true,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => LinkSuggestionScoreScale::clamp((int) ($row['score'] ?? 0)),
                'match_reason' => (string) ($row['match_reason'] ?? 'lexical'),
                'source' => 'lexical_search',
                'candidate_source' => 'content_phrase',
                'suggestion_engine' => 'lexical_search',
                'bucket' => 'internal',
                'components' => [],
            ];

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        if ($suggestions === []) {
            return [
                'status' => 'empty',
                'suggestions' => [],
                'metrics' => $metrics,
                'reason' => 'no_match',
                'discovery' => [
                    'exhausted' => true,
                    'excluded_urls' => array_keys($existingUrls),
                ],
            ];
        }

        return [
            'status' => 'ok',
            'suggestions' => $suggestions,
            'metrics' => array_merge($metrics, ['suggested' => count($suggestions)]),
            'reason' => null,
            'discovery' => [
                'exhausted' => false,
                'excluded_urls' => array_keys($usedUrls),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function extractPhrases(string $plain): array
    {
        $tokens = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return [];
        }

        $out = [];
        $seen = [];
        $count = count($tokens);
        for ($length = 4; $length >= 2; $length--) {
            for ($i = 0; $i <= $count - $length; $i++) {
                $slice = array_slice($tokens, $i, $length);
                $phrase = trim(implode(' ', $slice));
                if ($phrase === '' || $this->isGenericPhrase($phrase)) {
                    continue;
                }
                if (! $this->hasDistinctiveTokens($phrase)) {
                    continue;
                }
                $key = mb_strtolower($phrase);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $phrase;
                if (count($out) >= self::MAX_PHRASES) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @return array<string, true>
     */
    private function occupiedUrls(array $existingInternal): array
    {
        $urls = [];
        foreach ($existingInternal as $row) {
            if (! is_array($row)) {
                continue;
            }
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            if ($href !== '' && $href !== '#' && ! str_starts_with($href, '#')) {
                $urls[$href] = true;
            }
        }

        return $urls;
    }

    /**
     * @return array{status: string, suggestions: list<array<string, mixed>>, metrics: array<string, mixed>, reason: string, discovery: array<string, mixed>}
     */
    private function empty(string $reason): array
    {
        return [
            'status' => 'empty',
            'suggestions' => [],
            'metrics' => [],
            'reason' => $reason,
            'discovery' => ['exhausted' => true, 'excluded_urls' => []],
        ];
    }

    private function verbatimSpan(string $plain, string $needle): ?string
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }
        $pos = mb_stripos($plain, $needle);
        if ($pos === false) {
            return null;
        }

        return mb_substr($plain, $pos, mb_strlen($needle));
    }

    private function isGenericPhrase(string $phrase): bool
    {
        $normalized = mb_strtolower(trim($phrase));
        if ($normalized === '' || in_array($normalized, self::GENERIC_PHRASES, true)) {
            return true;
        }

        return ! $this->hasDistinctiveTokens($normalized);
    }

    private function hasDistinctiveTokens(string $text): bool
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || mb_strlen($part) < 3 || in_array($part, self::STOP_TOKENS, true)) {
                continue;
            }
            $tokens[] = $part;
        }
        $tokens = array_values(array_unique($tokens));
        if (count($tokens) >= 2) {
            return true;
        }

        return count($tokens) === 1 && mb_strlen($tokens[0]) >= 10;
    }
}
