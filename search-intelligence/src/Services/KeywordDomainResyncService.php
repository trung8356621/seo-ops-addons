<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;


use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\SearchFoundation\Support\KeywordSyncIsolation;
use Illuminate\Support\Facades\DB;

final class KeywordDomainResyncService
{
    public function __construct(
        private readonly CtaKeywordBlacklistFilter $ctaKeywordBlacklistFilter,
        private readonly KeywordPersistenceService $keywordPersistence,
        private readonly ArticleLinkContextMapService $linkContextMap,
        private readonly KeywordQualityFlagService $qualityFlags,
        private readonly KeywordMetaRepository $metaRepository,
    ) {}

    /**
     * @return array{
     *     deleted:int,
     *     kept:int,
     *     cta_deleted:int,
     *     articles:int,
     *     rescanned:int,
     *     skipped_articles:int,
     *     link_maps_created:int,
     *     keywords_total:int,
     * }
     */
    public function resetAndResync(int $siteId): array
    {
        if ($siteId <= 0 || ! KeywordSyncIsolation::allowsDomainResync()) {
            return [
                'deleted' => 0,
                'kept' => 0,
                'cta_deleted' => 0,
                'articles' => 0,
                'rescanned' => 0,
                'skipped_articles' => 0,
                'link_maps_created' => 0,
                'keywords_total' => 0,
            ];
        }

        return KeywordSyncIsolation::runWithinDomainResync(function () use ($siteId): array {
            $ctaDeleteStats = $this->deleteCtaBlacklistedKeywordsForSite($siteId);
            $deleteStats = $this->deleteLinkedKeywordsForSite($siteId);
            $resyncStats = $this->resyncKeywordsFromArticles($siteId);
            $focusSynced = KeywordFocusAttach::syncFocusKeywordsFromArticles($siteId);
            $qualityRecomputed = $this->qualityFlags->recomputeForSite($siteId);

            $keywordsTotal = Keyword::query()->forSite($siteId)->count();

            return array_merge($deleteStats, $ctaDeleteStats, $resyncStats, [
                'keywords_total' => $keywordsTotal,
                'focus_synced' => $focusSynced,
                'quality_recomputed' => $qualityRecomputed,
            ]);
        });
    }

    /**
     * @return array{cta_deleted:int}
     */
    public function deleteCtaBlacklistedKeywordsForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            return ['cta_deleted' => 0];
        }

        $deleted = 0;

        DB::connection((new Keyword)->getConnectionName())->transaction(function () use ($siteId, &$deleted): void {
            Keyword::query()
                ->forSite($siteId)
                ->orderBy('id')
                ->chunkById(200, function ($keywords) use ($siteId, &$deleted): void {
                    foreach ($keywords as $keyword) {
                        if (! $keyword instanceof Keyword) {
                            continue;
                        }

                        if (! $this->ctaKeywordBlacklistFilter->isBlocked((string) $keyword->phrase)) {
                            continue;
                        }

                        $this->deleteKeywordForSite($keyword, $siteId);
                        $deleted++;
                    }
                });
        });

        return ['cta_deleted' => $deleted];
    }

    /**
     * @return array{deleted:int, kept:int}
     */
    public function deleteLinkedKeywordsForSite(int $siteId): array
    {
        $deleted = 0;
        $kept = 0;

        DB::connection((new Keyword)->getConnectionName())->transaction(function () use ($siteId, &$deleted, &$kept): void {
            Keyword::query()
                ->forSite($siteId)
                ->withCount([
                    'mainArticles as main_articles_on_site_count' => static fn ($query) => $query->where('articles.site_id', $siteId),
                    'linkMaps as inbound_link_maps_on_site_count' => static fn ($query) => $query
                        ->whereHas('sourceArticle', static fn ($articleQuery) => $articleQuery->where('site_id', $siteId)),
                ])
                ->orderBy('id')
                ->chunkById(200, function ($keywords) use ($siteId, &$deleted, &$kept): void {
                    foreach ($keywords as $keyword) {
                        if (! $keyword instanceof Keyword) {
                            continue;
                        }

                        if (! $this->isLinkedOnSite($keyword, $siteId)) {
                            $kept++;

                            continue;
                        }

                        $this->deleteKeywordForSite($keyword, $siteId);
                        $deleted++;
                    }
                });
        });

        return [
            'deleted' => $deleted,
            'kept' => $kept,
        ];
    }

    /**
     * @return array{articles:int, rescanned:int, skipped_articles:int, link_maps_created:int}
     */
    public function resyncKeywordsFromArticles(int $siteId): array
    {
        $articles = 0;
        $rescanned = 0;
        $skipped = 0;
        $linkMapsCreated = 0;

        SeoArticle::query()
            ->where('site_id', $siteId)
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use (&$articles, &$rescanned, &$skipped, &$linkMapsCreated): void {
                foreach ($chunk as $article) {
                    if (! $article instanceof SeoArticle) {
                        continue;
                    }

                    $articles++;
                    $content = $this->resolveArticleContent($article);
                    if ($content === '') {
                        $skipped++;

                        continue;
                    }

                    $linkMapsCreated += $this->linkContextMap->resyncArticle($article);
                    $rescanned++;
                }
            });

        return [
            'articles' => $articles,
            'rescanned' => $rescanned,
            'skipped_articles' => $skipped,
            'link_maps_created' => $linkMapsCreated,
        ];
    }

    public function deleteKeywordForSite(Keyword $keyword, int $siteId): void
    {
        $this->keywordPersistence->detachKeywordFromSite($keyword, $siteId);

        if (KeywordResource::isUnused($keyword)) {
            $this->deleteKeywordRecord($keyword);
        }
    }

    public function deleteKeywordRecord(Keyword $keyword): void
    {
        SeoLinkMap::query()->where('keyword_id', $keyword->id)->delete();
        $this->metaRepository->deleteAllForKeyword((int) $keyword->id);
        $keyword->delete();
    }

    private function isLinkedOnSite(Keyword $keyword, int $siteId): bool
    {
        if ($keyword->keepOnRescrapeForSite($siteId)) {
            return false;
        }

        if (KeywordFocusAttach::isFocusKeywordForSite($keyword, $siteId)
            || KeywordFocusAttach::phraseMatchesFocusOnSite($keyword, $siteId)) {
            return false;
        }

        if ((int) ($keyword->inbound_link_maps_on_site_count ?? 0) > 0) {
            return true;
        }

        return (int) ($keyword->main_articles_on_site_count ?? 0) > 0;
    }

    private function resolveArticleContent(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        $meta = $article->articleMetas()
            ->where('meta_key', 'wp_post_content')
            ->value('meta_value');

        return is_string($meta) ? trim($meta) : '';
    }
}
