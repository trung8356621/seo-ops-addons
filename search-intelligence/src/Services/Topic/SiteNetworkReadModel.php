<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Site Network read model — directional cross-site link aggregate.
 *
 * Answers: which managed sites link to which other managed sites?
 *
 * Direction is PRESERVED. A→B and B→A are separate edges.
 *
 * Scope: only managed sites (registered in the `sites` table and accessible
 * via the current SeoAccessControl scope).
 *
 * NOTE: Agent Runtime global retrieval stays unsupported. This read model
 * is only consumed by the Site Network API and Topical Map frontend.
 */
final class SiteNetworkReadModel
{
    /**
     * @return array{
     *   sites: list<array{site_ref: string, site_id: int, domain: string}>,
     *   edges: list<array{
     *     source_site_ref: string,
     *     target_site_ref: string,
     *     article_link_count: int,
     *     source_article_count: int,
     *     target_article_count: int,
     *     source_keyword_count: int
     *   }>,
     *   accessible_site_count: int
     * }
     */
    public function overview(): array
    {
        $articleTable = (new SeoArticle())->getTable();
        $managedSites = $this->loadManagedSites();
        $accessibleSiteCount = count($managedSites);
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps') || ! Schema::connection('omi_seo_ai')->hasTable($articleTable)) {
            return ['sites' => [], 'edges' => [], 'accessible_site_count' => $accessibleSiteCount];
        }

        if ($managedSites === []) {
            return ['sites' => [], 'edges' => [], 'accessible_site_count' => 0];
        }

        $siteIdSet = array_column($managedSites, 'site_id');
        $edges = $this->computeEdges($siteIdSet);

        // Only include sites that participate in at least one edge.
        $participatingSiteIds = [];
        foreach ($edges as $edge) {
            $participatingSiteIds[$edge['source_site_id']] = true;
            $participatingSiteIds[$edge['target_site_id']] = true;
        }

        $sites = array_values(array_filter(
            $managedSites,
            static fn (array $s): bool => isset($participatingSiteIds[$s['site_id']]),
        ));

        $edgePayload = array_map(static function (array $edge): array {
            return [
                'source_site_ref' => 'site:'.$edge['source_site_id'],
                'target_site_ref' => 'site:'.$edge['target_site_id'],
                'article_link_count' => $edge['article_link_count'],
                'source_article_count' => $edge['source_article_count'],
                'target_article_count' => $edge['target_article_count'],
                'source_keyword_count' => $edge['source_keyword_count'],
            ];
        }, $edges);

        return [
            'sites' => array_map(static fn (array $s): array => [
                'site_ref' => 'site:'.$s['site_id'],
                'site_id' => $s['site_id'],
                'domain' => $s['domain'],
            ], $sites),
            'edges' => $edgePayload,
            'accessible_site_count' => $accessibleSiteCount,
        ];
    }

    /**
     * Topics participating in the given site pair (directional).
     *
     * @return array{
     *   source_site_ref: string,
     *   target_site_ref: string,
     *   topics: list<array{topic_id: int, topic_ref: string, name: string, cross_site_link_count: int}>
     * }|null
     */
    public function topicsForSitePair(int $sourceSiteId, int $targetSiteId): ?array
    {
        $articleTable = (new SeoArticle())->getTable();
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps') || ! Schema::connection('omi_seo_ai')->hasTable($articleTable)) {
            return null;
        }

        if ($sourceSiteId <= 0 || $targetSiteId <= 0 || $sourceSiteId === $targetSiteId) {
            return null;
        }

        $managedSiteIds = array_column($this->loadManagedSites(), 'site_id');
        if (! in_array($sourceSiteId, $managedSiteIds, true) || ! in_array($targetSiteId, $managedSiteIds, true)) {
            return null;
        }

        $topics = $this->loadTopicsForSitePair($sourceSiteId, $targetSiteId);

        return [
            'source_site_ref' => 'site:'.$sourceSiteId,
            'target_site_ref' => 'site:'.$targetSiteId,
            'topics' => $topics,
        ];
    }

    /**
     * @return list<array{site_id: int, domain: string}>
     */
    private function loadManagedSites(): array
    {
        if (! Schema::hasTable('sites')) {
            return [];
        }

        $query = SeoAccessControl::accessibleSitesQuery()
            ->select(['id', 'domain'])
            ->orderBy('id');

        $sites = [];
        foreach ($query->get() as $site) {
            if (! $site instanceof Site) {
                continue;
            }
            $domain = trim((string) ($site->domain ?? ''));
            if ($domain === '') {
                continue;
            }
            $sites[] = [
                'site_id' => (int) $site->id,
                'domain' => $domain,
            ];
        }

        return $sites;
    }

    /**
     * Compute directional cross-site edges from seo_link_maps.
     *
     * A row is a cross-site edge when:
     * - source_article belongs to site A
     * - destination site is site B (different from A), resolved via
     *   slm.target_site_id (canonical) or ta.site_id (legacy fallback)
     * - both sites are in the managed site set
     * - link_type IN ('managed_cross_site', 'external') — 'external' retained
     *   for backward compat rows that predate this classification.
     *
     * Note: target_article_id may be null for un-crawled managed cross-site URLs.
     * Such links contribute to article_link_count, source_article_count,
     * and source_keyword_count, but NOT to target_article_count.
     *
     * @param  list<int>  $siteIdSet
     * @return list<array{
     *   source_site_id: int,
     *   target_site_id: int,
     *   article_link_count: int,
     *   source_article_count: int,
     *   target_article_count: int,
     *   source_keyword_count: int
     * }>
     */
    private function computeEdges(array $siteIdSet): array
    {
        $articleTable = (new SeoArticle())->getTable();
        if ($siteIdSet === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps') || ! Schema::connection('omi_seo_ai')->hasTable($articleTable)) {
            return [];
        }

        $linkTypes = ['managed_cross_site', 'external'];

        // Raw aggregate query using subquery joins for site_id resolution.
        // Canonical cross-site destination identity uses slm.target_site_id when available,
        // with backward-compatible fallback to target article's site_id (ta.site_id).
        $rows = DB::connection('omi_seo_ai')
            ->table('seo_link_maps as slm')
            ->selectRaw(
                'sa.site_id as source_site_id, '.
                'COALESCE(slm.target_site_id, ta.site_id) as target_site_id, '.
                'COUNT(slm.id) as article_link_count, '.
                'COUNT(DISTINCT slm.source_article_id) as source_article_count, '.
                'COUNT(DISTINCT slm.target_article_id) as target_article_count, '.
                'COUNT(DISTINCT slm.keyword_id) as source_keyword_count'
            )
            ->join("{$articleTable} as sa", 'sa.id', '=', 'slm.source_article_id')
            ->leftJoin("{$articleTable} as ta", 'ta.id', '=', 'slm.target_article_id')
            ->whereIn('slm.link_type', $linkTypes)
            ->whereIn('sa.site_id', $siteIdSet)
            ->whereNotNull('slm.source_article_id')
            ->where(function ($q) use ($siteIdSet): void {
                $q->where(function ($sub) use ($siteIdSet): void {
                    $sub->whereNotNull('slm.target_site_id')
                        ->whereIn('slm.target_site_id', $siteIdSet);
                })->orWhere(function ($sub) use ($siteIdSet): void {
                    $sub->whereNull('slm.target_site_id')
                        ->whereNotNull('slm.target_article_id')
                        ->whereIn('ta.site_id', $siteIdSet);
                });
            })
            ->whereRaw('sa.site_id != COALESCE(slm.target_site_id, ta.site_id)')
            ->groupByRaw('sa.site_id, COALESCE(slm.target_site_id, ta.site_id)')
            ->get();

        $edges = [];
        foreach ($rows as $row) {
            $sourceSiteId = (int) ($row->source_site_id ?? 0);
            $targetSiteId = (int) ($row->target_site_id ?? 0);
            if ($sourceSiteId <= 0 || $targetSiteId <= 0) {
                continue;
            }

            $edges[] = [
                'source_site_id' => $sourceSiteId,
                'target_site_id' => $targetSiteId,
                'article_link_count' => (int) ($row->article_link_count ?? 0),
                'source_article_count' => (int) ($row->source_article_count ?? 0),
                'target_article_count' => (int) ($row->target_article_count ?? 0),
                'source_keyword_count' => (int) ($row->source_keyword_count ?? 0),
            ];
        }

        return $edges;
    }

    /**
     * @return list<array{topic_id: int, topic_ref: string, name: string, cross_site_link_count: int}>
     */
    private function loadTopicsForSitePair(int $sourceSiteId, int $targetSiteId): array
    {
        $articleTable = (new SeoArticle())->getTable();
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_keywords') || ! Schema::connection('omi_seo_ai')->hasTable('seo_topics') || ! Schema::connection('omi_seo_ai')->hasTable($articleTable)) {
            return [];
        }

        $linkTypes = ['managed_cross_site', 'external'];

        // Find topics on source site that have keywords with cross-site links to target site.
        // Supports managed_cross_site links even when target_article_id is unresolved (null).
        $rows = DB::connection('omi_seo_ai')
            ->table('seo_link_maps as slm')
            ->selectRaw(
                'stk.topic_id, COUNT(slm.id) as cross_site_link_count'
            )
            ->join("{$articleTable} as sa", 'sa.id', '=', 'slm.source_article_id')
            ->leftJoin("{$articleTable} as ta", 'ta.id', '=', 'slm.target_article_id')
            ->join('seo_topic_keywords as stk', function ($join) use ($sourceSiteId): void {
                $join->on('stk.keyword_id', '=', 'slm.keyword_id')
                    ->where('stk.site_id', $sourceSiteId);
            })
            ->whereIn('slm.link_type', $linkTypes)
            ->where('sa.site_id', $sourceSiteId)
            ->where(function ($q) use ($targetSiteId): void {
                $q->where('slm.target_site_id', $targetSiteId)
                    ->orWhere(function ($sub) use ($targetSiteId): void {
                        $sub->whereNull('slm.target_site_id')
                            ->where('ta.site_id', $targetSiteId);
                    });
            })
            ->whereRaw('sa.site_id != COALESCE(slm.target_site_id, ta.site_id)')
            ->groupBy('stk.topic_id')
            ->orderByRaw('cross_site_link_count DESC')
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $topicIds = $rows->pluck('topic_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        // Load topic names.
        $topicNames = DB::connection('omi_seo_ai')
            ->table('seo_topics')
            ->whereIn('id', $topicIds)
            ->where('site_id', $sourceSiteId)
            ->get(['id', 'name'])
            ->keyBy('id');

        $topics = [];
        foreach ($rows as $row) {
            $topicId = (int) ($row->topic_id ?? 0);
            if ($topicId <= 0) {
                continue;
            }
            $topicRow = $topicNames->get($topicId);
            $name = trim((string) ($topicRow?->name ?? ''));
            $topics[] = [
                'topic_id' => $topicId,
                'topic_ref' => 'topic:'.$topicId,
                'name' => $name !== '' ? $name : ('Topic '.$topicId),
                'cross_site_link_count' => (int) ($row->cross_site_link_count ?? 0),
            ];
        }

        return $topics;
    }
}
