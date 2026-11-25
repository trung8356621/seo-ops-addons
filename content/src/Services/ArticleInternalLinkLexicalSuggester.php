<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\DomainLinkListEditorService;
use Omnichannel\Addons\Seo\Services\EffectiveDomainLinkResolver;
use Omnichannel\Addons\Seo\Services\Linking\InternalLinkV2Guard;
use Omnichannel\Addons\Seo\Support\EditorAnchorOccurrenceMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;

/**
 * Link Assistant — first-pass lexical/deterministic suggestions.
 *
 * Destination-driven: catalog anchors (article title/focus + Domain Link List)
 * are matched into the current editor body. Content-phrase search is a bounded
 * fallback only. Never calls Semantic Service.
 */
final class ArticleInternalLinkLexicalSuggester
{
    private const MAX_PHRASES = 48;

    private const MAX_SUGGESTIONS = 20;

    private const SEARCH_PER_PHRASE = 5;

    /** Bound article catalog scan — newest first, not unbounded site load. */
    private const MAX_ARTICLE_DESTINATIONS = 250;

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
        'thời trang', 'hiện đại', 'phụ kiện', 'phù hợp', 'chi tiết', 'giới thiệu',
        'khám phá', 'cách sử dụng', 'kích thước', 'các thương hiệu', 'năng động',
        'công nghệ', 'tiện ích', 'vượt trội', 'lựa chọn', 'phong cách',
    ];

    public function __construct(
        private readonly ArticleInternalLinkSearchService $search,
        private readonly WordPressInternalLinkTargetPolicy $linkTargetPolicy,
        private readonly InternalLinkV2Guard $guard = new InternalLinkV2Guard(),
    ) {}

    /**
     * @param  list<array<string, mixed>>  $existingInternal
     * @return array{
     *     status: string,
     *     suggestions: list<array<string, mixed>>,
     *     metrics: array<string, mixed>,
     *     diagnostics: array<string, mixed>,
     *     reason?: string|null,
     *     discovery?: array<string, mixed>
     * }
     */
    public function suggest(SeoArticle $article, string $content, array $existingInternal = []): array
    {
        $siteId = (int) ($article->site_id ?? 0);
        $sourceId = (int) $article->id;
        $article->loadMissing('site');
        $siteHost = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) ($article->site?->domain ?? ''));
        $contentHtml = trim($content);
        $plain = EditorAnchorOccurrenceMatcher::plainTextExcludingAnchors($contentHtml);
        if ($siteId <= 0) {
            return $this->empty('site_unresolved');
        }
        if ($plain === '') {
            return $this->empty('content_unavailable');
        }

        $existingUrls = $this->occupiedUrls($existingInternal);
        $diagnostics = [
            'source_candidates' => 0,
            'valid_destinations' => 0,
            'anchors_matched_in_content' => 0,
            'duplicates_removed' => 0,
            'existing_links_excluded' => 0,
            'wrong_site_cross_domain' => 0,
            'final_suggestions' => 0,
            'rejected' => [],
        ];

        $candidates = [];
        $articleCandidates = $this->articleCatalogCandidates($article, $siteId, $sourceId, $diagnostics);
        $domainCandidates = $this->domainLinkCandidates($article, $diagnostics);
        foreach (array_merge($articleCandidates, $domainCandidates) as $row) {
            $candidates[] = $row;
        }
        $diagnostics['source_candidates'] = count($candidates);

        $matched = EditorAnchorOccurrenceMatcher::filterActionable($candidates, $contentHtml);
        $diagnostics['anchors_matched_in_content'] = count($matched);

        // Bounded content-phrase fallback for title/slug/focus retrieval misses.
        $phrases = $this->extractPhrases($plain);
        $phraseHits = $this->phraseSearchFallback($article, $siteId, $sourceId, $phrases, $matched, $diagnostics);
        foreach ($phraseHits as $hit) {
            $matched[] = $hit;
        }

        $suggestions = [];
        $usedUrls = $existingUrls;
        $usedAnchors = [];
        $candidateRows = [];

        foreach ($matched as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            $anchor = trim((string) ($row['text'] ?? $row['matched_phrase'] ?? ''));
            $urlKey = SeoSuggestionUrlNormalizer::normalize($url);
            $reject = $this->rejectReason(
                $url,
                $urlKey,
                $anchor,
                $sourceId,
                (int) ($row['article_id'] ?? $row['target_article_id'] ?? 0),
                $usedUrls,
                $usedAnchors,
                $existingUrls,
            );
            if ($reject !== null) {
                $diagnostics['rejected'][] = ['anchor' => $anchor, 'url' => $url, 'reason' => $reject];
                if ($reject === 'already_linked') {
                    $diagnostics['existing_links_excluded']++;
                } elseif ($reject === 'duplicate') {
                    $diagnostics['duplicates_removed']++;
                }
                continue;
            }

            $relationship = $this->relationshipForUrl($url, $siteHost);
            if ($relationship === 'cross_domain') {
                $diagnostics['wrong_site_cross_domain']++;
            }

            $score = (int) ($row['score'] ?? 0);
            if ($score <= 0) {
                $score = $this->scoreForSource((string) ($row['candidate_source'] ?? $row['source'] ?? ''));
            }

            $candidateRows[] = [
                'ref' => (string) ($row['ref'] ?? ('url:'.$urlKey)),
                'url' => $url,
                'eligible' => true,
                'same_as_source' => false,
                'already_linked_from_source' => false,
                'phrase' => $anchor,
                'title' => trim((string) ($row['title'] ?? $row['page_title'] ?? $anchor)),
                'article_id' => (int) ($row['article_id'] ?? $row['target_article_id'] ?? 0),
                'score' => $score,
                'match_reason' => (string) ($row['match_reason'] ?? 'destination_anchor'),
                'candidate_source' => (string) ($row['candidate_source'] ?? $row['source'] ?? 'article'),
                'source_label' => (string) ($row['source_label'] ?? ''),
                'relationship' => $relationship,
                'keyword_id' => $row['keyword_id'] ?? null,
            ];
        }

        usort(
            $candidateRows,
            static function (array $a, array $b): int {
                $scoreCmp = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }
                // Prefer longer anchors when scores tie (exact destination > weak core).
                return mb_strlen((string) ($b['phrase'] ?? '')) <=> mb_strlen((string) ($a['phrase'] ?? ''));
            },
        );

        $filtered = $this->guard->filter('article:'.$sourceId, $candidateRows);
        foreach ($filtered['rejected'] as $rej) {
            if (! is_array($rej)) {
                continue;
            }
            $diagnostics['rejected'][] = [
                'ref' => (string) ($rej['ref'] ?? ''),
                'reason' => (string) ($rej['reason'] ?? 'rejected'),
            ];
        }

        foreach ($filtered['accepted'] as $row) {
            $anchor = trim((string) ($row['phrase'] ?? ''));
            if ($anchor === '' || $this->isGenericPhrase($anchor)) {
                $diagnostics['rejected'][] = ['anchor' => $anchor, 'reason' => 'anchor_not_found'];
                continue;
            }
            $anchorKey = mb_strtolower($anchor);
            if (isset($usedAnchors[$anchorKey])) {
                $diagnostics['duplicates_removed']++;
                $diagnostics['rejected'][] = ['anchor' => $anchor, 'reason' => 'duplicate'];
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            $urlKey = SeoSuggestionUrlNormalizer::normalize($url);
            if ($url === '' || $urlKey === '' || isset($usedUrls[$urlKey]) || isset($usedUrls[$url])) {
                $diagnostics['rejected'][] = ['anchor' => $anchor, 'url' => $url, 'reason' => 'duplicate'];
                $diagnostics['duplicates_removed']++;
                continue;
            }
            if (SeoSuggestionUrlNormalizer::isPlaceholder($url) || $url === '#') {
                $diagnostics['rejected'][] = ['anchor' => $anchor, 'url' => $url, 'reason' => 'invalid_destination'];
                continue;
            }

            $usedUrls[$urlKey] = true;
            $usedUrls[$url] = true;
            $usedAnchors[$anchorKey] = true;
            $relationship = (string) ($row['relationship'] ?? 'internal');
            $candidateSource = (string) ($row['candidate_source'] ?? 'article');
            $suggestions[] = [
                'text' => $anchor,
                'keyword_id' => $row['keyword_id'] ?? null,
                'href' => $url,
                'target_url' => $url,
                'target_article_id' => (int) ($row['article_id'] ?? 0) ?: null,
                'destination_resolved' => true,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => LinkSuggestionScoreScale::clamp((int) ($row['score'] ?? 0)),
                'match_reason' => (string) ($row['match_reason'] ?? 'destination_anchor'),
                'source' => 'lexical_search',
                'candidate_source' => $candidateSource,
                'source_label' => $this->sourceLabel($candidateSource, (string) ($row['source_label'] ?? '')),
                'relationship' => $relationship,
                'suggestion_engine' => 'lexical_search',
                'bucket' => $relationship === 'cross_domain' ? 'cross_domain' : 'internal',
                'components' => [],
            ];

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        $diagnostics['final_suggestions'] = count($suggestions);
        $diagnostics['valid_destinations'] = count($usedUrls) - count($existingUrls);

        if ($suggestions === []) {
            return [
                'status' => 'empty',
                'suggestions' => [],
                'metrics' => [
                    'lexical_phrases' => count($phrases),
                    'lexical_searches' => (int) ($diagnostics['phrase_searches'] ?? 0),
                    'lexical_hits' => (int) ($diagnostics['phrase_hits'] ?? 0),
                    'suggested' => 0,
                ],
                'diagnostics' => $diagnostics,
                'reason' => 'no_match',
                'discovery' => [
                    'exhausted' => false,
                    'phase' => 'lexical',
                    'excluded_urls' => array_keys($existingUrls),
                ],
            ];
        }

        return [
            'status' => 'ok',
            'suggestions' => $suggestions,
            'metrics' => [
                'lexical_phrases' => count($phrases),
                'lexical_searches' => (int) ($diagnostics['phrase_searches'] ?? 0),
                'lexical_hits' => (int) ($diagnostics['phrase_hits'] ?? 0),
                'suggested' => count($suggestions),
            ],
            'diagnostics' => $diagnostics,
            'reason' => null,
            'discovery' => [
                'exhausted' => false,
                'phase' => 'lexical',
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

        $candidates = [];
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
                // Prefer later/document-wide distinctive phrases over head-only fill of MAX_PHRASES.
                $positionScore = (int) floor(($i / max(1, $count)) * 10);
                $lengthScore = $length * 3;
                $candidates[] = [
                    'phrase' => $phrase,
                    'score' => $lengthScore + $positionScore + min(12, mb_strlen($phrase)),
                ];
            }
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => ((int) $b['score']) <=> ((int) $a['score']),
        );

        $out = [];
        foreach ($candidates as $row) {
            $out[] = (string) $row['phrase'];
            if (count($out) >= self::MAX_PHRASES) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return list<array<string, mixed>>
     */
    private function articleCatalogCandidates(SeoArticle $article, int $siteId, int $sourceId, array &$diagnostics): array
    {
        $language = (string) ($article->language ?? '') ?: 'vi';
        $rows = SeoArticle::query()
            ->with(['articleMetas', 'wordpressLink', 'site'])
            ->where('site_id', $siteId)
            ->where('id', '!=', $sourceId)
            ->where('language', $language)
            ->notContentArchived()
            ->hasWpPostId()
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ARTICLE_DESTINATIONS)
            ->get(['id', 'title', 'slug', 'site_id', 'language', 'updated_at']);

        $out = [];
        foreach ($rows as $target) {
            if (! $target instanceof SeoArticle) {
                continue;
            }
            $url = trim((string) ($this->linkTargetPolicy->resolveAuthoritativePermalink($target) ?? ''));
            if ($url === '') {
                $diagnostics['rejected'][] = [
                    'article_id' => (int) $target->id,
                    'reason' => 'no_authoritative_permalink',
                ];
                continue;
            }
            if (! SeoSuggestionUrlNormalizer::isParsableTarget($url)) {
                $diagnostics['rejected'][] = [
                    'article_id' => (int) $target->id,
                    'url' => $url,
                    'reason' => 'invalid_destination',
                ];
                continue;
            }

            $title = trim((string) $target->title);
            $focus = $this->resolveFocusKeyword($target);
            $slugPhrase = trim(str_replace(['-', '_'], ' ', (string) ($target->slug ?? '')));
            $anchors = [];
            if ($title !== '') {
                $anchors[] = ['text' => $title, 'candidate_source' => 'article', 'match_reason' => 'title_exact', 'score' => LinkSuggestionScoreScale::TITLE_EXACT];
            }
            if ($focus !== '') {
                $anchors[] = ['text' => $focus, 'candidate_source' => 'focus_keyword', 'match_reason' => 'focus_keyword', 'score' => LinkSuggestionScoreScale::FOCUS_KEYWORD];
            }
            if ($slugPhrase !== '' && mb_strlen($slugPhrase) >= 6) {
                $anchors[] = ['text' => $slugPhrase, 'candidate_source' => 'article', 'match_reason' => 'slug_match', 'score' => LinkSuggestionScoreScale::SLUG_MATCH];
            }

            foreach ($anchors as $anchor) {
                // Expand cores only for focus keywords — title/slug stay exact to avoid weak noise.
                $texts = $anchor['candidate_source'] === 'focus_keyword'
                    ? $this->expandAnchorTexts($anchor['text'])
                    : [$anchor['text']];
                foreach ($texts as $expanded) {
                    if ($this->isGenericPhrase($expanded)) {
                        continue;
                    }
                    $isCore = mb_strtolower($expanded) !== mb_strtolower($anchor['text']);
                    $out[] = [
                        'text' => $expanded,
                        'href' => $url,
                        'target_url' => $url,
                        'article_id' => (int) $target->id,
                        'target_article_id' => (int) $target->id,
                        'title' => $title !== '' ? $title : $expanded,
                        'ref' => 'article:'.$target->id,
                        'candidate_source' => $anchor['candidate_source'],
                        'source_label' => $anchor['candidate_source'] === 'focus_keyword' ? 'Focus Keyword' : 'Article',
                        'match_reason' => $isCore ? 'destination_core' : $anchor['match_reason'],
                        'score' => $isCore
                            ? max(78, (int) $anchor['score'] - 10)
                            : (int) $anchor['score'],
                        'can_insert' => true,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return list<array<string, mixed>>
     */
    private function domainLinkCandidates(SeoArticle $article, array &$diagnostics): array
    {
        $items = app(DomainLinkListEditorService::class)->forSite($article->site);
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            $href = trim((string) ($item['href'] ?? $item['target_url'] ?? ''));
            if ($text === '' || $href === '') {
                $diagnostics['rejected'][] = ['anchor' => $text, 'url' => $href, 'reason' => 'invalid_destination'];
                continue;
            }
            if (SeoSuggestionUrlNormalizer::isPlaceholder($href) || $href === '#') {
                $diagnostics['rejected'][] = ['anchor' => $text, 'url' => $href, 'reason' => 'invalid_destination'];
                continue;
            }
            $source = (string) ($item['source'] ?? EffectiveDomainLinkResolver::SOURCE_CUSTOM);
            $candidateSource = match ($source) {
                EffectiveDomainLinkResolver::SOURCE_PRODUCT_CAT => 'product_category',
                EffectiveDomainLinkResolver::SOURCE_MAIN_DOMAIN => 'main_domain',
                default => 'manual',
            };
            foreach ($this->expandAnchorTexts($text) as $expanded) {
                if ($this->isGenericPhrase($expanded)) {
                    continue;
                }
                $isCore = mb_strtolower($expanded) !== mb_strtolower($text);
                $out[] = [
                    'text' => $expanded,
                    'href' => $href,
                    'target_url' => $href,
                    'keyword_id' => $item['keyword_id'] ?? null,
                    'ref' => 'domain:'.SeoSuggestionUrlNormalizer::normalize($href).'|'.mb_strtolower($expanded),
                    'candidate_source' => $candidateSource,
                    'source_label' => $this->sourceLabel($candidateSource, ''),
                    'source' => $source,
                    'match_reason' => $isCore ? 'domain_link_core' : 'domain_link_list',
                    'score' => $isCore
                        ? max(68, $this->scoreForSource($candidateSource) - 6)
                        : $this->scoreForSource($candidateSource),
                    'can_insert' => true,
                ];
            }
        }

        return $out;
    }

    /**
     * Destination-driven cores: full anchor + contiguous 2–4 windows + ordered
     * contentful pairs (so "May Túi Vải Bố Canvas" yields "túi canvas").
     *
     * @return list<string>
     */
    private function expandAnchorTexts(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }

        $out = [$text];
        $seen = [mb_strtolower($text) => true];
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($tokens);
        if ($count < 2) {
            return $out;
        }

        $maxLen = min(4, $count);
        for ($length = $maxLen; $length >= 2; $length--) {
            for ($i = 0; $i <= $count - $length; $i++) {
                $slice = array_slice($tokens, $i, $length);
                $phrase = trim(implode(' ', $slice));
                $this->pushExpandedAnchor($out, $seen, $phrase);
            }
        }

        // Ordered non-adjacent pairs from contentful tokens (bounded).
        if ($count <= 8) {
            $contentful = [];
            foreach ($tokens as $token) {
                $norm = mb_strtolower($token);
                if ($norm === '' || mb_strlen($norm) < 3 || in_array($norm, self::STOP_TOKENS, true)) {
                    continue;
                }
                $contentful[] = $token;
            }
            $n = count($contentful);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $phrase = trim($contentful[$i].' '.$contentful[$j]);
                    $this->pushExpandedAnchor($out, $seen, $phrase);
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $out
     * @param  array<string, true>  $seen
     */
    private function pushExpandedAnchor(array &$out, array &$seen, string $phrase): void
    {
        $phrase = trim($phrase);
        $key = mb_strtolower($phrase);
        if ($phrase === '' || isset($seen[$key]) || $this->isGenericPhrase($phrase)) {
            return;
        }
        if (! $this->hasStrongCoreTokens($phrase)) {
            return;
        }
        $seen[$key] = true;
        $out[] = $phrase;
    }

    private function hasStrongCoreTokens(string $text): bool
    {
        if (! $this->hasDistinctiveTokens($text)) {
            return false;
        }
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $strong = 0;
        foreach ($parts as $part) {
            if ($part === '' || in_array($part, self::STOP_TOKENS, true)) {
                continue;
            }
            if (mb_strlen($part) >= 6) {
                $strong++;
            }
        }

        // Require a long token ("canvas", "không", …) or 3+ contentful tokens.
        if ($strong >= 1) {
            return true;
        }

        $contentful = 0;
        foreach ($parts as $part) {
            if ($part === '' || mb_strlen($part) < 3 || in_array($part, self::STOP_TOKENS, true)) {
                continue;
            }
            $contentful++;
        }

        return $contentful >= 3;
    }

    /**
     * @param  list<string>  $phrases
     * @param  list<array<string, mixed>>  $alreadyMatched
     * @param  array<string, mixed>  $diagnostics
     * @return list<array<string, mixed>>
     */
    private function phraseSearchFallback(
        SeoArticle $article,
        int $siteId,
        int $sourceId,
        array $phrases,
        array $alreadyMatched,
        array &$diagnostics,
    ): array {
        $seen = [];
        foreach ($alreadyMatched as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = SeoSuggestionUrlNormalizer::normalize((string) ($row['href'] ?? '')).'|'.mb_strtolower(trim((string) ($row['text'] ?? '')));
            if ($key !== '|') {
                $seen[$key] = true;
            }
        }

        $out = [];
        $searches = 0;
        $hits = 0;
        foreach ($phrases as $phrase) {
            $searches++;
            $results = $this->search->search($siteId, $sourceId, $phrase, self::SEARCH_PER_PHRASE);
            if ($results === []) {
                continue;
            }
            $hits++;
            foreach ($results as $hit) {
                $url = trim((string) ($hit['url'] ?? ''));
                $targetId = (int) ($hit['id'] ?? 0);
                if ($url === '' || $targetId <= 0) {
                    continue;
                }
                $key = SeoSuggestionUrlNormalizer::normalize($url).'|'.mb_strtolower($phrase);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'text' => $phrase,
                    'href' => $url,
                    'target_url' => $url,
                    'article_id' => $targetId,
                    'target_article_id' => $targetId,
                    'title' => trim((string) ($hit['title'] ?? '')),
                    'ref' => 'article:'.$targetId,
                    'candidate_source' => ((string) ($hit['match_reason'] ?? '') === 'focus_keyword'
                        || (string) ($hit['match_reason'] ?? '') === 'focus_overlap')
                        ? 'focus_keyword'
                        : 'article',
                    'source_label' => 'Article',
                    'match_reason' => (string) ($hit['match_reason'] ?? 'lexical'),
                    'score' => (int) ($hit['score'] ?? 0),
                    'can_insert' => true,
                ];
            }
        }
        $diagnostics['phrase_searches'] = $searches;
        $diagnostics['phrase_hits'] = $hits;

        return $out;
    }

    /**
     * @param  array<string, true>  $usedUrls
     * @param  array<string, true>  $usedAnchors
     * @param  array<string, true>  $existingUrls
     */
    private function rejectReason(
        string $url,
        string $urlKey,
        string $anchor,
        int $sourceId,
        int $targetId,
        array $usedUrls,
        array $usedAnchors,
        array $existingUrls,
    ): ?string {
        if ($anchor === '') {
            return 'anchor_not_found';
        }
        if ($url === '' || $urlKey === '' || SeoSuggestionUrlNormalizer::isPlaceholder($url) || $url === '#') {
            return 'invalid_destination';
        }
        if ($targetId > 0 && $targetId === $sourceId) {
            return 'self_link';
        }
        if (isset($existingUrls[$url]) || isset($existingUrls[$urlKey])) {
            return 'already_linked';
        }
        if (isset($usedUrls[$url]) || isset($usedUrls[$urlKey])) {
            return 'duplicate';
        }
        if (isset($usedAnchors[mb_strtolower($anchor)])) {
            return 'duplicate';
        }

        return null;
    }

    private function relationshipForUrl(string $url, string $siteHost): string
    {
        if ($siteHost === '') {
            return 'internal';
        }
        $host = SeoSuggestionUrlNormalizer::host($url);
        if ($host === '') {
            // Relative / same-site path
            return 'internal';
        }
        if ($host === $siteHost || str_ends_with($host, '.'.$siteHost) || str_ends_with($siteHost, '.'.$host)) {
            return 'internal';
        }

        return 'cross_domain';
    }

    private function sourceLabel(string $candidateSource, string $explicit): string
    {
        if ($explicit !== '') {
            return $explicit;
        }

        return match ($candidateSource) {
            'focus_keyword' => 'Focus Keyword',
            'manual' => 'Manual',
            'product_category' => 'Product Category',
            'main_domain' => 'Main Domain',
            default => 'Article',
        };
    }

    private function scoreForSource(string $candidateSource): int
    {
        return match ($candidateSource) {
            'focus_keyword' => 92,
            'manual' => 88,
            'product_category' => 80,
            'main_domain' => 72,
            default => 75,
        };
    }

    private function resolveFocusKeyword(SeoArticle $article): string
    {
        $metas = $article->articleMetas ?? collect();
        foreach (['seo_focus_keyword', 'rank_math_focus_keyword'] as $key) {
            $value = trim((string) ($metas->firstWhere('meta_key', $key)?->meta_value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
            if ($href === '' || $href === '#' || str_starts_with($href, '#')) {
                continue;
            }
            $urls[$href] = true;
            $norm = SeoSuggestionUrlNormalizer::normalize($href);
            if ($norm !== '') {
                $urls[$norm] = true;
            }
        }

        return $urls;
    }

    /**
     * @return array{status: string, suggestions: list<array<string, mixed>>, metrics: array<string, mixed>, diagnostics: array<string, mixed>, reason: string, discovery: array<string, mixed>}
     */
    private function empty(string $reason): array
    {
        return [
            'status' => 'empty',
            'suggestions' => [],
            'metrics' => [],
            'diagnostics' => [
                'source_candidates' => 0,
                'valid_destinations' => 0,
                'anchors_matched_in_content' => 0,
                'duplicates_removed' => 0,
                'existing_links_excluded' => 0,
                'wrong_site_cross_domain' => 0,
                'final_suggestions' => 0,
                'rejected' => [],
            ],
            'reason' => $reason,
            'discovery' => ['exhausted' => false, 'phase' => 'lexical', 'excluded_urls' => []],
        ];
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
