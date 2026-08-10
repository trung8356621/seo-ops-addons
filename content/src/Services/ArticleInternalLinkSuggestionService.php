<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionStopPhraseFilter;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use App\Support\RuntimeLogger;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;

final class ArticleInternalLinkSuggestionService
{
    /**
     * Request-scoped cache: same (article, content, links) collectCandidates() call
     * repeated by suggest()/suggestCatalog()/suggestExternal()/suggestExternalCatalog()
     * within one request only pays the query cost once (Phase 2 perf).
     *
     * @var array<string, array{internal: list<array<string, mixed>>, external: list<array<string, mixed>>}>
     */
    private array $candidatesCache = [];

    /**
     * Request-scoped cache of the site keyword catalog, keyed by site id + excluded
     * keyword ids — avoids re-running the full `Keyword::forSite()` scan per call.
     *
     * @var array<string, \Illuminate\Support\Collection<int, Keyword>>
     */
    private array $keywordsBySite = [];

    /** @var array<string, mixed> */
    private array $lastDebug = [];

    public function __construct(
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
        private readonly ArticleLinkSuggestionCandidateRetriever $candidateRetriever,
        private readonly ArticleLinkSuggestionSearchTermsBuilder $termsBuilder,
        private readonly ArticleLinkSuggestionContentKeywordFallback $contentKeywordFallback,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lastDebug(): array
    {
        return $this->lastDebug;
    }

    /**
     * Chỉ chạy content-keyword fallback (nút debug «Tạo gợi ý bổ sung»).
     *
     * @param  list<array<string, mixed>>  $existingInternal
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array<string, mixed>>,
     *     internal_catalog: list<array<string, mixed>>,
     *     external: list<array<string, mixed>>,
     *     external_catalog: list<array<string, mixed>>,
     *     debug?: array<string, mixed>
     * }
     */
    public function suggestFallbackSupplement(
        SeoArticle $article,
        string $content,
        array $existingInternal,
        array $internalLinks,
        array $externalLinks = [],
    ): array {
        $article->loadMissing('site', 'articleMetas');
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) ($article->site?->domain ?? ''));
        $validationContext = [
            'site_domain' => $siteDomain,
            'site_id' => (int) ($article->site_id ?? 0),
            'current_article_id' => (int) $article->id,
            'current_urls' => $this->currentArticleUrls($article),
            'current_slug' => trim((string) ($article->slug ?? ''), '/'),
        ];

        $linkedContext = $this->collectLinkedContext(array_merge($internalLinks, $externalLinks));
        $seenUrls = $linkedContext['hrefs'];
        $seenTargets = [];
        $validExisting = [];
        foreach ($existingInternal as $row) {
            if (! is_array($row)) {
                continue;
            }
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '' || $href === '' || LinkSuggestionStopPhraseFilter::isStopPhrase($text)) {
                continue;
            }
            $item = array_merge($row, [
                'href' => $href,
                'target_url' => $href,
                'text' => $text,
                'bucket' => 'internal',
            ]);
            if (! LinkSuggestionValidator::isValidLinkSuggestion($item, $validationContext)) {
                continue;
            }
            unset($item['bucket']);
            $validExisting[] = $item;
            $norm = SeoSuggestionUrlNormalizer::normalize($href);
            if ($norm !== '') {
                $seenUrls[] = $norm;
            }
            $tid = (int) ($item['target_article_id'] ?? 0);
            if ($tid > 0) {
                $seenTargets[$tid] = true;
            }
        }

        $plainText = $this->plainTextFromHtml($content);
        $focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? '';
        $priority = array_values(array_filter([
            (string) $focusKeyword,
            ...$this->secondaryKeywordsAppearingInContent($article, $plainText),
        ]));

        $fallbackItems = $this->contentKeywordFallback->supplement(
            $article,
            $content,
            $validExisting,
            $linkedContext['labels'],
            $seenUrls,
            $seenTargets,
            $validationContext,
            $priority,
            forceRun: true,
        );

        $merged = array_merge($validExisting, $fallbackItems);
        usort(
            $merged,
            static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
        );

        $this->lastDebug = [
            'entry' => 'suggestFallbackSupplement',
            'primary_valid_count' => count($validExisting),
            'fallback' => $this->contentKeywordFallback->lastDebug(),
            'final_internal_count' => count($merged),
        ];
        $this->logDebug('fallback_only', $this->lastDebug);

        $maxDisplay = $this->limit('max_display_internal', 10);

        return [
            'internal' => array_slice($merged, 0, $maxDisplay),
            'internal_catalog' => $merged,
            'external' => [],
            'external_catalog' => [],
            'debug' => LinkSuggestionStopPhraseFilter::debugEnabled() ? $this->lastDebug : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array<string, mixed>>,
     *     internal_catalog: list<array<string, mixed>>,
     *     external: list<array<string, mixed>>,
     *     external_catalog: list<array<string, mixed>>
     * }
     */
    public function suggestBundle(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        $candidates = $this->collectCandidates($article, $content, $internalLinks, $externalLinks);

        $internalCatalog = $candidates['internal'];
        $externalCatalog = $candidates['external'];
        $maxInternalLinks = $this->limit('max_internal_links', 10);
        $maxDisplayInternal = $this->limit('max_display_internal', 10);
        $maxDisplayExternal = $this->limit('max_display_external', 10);

        $payload = [
            'internal' => count($internalLinks) >= $maxInternalLinks
                ? []
                : array_slice($internalCatalog, 0, $maxDisplayInternal),
            'internal_catalog' => $internalCatalog,
            'external' => array_slice($externalCatalog, 0, $maxDisplayExternal),
            'external_catalog' => $externalCatalog,
        ];

        if (LinkSuggestionStopPhraseFilter::debugEnabled()) {
            $payload['debug'] = $this->lastDebug;
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggest(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        if (count($internalLinks) >= $this->limit('max_internal_links', 10)) {
            return [];
        }

        return array_slice(
            $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['internal'],
            0,
            $this->limit('max_display_internal', 10),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggestCatalog(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['internal'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggestExternal(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return array_slice(
            $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['external'],
            0,
            $this->limit('max_display_external', 10),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return list<array<string, mixed>>
     */
    public function suggestExternalCatalog(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        return $this->collectCandidates($article, $content, $internalLinks, $externalLinks)['external'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{
     *     internal: list<array<string, mixed>>,
     *     external: list<array<string, mixed>>
     * }
     */
    private function collectCandidates(SeoArticle $article, string $content, array $internalLinks, array $externalLinks = []): array
    {
        $empty = ['internal' => [], 'external' => []];
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return $empty;
        }

        $plainText = $this->plainTextFromHtml($content);
        if ($plainText === '') {
            $this->lastDebug = [
                'entry' => 'collectCandidates',
                'article_id' => (int) $article->id,
                'skip_reason' => 'empty_plain_text_after_strip',
                'content_length' => mb_strlen($content),
            ];
            $this->logDebug('empty_content', $this->lastDebug);

            return $empty;
        }

        $cacheKey = $this->candidatesCacheKey((int) $article->id, $content, $internalLinks, $externalLinks);
        if (isset($this->candidatesCache[$cacheKey])) {
            return $this->candidatesCache[$cacheKey];
        }

        $article->loadMissing('site', 'articleMetas');
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) ($article->site?->domain ?? ''));
        $currentUrls = $this->currentArticleUrls($article);
        $validationContext = [
            'site_domain' => $siteDomain,
            'site_id' => $siteId,
            'current_article_id' => (int) $article->id,
            'current_urls' => $currentUrls,
            'current_slug' => trim((string) ($article->slug ?? ''), '/'),
        ];

        $linkedContext = $this->collectLinkedContext(array_merge($internalLinks, $externalLinks));
        $linkedLabels = $linkedContext['labels'];
        $linkedHrefs = $linkedContext['hrefs'];
        $ownArticlePhrases = $this->ownArticlePhraseBlocklist($article);

        $excludeKeywordIds = $this->mainKeywordIdsForArticle((int) $article->id);
        $keywords = $this->keywordsForSite($siteId, $excludeKeywordIds);

        $focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? '';
        $articleContextBase = [
            'title' => (string) ($article->title ?? ''),
            'focus_keyword' => (string) $focusKeyword,
            'slug' => (string) ($article->slug ?? ''),
            'meta_title' => app(SeoAnalyzerService::class)->resolveSeoTitleForArticle($article),
            'meta_description' => app(SeoAnalyzerService::class)->resolveMetaDescriptionForArticle($article),
        ];

        $primaryRejectedStop = 0;
        /** @var list<array{keyword: Keyword, phrase: string}> $matched */
        $matched = [];
        foreach ($keywords as $keyword) {
            $phrase = trim((string) $keyword->phrase);
            if ($phrase === '' || $this->isAlreadyLinked($phrase, $linkedLabels)) {
                continue;
            }

            if (LinkSuggestionStopPhraseFilter::isStopPhrase($phrase)) {
                $primaryRejectedStop++;
                continue;
            }

            if ($this->isOwnArticlePhrase($phrase, $ownArticlePhrases)) {
                continue;
            }

            if (in_array((int) $keyword->id, $excludeKeywordIds, true)) {
                continue;
            }

            if (! $this->textContainsPhrase($plainText, $phrase)) {
                continue;
            }

            $matched[] = ['keyword' => $keyword, 'phrase' => $phrase];
        }

        $needArticleSearch = [];
        $resolvedByKeywordId = [];

        foreach ($matched as $row) {
            $keyword = $row['keyword'];
            $phrase = $row['phrase'];

            $resolvedInternal = $this->linkTargetResolver->resolveForKeyword(
                $keyword,
                $article,
                sameLanguageOnly: true,
                internalOnly: true,
            );
            $resolvedAny = $resolvedInternal
                ?? $this->linkTargetResolver->resolveForKeyword(
                    $keyword,
                    $article,
                    sameLanguageOnly: true,
                    internalOnly: false,
                );

            $href = is_string($resolvedAny) ? trim($resolvedAny) : '';
            if ($href !== '') {
                $resolvedByKeywordId[(int) $keyword->id] = [
                    'href' => $href,
                    'keyword_id' => (int) $keyword->id,
                    'score' => 90,
                    'match_reason' => 'link_map',
                    'target_article_id' => $this->resolveTargetArticleId($siteId, $href, $article),
                ];
            } else {
                $needArticleSearch[] = [
                    'keyword_id' => (int) $keyword->id,
                    'phrase' => $phrase,
                    'context' => array_merge($articleContextBase, [
                        'paragraph_context' => $this->termsBuilder->extractParagraphContext($plainText, $phrase),
                    ]),
                ];
            }
        }

        $articleTargets = $this->candidateRetriever->resolveBestForAnchors(
            $article,
            $needArticleSearch,
            $linkedHrefs,
        );

        $internalSuggestions = [];
        $externalSuggestions = [];
        $seenNormalizedUrls = $linkedHrefs;
        $seenTargetArticleIds = [];

        foreach ($matched as $row) {
            $keyword = $row['keyword'];
            $phrase = $row['phrase'];
            $keywordId = (int) $keyword->id;

            $resolved = $resolvedByKeywordId[$keywordId] ?? $articleTargets[$keywordId] ?? null;
            if (! is_array($resolved)) {
                // Anchor candidate only — không tạo suggestion thiếu URL.
                continue;
            }

            $href = trim((string) ($resolved['href'] ?? ''));
            if ($href === '' || $this->isSpecialSchemeOrContactHref($href)) {
                continue;
            }

            if ($this->isHrefAlreadyLinked($href, $seenNormalizedUrls)) {
                continue;
            }

            $bucket = $this->suggestionBucketForHref($href, $siteDomain, $siteId);
            if ($bucket === null) {
                continue;
            }

            $targetArticleId = (int) ($resolved['target_article_id'] ?? 0);
            if ($targetArticleId <= 0 && $bucket === 'internal') {
                $targetArticleId = $this->resolveTargetArticleId($siteId, $href, $article);
            }

            if ($bucket === 'internal' && $targetArticleId > 0 && isset($seenTargetArticleIds[$targetArticleId])) {
                continue;
            }

            $item = [
                'text' => $phrase,
                'keyword_id' => $keywordId,
                'href' => $href,
                'target_url' => $href,
                'target_article_id' => $targetArticleId > 0 ? $targetArticleId : null,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => (int) ($resolved['score'] ?? 0),
                'match_reason' => (string) ($resolved['match_reason'] ?? ''),
                'source' => 'primary',
                'bucket' => $bucket,
            ];

            if (! LinkSuggestionValidator::isValidLinkSuggestion($item, $validationContext)) {
                continue;
            }

            unset($item['bucket']);

            if ($bucket === 'external') {
                $externalSuggestions[] = $item;
            } else {
                $internalSuggestions[] = $item;
            }

            $linkedLabels[] = mb_strtolower($phrase);
            $normalizedHref = SeoSuggestionUrlNormalizer::normalize($href);
            if ($normalizedHref !== '') {
                $seenNormalizedUrls[] = $normalizedHref;
            }
            if ($targetArticleId > 0) {
                $seenTargetArticleIds[$targetArticleId] = true;
            }
        }

        usort(
            $internalSuggestions,
            static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
        );
        usort(
            $externalSuggestions,
            static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
        );

        $primaryValidInternal = count($internalSuggestions);
        $fallbackTriggered = false;
        $fallbackItems = [];

        if ($this->contentKeywordFallback->shouldRun($primaryValidInternal)) {
            $fallbackTriggered = true;
            $priorityPhrases = array_values(array_filter([
                (string) $focusKeyword,
                ...$this->secondaryKeywordsAppearingInContent($article, $plainText),
            ]));

            $fallbackItems = $this->contentKeywordFallback->supplement(
                $article,
                $content,
                $internalSuggestions,
                $linkedLabels,
                $seenNormalizedUrls,
                $seenTargetArticleIds,
                $validationContext,
                $priorityPhrases,
            );

            foreach ($fallbackItems as $fallbackItem) {
                $internalSuggestions[] = $fallbackItem;
                $href = trim((string) ($fallbackItem['href'] ?? ''));
                $normalizedHref = SeoSuggestionUrlNormalizer::normalize($href);
                if ($normalizedHref !== '') {
                    $seenNormalizedUrls[] = $normalizedHref;
                }
                $targetArticleId = (int) ($fallbackItem['target_article_id'] ?? 0);
                if ($targetArticleId > 0) {
                    $seenTargetArticleIds[$targetArticleId] = true;
                }
            }

            usort(
                $internalSuggestions,
                static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
            );
        }

        $this->lastDebug = [
            'entry' => 'collectCandidates',
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'content_length' => mb_strlen($content),
            'plain_text_length' => mb_strlen($plainText),
            'matched_keyword_count' => count($matched),
            'primary_rejected_stop_phrases' => $primaryRejectedStop,
            'primary_valid_internal' => $primaryValidInternal,
            'target_internal_suggestions' => $this->contentKeywordFallback->targetCount(),
            'fallback_triggered' => $fallbackTriggered,
            'fallback_skip_reason' => $fallbackTriggered
                ? null
                : $this->contentKeywordFallback->skipReason($primaryValidInternal),
            'fallback' => $this->contentKeywordFallback->lastDebug(),
            'fallback_valid_count' => count($fallbackItems),
            'final_internal_count' => count($internalSuggestions),
            'final_external_count' => count($externalSuggestions),
            'config' => [
                'target_internal_suggestions' => (int) config('seo-content-ai.link_suggestions.target_internal_suggestions', 5),
                'fallback_enabled' => (bool) config('seo-content-ai.link_suggestions.fallback_enabled', true),
                'fallback_min_score' => (int) config('seo-content-ai.link_suggestions.fallback_min_score', 55),
                'fallback_phrase_limit' => (int) config('seo-content-ai.link_suggestions.fallback_phrase_limit', 10),
                'fallback_candidate_limit' => (int) config('seo-content-ai.link_suggestions.fallback_candidate_limit', 20),
                'min_accept_score' => (int) config('seo-content-ai.link_suggestions.min_accept_score', 40),
                'score_scale' => '0-100',
            ],
        ];
        $this->logDebug('collect_done', $this->lastDebug);

        $result = [
            'internal' => $internalSuggestions,
            'external' => $externalSuggestions,
        ];

        $this->candidatesCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logDebug(string $step, array $payload): void
    {
        if (! LinkSuggestionStopPhraseFilter::debugEnabled()) {
            return;
        }

        RuntimeLogger::info('[LINK_FALLBACK_DEBUG] '.$step, $payload);
    }

    /**
     * Secondary keywords gắn bài (main_article_id) nếu xuất hiện trong content.
     *
     * @return list<string>
     */
    private function secondaryKeywordsAppearingInContent(SeoArticle $article, string $plainText): array
    {
        $ids = $this->mainKeywordIdsForArticle((int) $article->id);
        if ($ids === []) {
            return [];
        }

        $phrases = Keyword::query()
            ->whereIn('id', $ids)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->pluck('phrase')
            ->all();

        $out = [];
        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '' || ! $this->textContainsPhrase($plainText, $phrase)) {
                continue;
            }
            $out[] = $phrase;
        }

        return $out;
    }

    private function limit(string $key, int $default): int
    {
        return max(1, (int) config('seo-content-ai.link_suggestions.'.$key, $default));
    }

    /**
     * @return list<int>
     */
    private function mainKeywordIdsForArticle(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        return KeywordMeta::query()
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->where('meta_value', (string) $articleId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function currentArticleUrls(SeoArticle $article): array
    {
        $urls = [];
        $public = trim((string) ($this->linkTargetResolver->resolveArticlePublicUrl($article) ?? ''));
        if ($public !== '') {
            $urls[] = $public;
        }

        $permalink = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')
            ?->meta_value ?? ''));
        if ($permalink !== '') {
            $urls[] = $permalink;
        }

        return $urls;
    }

    private function resolveTargetArticleId(int $siteId, string $href, SeoArticle $current): int
    {
        $target = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href, $current);

        return $target instanceof SeoArticle ? (int) $target->id : 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     */
    private function candidatesCacheKey(int $articleId, string $content, array $internalLinks, array $externalLinks): string
    {
        return $articleId.':'.md5($content).':'.md5(serialize($internalLinks)).':'.md5(serialize($externalLinks));
    }

    /**
     * @param  list<int>  $excludeKeywordIds
     * @return \Illuminate\Support\Collection<int, Keyword>
     */
    private function keywordsForSite(int $siteId, array $excludeKeywordIds): \Illuminate\Support\Collection
    {
        $cacheKey = $siteId.':'.implode(',', $excludeKeywordIds);
        if (isset($this->keywordsBySite[$cacheKey])) {
            return $this->keywordsBySite[$cacheKey];
        }

        $keywordsQuery = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->where('review_status', KeywordReviewStatus::Active->value)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->orderByRaw('CHAR_LENGTH(phrase) DESC');

        if ($excludeKeywordIds !== []) {
            $keywordsQuery->whereNotIn('id', $excludeKeywordIds);
        }

        // Eager linkMaps để resolveForKeyword không N+1 theo keyword.
        $keywords = $keywordsQuery
            ->with([
                'linkMaps' => static fn ($query) => $query->whereHas(
                    'sourceArticle',
                    static fn ($articleQuery) => $articleQuery->where('site_id', $siteId),
                )->with(['targetArticle:id,site_id,title,slug', 'sourceArticle:id,site_id']),
            ])
            ->get(['id', 'phrase', 'type']);

        $this->keywordsBySite[$cacheKey] = $keywords;

        return $keywords;
    }

    /**
     * @return 'internal'|'external'|null null = bỏ (tel/mail/… hoặc thiếu URL)
     */
    private function suggestionBucketForHref(string $href, string $siteDomain, int $siteId): ?string
    {
        if ($href === '' || SeoSuggestionUrlNormalizer::isPlaceholder($href)) {
            // Không còn gợi ý keyword-only / placeholder.
            return null;
        }

        if ($this->isSpecialSchemeOrContactHref($href)) {
            return null;
        }

        if ($this->isInternalHrefForSite($href, $siteDomain, $siteId)) {
            return 'internal';
        }

        return 'external';
    }

    private function isInternalHrefForSite(string $href, string $siteDomain, int $siteId): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return true;
        }

        $host = SeoSuggestionUrlNormalizer::host($href);
        if ($host !== '' && $siteDomain !== '' && $host === $siteDomain) {
            return true;
        }

        $targetArticle = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href);
        if ($targetArticle instanceof SeoArticle && (int) ($targetArticle->site_id ?? 0) === $siteId) {
            return true;
        }

        return false;
    }

    private function isSpecialSchemeOrContactHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        $lower = mb_strtolower($href);
        if (str_starts_with($lower, 'javascript:')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '') {
            return in_array(strtolower($scheme), [
                'tel',
                'mailto',
                'sms',
                'fax',
                'callto',
                'geo',
                'skype',
                'whatsapp',
                'viber',
                'data',
                'cid',
            ], true);
        }

        if (preg_match('/^[+]?[\d\s().-]{6,}$/u', $href) === 1) {
            return true;
        }

        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $href) === 1) {
            return true;
        }

        return false;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @return array{labels: list<string>, hrefs: list<string>}
     */
    private function collectLinkedContext(array $internalLinks): array
    {
        $labels = [];
        $hrefs = [];

        foreach ($internalLinks as $link) {
            $text = trim((string) ($link['text'] ?? ''));
            if ($text !== '') {
                $labels[] = mb_strtolower($text);
            }

            $href = trim((string) ($link['href'] ?? ''));
            if ($href === '') {
                continue;
            }

            $normalizedHref = SeoSuggestionUrlNormalizer::normalize($href);
            if ($normalizedHref !== '') {
                $hrefs[] = $normalizedHref;
            }

            $path = parse_url($href, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                continue;
            }

            $slug = basename($path);
            if ($slug !== '' && $slug !== '/') {
                $labels[] = mb_strtolower(str_replace(['-', '_'], ' ', $slug));
            }
        }

        return [
            'labels' => array_values(array_unique($labels)),
            'hrefs' => array_values(array_unique($hrefs)),
        ];
    }

    /**
     * @param  list<string>  $linkedHrefs
     */
    private function isHrefAlreadyLinked(string $href, array $linkedHrefs): bool
    {
        $normalized = SeoSuggestionUrlNormalizer::normalize($href);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $linkedHrefs, true);
    }

    /**
     * @param  list<string>  $linkedLabels
     */
    private function isAlreadyLinked(string $phrase, array $linkedLabels): bool
    {
        $phraseLower = mb_strtolower(trim($phrase));
        if ($phraseLower === '') {
            return true;
        }

        foreach ($linkedLabels as $label) {
            if ($label === $phraseLower) {
                return true;
            }

            if (mb_stripos($label, $phraseLower) !== false || mb_stripos($phraseLower, $label) !== false) {
                return true;
            }
        }

        return false;
    }

    private function textContainsPhrase(string $text, string $phrase): bool
    {
        return KeywordPhraseMatcher::contains($text, $phrase);
    }

    /**
     * @return list<string>
     */
    private function ownArticlePhraseBlocklist(SeoArticle $article): array
    {
        $phrases = [];

        $focus = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article);
        if ($focus !== null) {
            $normalized = $this->normalizePhrase($focus);
            if ($normalized !== '') {
                $phrases[] = $normalized;
            }
        }

        $title = $this->normalizePhrase((string) ($article->title ?? ''));
        if ($title !== '') {
            $phrases[] = $title;
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @param  list<string>  $ownArticlePhrases
     */
    private function isOwnArticlePhrase(string $phrase, array $ownArticlePhrases): bool
    {
        $normalized = $this->normalizePhrase($phrase);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $ownArticlePhrases, true);
    }

    private function normalizePhrase(string $phrase): string
    {
        $phrase = mb_strtolower(trim($phrase));
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';

        return trim($phrase);
    }
}
