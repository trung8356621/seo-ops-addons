<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Support\LinkSuggestionStopPhraseFilter;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;

/**
 * Unified Internal Link pipeline:
 * product_cat → topic → keyword_non_topic → generic
 *
 * Stage priority always wins over raw score.
 */
final class ArticleInternalLinkPipeline
{
    /** @var array<string, mixed> */
    private array $lastDebug = [];

    /** @var array<string, Collection<int, Keyword>> */
    private array $keywordsBySite = [];

    public function __construct(
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
        private readonly ArticleLinkSuggestionCandidateRetriever $candidateRetriever,
        private readonly ArticleLinkSuggestionSearchTermsBuilder $termsBuilder,
        private readonly ArticleLinkSuggestionContentKeywordFallback $contentKeywordFallback,
        private readonly ArticleInternalLinkProductCatMatcher $productCatMatcher,
        private readonly ArticleInternalLinkPriorityMerger $priorityMerger,
        private readonly ArticleInternalLinkTopicMembership $topicMembership,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lastDebug(): array
    {
        return $this->lastDebug;
    }

    /**
     * @param  array<int, array<string, mixed>>  $internalLinks
     * @param  array<int, array<string, mixed>>  $externalLinks
     * @return array{internal: list<array<string, mixed>>, external: list<array<string, mixed>>}
     */
    public function collect(
        SeoArticle $article,
        string $content,
        array $internalLinks,
        array $externalLinks = [],
    ): array {
        $empty = ['internal' => [], 'external' => []];
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return $empty;
        }

        $plainText = $this->plainTextFromHtml($content);
        if ($plainText === '') {
            $this->lastDebug = [
                'entry' => 'pipeline',
                'article_id' => (int) $article->id,
                'skip_reason' => 'empty_plain_text_after_strip',
            ];

            return $empty;
        }

        $article->loadMissing('site', 'articleMetas');
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) ($article->site?->domain ?? ''));
        $validationContext = [
            'site_domain' => $siteDomain,
            'site_id' => $siteId,
            'current_article_id' => (int) $article->id,
            'current_urls' => $this->currentArticleUrls($article),
            'current_slug' => trim((string) ($article->slug ?? ''), '/'),
        ];

        $linkedContext = $this->collectLinkedContext(array_merge($internalLinks, $externalLinks));
        $linkedLabels = $linkedContext['labels'];
        $linkedHrefs = $linkedContext['hrefs'];
        $ownArticlePhrases = $this->ownArticlePhraseBlocklist($article);

        // —— Stage 1: FULL product_cat ——
        $productCatResult = $this->productCatMatcher->matchForSite(
            $siteId,
            $plainText,
            $validationContext,
            $linkedHrefs,
            $linkedLabels,
        );
        $productCatSuggestions = [];
        foreach ($productCatResult['suggestions'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['source'] = ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT;
            $row['candidate_source'] = ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT;
            $productCatSuggestions[] = $row;
        }
        $productCatDebug = is_array($productCatResult['debug'] ?? null) ? $productCatResult['debug'] : [];

        foreach ($productCatSuggestions as $item) {
            $label = mb_strtolower(trim((string) ($item['text'] ?? '')));
            if ($label !== '') {
                $linkedLabels[] = $label;
            }
            $norm = SeoSuggestionUrlNormalizer::normalize((string) ($item['href'] ?? ''));
            if ($norm !== '') {
                $linkedHrefs[] = $norm;
            }
        }

        // —— Match SEO keywords present in content ——
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

        $matchedIds = array_map(
            static fn (array $row): int => (int) $row['keyword']->id,
            $matched,
        );
        $clusterKeys = $this->topicMembership->clusterKeysByKeywordId($matchedIds);

        $topicSuggestions = [];
        $keywordNonTopicSuggestions = [];
        $needGenericSearch = [];
        $externalSuggestions = [];

        foreach ($matched as $row) {
            $keyword = $row['keyword'];
            $phrase = $row['phrase'];
            $keywordId = (int) $keyword->id;
            $isTopic = $this->topicMembership->isTopicKeyword($keyword, $clusterKeys);

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

            if ($href === '' || $this->isSpecialSchemeOrContactHref($href)) {
                // No mapped destination — defer to generic article search.
                $needGenericSearch[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => $phrase,
                    'context' => array_merge($articleContextBase, [
                        'paragraph_context' => $this->termsBuilder->extractParagraphContext($plainText, $phrase),
                    ]),
                ];
                continue;
            }

            $bucket = $this->suggestionBucketForHref($href, $siteDomain, $siteId);
            if ($bucket === null) {
                continue;
            }

            $stage = $isTopic
                ? ArticleInternalLinkPriorityMerger::STAGE_TOPIC
                : ArticleInternalLinkPriorityMerger::STAGE_KEYWORD_NON_TOPIC;

            $targetArticleId = $this->resolveTargetArticleId($siteId, $href, $article);
            $item = [
                'text' => $phrase,
                'keyword_id' => $keywordId,
                'href' => $href,
                'target_url' => $href,
                'target_article_id' => $targetArticleId > 0 ? $targetArticleId : null,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => $isTopic ? 92 : 88,
                'match_reason' => $isTopic ? 'topic_focus_destination' : 'keyword_link_map',
                'source' => $stage,
                'candidate_source' => $stage,
                'cluster_key' => $isTopic ? (string) ($clusterKeys[$keywordId] ?? '') : null,
                'bucket' => $bucket,
                'provenance' => [
                    'candidate_source' => $stage,
                    'keyword_id' => $keywordId,
                    'cluster_key' => $isTopic ? (string) ($clusterKeys[$keywordId] ?? '') : null,
                    'matched_phrase' => $phrase,
                    'match_reason' => $isTopic ? 'topic_focus_destination' : 'keyword_link_map',
                    'url' => $href,
                ],
            ];

            if (! LinkSuggestionValidator::isValidLinkSuggestion($item, $validationContext)) {
                continue;
            }
            unset($item['bucket']);

            if ($bucket === 'external') {
                $externalSuggestions[] = $item;
                continue;
            }

            if ($isTopic) {
                $topicSuggestions[] = $item;
            } else {
                $keywordNonTopicSuggestions[] = $item;
            }
        }

        // —— Stage 4 generic: article index for unresolved phrases + content fallback ——
        $genericSuggestions = [];
        $stagesSoFarCount = count($productCatSuggestions) + count($topicSuggestions) + count($keywordNonTopicSuggestions);
        $articleTargets = $this->candidateRetriever->resolveBestForAnchors(
            $article,
            $needGenericSearch,
            $linkedHrefs,
        );
        foreach ($needGenericSearch as $anchor) {
            $keywordId = (int) ($anchor['keyword_id'] ?? 0);
            $resolved = $articleTargets[$keywordId] ?? null;
            if (! is_array($resolved)) {
                continue;
            }
            $href = trim((string) ($resolved['href'] ?? ''));
            if ($href === '' || $this->isSpecialSchemeOrContactHref($href)) {
                continue;
            }
            $bucket = $this->suggestionBucketForHref($href, $siteDomain, $siteId);
            if ($bucket !== 'internal') {
                continue;
            }
            $targetArticleId = (int) ($resolved['target_article_id'] ?? 0);
            if ($targetArticleId <= 0) {
                $targetArticleId = $this->resolveTargetArticleId($siteId, $href, $article);
            }
            $item = [
                'text' => (string) ($anchor['phrase'] ?? ''),
                'keyword_id' => $keywordId,
                'href' => $href,
                'target_url' => $href,
                'target_article_id' => $targetArticleId > 0 ? $targetArticleId : null,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => (int) ($resolved['score'] ?? 0),
                'match_reason' => (string) ($resolved['match_reason'] ?? 'article_index'),
                'source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
                'candidate_source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
                'bucket' => 'internal',
                'provenance' => [
                    'candidate_source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
                    'matched_phrase' => (string) ($anchor['phrase'] ?? ''),
                    'match_reason' => (string) ($resolved['match_reason'] ?? 'article_index'),
                    'url' => $href,
                ],
            ];
            if (! LinkSuggestionValidator::isValidLinkSuggestion($item, $validationContext)) {
                continue;
            }
            unset($item['bucket']);
            $genericSuggestions[] = $item;
        }

        $fallbackTriggered = false;
        $fallbackItems = [];
        $preGenericMerged = $this->priorityMerger->merge(
            [
                ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT => $productCatSuggestions,
                ArticleInternalLinkPriorityMerger::STAGE_TOPIC => $topicSuggestions,
                ArticleInternalLinkPriorityMerger::STAGE_KEYWORD_NON_TOPIC => $keywordNonTopicSuggestions,
                ArticleInternalLinkPriorityMerger::STAGE_GENERIC => $genericSuggestions,
            ],
            $linkedHrefs,
            $linkedLabels,
        );

        if ($this->contentKeywordFallback->shouldRun(count($preGenericMerged))) {
            $fallbackTriggered = true;
            $seenTargets = [];
            foreach ($preGenericMerged as $row) {
                $tid = (int) ($row['target_article_id'] ?? 0);
                if ($tid > 0) {
                    $seenTargets[$tid] = true;
                }
            }
            $excludeLabels = $linkedLabels;
            $excludeUrls = $linkedHrefs;
            foreach ($preGenericMerged as $row) {
                $excludeLabels[] = mb_strtolower(trim((string) ($row['text'] ?? '')));
                $norm = SeoSuggestionUrlNormalizer::normalize((string) ($row['href'] ?? ''));
                if ($norm !== '') {
                    $excludeUrls[] = $norm;
                }
            }

            $priorityPhrases = array_values(array_filter([
                (string) $focusKeyword,
                ...$this->secondaryKeywordsAppearingInContent($article, $plainText),
            ]));

            $rawFallback = $this->contentKeywordFallback->supplement(
                $article,
                $content,
                $preGenericMerged,
                $excludeLabels,
                $excludeUrls,
                $seenTargets,
                $validationContext,
                $priorityPhrases,
            );

            foreach ($rawFallback as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row['source'] = ArticleInternalLinkPriorityMerger::STAGE_GENERIC;
                $row['candidate_source'] = ArticleInternalLinkPriorityMerger::STAGE_GENERIC;
                $row['provenance'] = array_merge(
                    is_array($row['provenance'] ?? null) ? $row['provenance'] : [],
                    [
                        'candidate_source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
                        'match_reason' => (string) ($row['match_reason'] ?? 'content_keyword_fallback'),
                    ],
                );
                $fallbackItems[] = $row;
                $genericSuggestions[] = $row;
            }
        }

        $internalSuggestions = $this->priorityMerger->merge(
            [
                ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT => $productCatSuggestions,
                ArticleInternalLinkPriorityMerger::STAGE_TOPIC => $topicSuggestions,
                ArticleInternalLinkPriorityMerger::STAGE_KEYWORD_NON_TOPIC => $keywordNonTopicSuggestions,
                ArticleInternalLinkPriorityMerger::STAGE_GENERIC => $genericSuggestions,
            ],
            $linkedHrefs,
            $linkedLabels,
        );

        usort(
            $externalSuggestions,
            static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
        );

        $countByStage = [
            'product_cat' => 0,
            'topic' => 0,
            'keyword_non_topic' => 0,
            'generic' => 0,
        ];
        foreach ($internalSuggestions as $row) {
            $stage = (string) ($row['source_stage'] ?? '');
            if (isset($countByStage[$stage])) {
                $countByStage[$stage]++;
            }
        }

        $internalLinkCatalog = array_merge($productCatDebug, [
            'matched_product_cat' => $countByStage['product_cat'],
            'topic_candidates' => $countByStage['topic'],
            'keyword_non_topic_candidates' => $countByStage['keyword_non_topic'],
            'generic_candidates' => $countByStage['generic'],
            'article_candidates' => count($genericSuggestions) - count($fallbackItems),
            'fallback_candidates' => count($fallbackItems),
            'fallback_triggered' => $fallbackTriggered,
            'sort' => 'source_priority ASC, score DESC',
        ]);

        $this->lastDebug = [
            'entry' => 'pipeline',
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'matched_keyword_count' => count($matched),
            'primary_rejected_stop_phrases' => $primaryRejectedStop,
            'stages_before_generic' => $stagesSoFarCount,
            'fallback_triggered' => $fallbackTriggered,
            'fallback' => $this->contentKeywordFallback->lastDebug(),
            'final_internal_count' => count($internalSuggestions),
            'final_external_count' => count($externalSuggestions),
            'stage_counts' => $countByStage,
            'internal_link_catalog' => $internalLinkCatalog,
        ];

        RuntimeLogger::info('[INTERNAL_LINK_PIPELINE]', [
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            ...$countByStage,
            'sort' => 'source_priority ASC, score DESC',
        ]);

        return [
            'internal' => $internalSuggestions,
            'external' => $externalSuggestions,
        ];
    }

    /**
     * @param  list<string>  $excludeKeywordIds
     * @return Collection<int, Keyword>
     */
    private function keywordsForSite(int $siteId, array $excludeKeywordIds = []): Collection
    {
        $excludeKey = implode(',', array_map('intval', $excludeKeywordIds));
        $cacheKey = $siteId.'|'.$excludeKey;
        if (isset($this->keywordsBySite[$cacheKey])) {
            return $this->keywordsBySite[$cacheKey];
        }

        $query = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->where('review_status', KeywordReviewStatus::Active->value)
            ->whereNotNull('phrase')
            ->where('phrase', '!=', '')
            ->whereDoesntHave(
                'metas',
                static function ($meta): void {
                    $meta->where(
                        'meta_key',
                        KeywordMetaKey::SeoHidden->value,
                    )->where('meta_value', '1');
                },
            )
            ->orderByRaw('CHAR_LENGTH(phrase) DESC')
            ->limit(800);

        if ($excludeKeywordIds !== []) {
            $query->whereNotIn('id', $excludeKeywordIds);
        }

        $this->keywordsBySite[$cacheKey] = $query->get();

        return $this->keywordsBySite[$cacheKey];
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
            ->unique()
            ->values()
            ->all();
    }

    /**
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

    /**
     * @param  array<int, array<string, mixed>>  $links
     * @return array{labels: list<string>, hrefs: list<string>}
     */
    private function collectLinkedContext(array $links): array
    {
        $labels = [];
        $hrefs = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $text = mb_strtolower(trim((string) ($link['text'] ?? $link['anchor'] ?? '')));
            if ($text !== '') {
                $labels[] = $text;
            }
            $href = trim((string) ($link['href'] ?? $link['target_url'] ?? $link['url'] ?? ''));
            $norm = SeoSuggestionUrlNormalizer::normalize($href);
            if ($norm !== '') {
                $hrefs[] = $norm;
            }
        }

        return ['labels' => $labels, 'hrefs' => $hrefs];
    }

    /**
     * @return list<string>
     */
    private function ownArticlePhraseBlocklist(SeoArticle $article): array
    {
        $out = [];
        foreach ([(string) ($article->title ?? ''), (string) ($article->slug ?? '')] as $raw) {
            $norm = KeywordPhraseMatcher::normalize(str_replace(['-', '_'], ' ', $raw));
            if ($norm !== '') {
                $out[] = $norm;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $linkedLabels
     */
    private function isAlreadyLinked(string $phrase, array $linkedLabels): bool
    {
        $needle = KeywordPhraseMatcher::normalize($phrase);
        if ($needle === '') {
            return false;
        }
        foreach ($linkedLabels as $label) {
            if (KeywordPhraseMatcher::normalize((string) $label) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ownPhrases
     */
    private function isOwnArticlePhrase(string $phrase, array $ownPhrases): bool
    {
        $needle = KeywordPhraseMatcher::normalize($phrase);

        return $needle !== '' && in_array($needle, $ownPhrases, true);
    }

    private function textContainsPhrase(string $text, string $phrase): bool
    {
        return KeywordPhraseMatcher::contains($text, $phrase);
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function isSpecialSchemeOrContactHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return true;
        }

        return (bool) preg_match('#^(mailto:|tel:|javascript:|sms:)#i', $href);
    }

    private function suggestionBucketForHref(string $href, string $siteDomain, int $siteId): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, '/')) {
            return 'internal';
        }
        $host = SeoLinkMapLinkTypeClassifier::resolveHost($href);
        if ($host !== '' && $siteDomain !== '' && $host === $siteDomain) {
            return 'internal';
        }
        $target = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href);
        if ($target instanceof SeoArticle && (int) ($target->site_id ?? 0) === $siteId) {
            return 'internal';
        }
        if ($host !== '') {
            return 'external';
        }

        return null;
    }

    private function resolveTargetArticleId(int $siteId, string $href, SeoArticle $exclude): int
    {
        $found = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href, $exclude);

        return $found instanceof SeoArticle ? (int) $found->id : 0;
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
        $permalink = trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''
        ));
        if ($permalink !== '') {
            $urls[] = $permalink;
        }

        return $urls;
    }
}
