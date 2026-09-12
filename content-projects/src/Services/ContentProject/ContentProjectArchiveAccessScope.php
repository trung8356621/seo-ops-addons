<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical tenant access for Content Project archives.
 *
 * Single-domain: archive.site_id must be accessible.
 * Multi-domain (site_id NULL/0): visible only if at least one archive item
 * resolves to an accessible site (snapshot.site_id and/or live article.site_id).
 *
 * Never use an unconditional null site_id OR — that leaks foreign tenants.
 */
final class ContentProjectArchiveAccessScope
{
    /**
     * Constrain archive list/filter queries to archives the actor may see.
     *
     * @param  Builder<SeoProjectArchive>  $query
     * @param  list<int>  $accessibleSiteIds  Empty = unrestricted (same convention as vault page).
     * @return Builder<SeoProjectArchive>
     */
    public function constrainQuery(Builder $query, array $accessibleSiteIds): Builder
    {
        $siteIds = $this->normalizeSiteIds($accessibleSiteIds);
        if ($siteIds === []) {
            return $query;
        }

        $archivesTable = $query->getModel()->getTable();

        return $query->where(function (Builder $builder) use ($siteIds, $archivesTable): void {
            $builder
                ->whereIn("{$archivesTable}.site_id", $siteIds)
                ->orWhere(function (Builder $multi) use ($siteIds, $archivesTable): void {
                    $multi
                        ->where(function (Builder $nullSite) use ($archivesTable): void {
                            $nullSite
                                ->whereNull("{$archivesTable}.site_id")
                                ->orWhere("{$archivesTable}.site_id", 0);
                        })
                        ->where(function (Builder $hasItem) use ($siteIds, $archivesTable): void {
                            $this->whereArchiveHasAccessibleItem($hasItem, $archivesTable, $siteIds);
                        });
                });
        });
    }

    /**
     * Domain filter: single-domain match OR multi-domain archive containing that site.
     *
     * @param  Builder<SeoProjectArchive>  $query
     * @return Builder<SeoProjectArchive>
     */
    public function constrainQueryToSiteFilter(Builder $query, int $siteId): Builder
    {
        if ($siteId <= 0) {
            return $query;
        }

        $archivesTable = $query->getModel()->getTable();

        return $query->where(function (Builder $builder) use ($siteId, $archivesTable): void {
            $builder
                ->where("{$archivesTable}.site_id", $siteId)
                ->orWhere(function (Builder $multi) use ($siteId, $archivesTable): void {
                    $multi
                        ->where(function (Builder $nullSite) use ($archivesTable): void {
                            $nullSite
                                ->whereNull("{$archivesTable}.site_id")
                                ->orWhere("{$archivesTable}.site_id", 0);
                        })
                        ->where(function (Builder $hasItem) use ($siteId, $archivesTable): void {
                            $this->whereArchiveHasAccessibleItem($hasItem, $archivesTable, [$siteId]);
                        });
                });
        });
    }

    /**
     * @param  list<int>  $accessibleSiteIds
     */
    public function userCanAccessArchive(SeoProjectArchive $archive, array $accessibleSiteIds): bool
    {
        $siteIds = $this->normalizeSiteIds($accessibleSiteIds);
        $archiveSiteId = (int) ($archive->site_id ?? 0);

        if ($archiveSiteId > 0) {
            if ($siteIds === []) {
                return SeoAccessControl::canAccessSite($archiveSiteId);
            }

            return in_array($archiveSiteId, $siteIds, true);
        }

        // Multi-domain / domain-free header.
        if ($siteIds === []) {
            return true;
        }

        $itemSiteIds = $this->resolveArchiveItemSiteIds($archive);

        return $itemSiteIds !== [] && array_intersect($itemSiteIds, $siteIds) !== [];
    }

    /**
     * Preview rows: for multi-domain archives, only expose accessible item sites.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $accessibleSiteIds
     * @return list<array<string, mixed>>
     */
    public function filterPresenterRows(array $rows, array $accessibleSiteIds, ?SeoProjectArchive $archive = null): array
    {
        $siteIds = $this->normalizeSiteIds($accessibleSiteIds);
        if ($siteIds === []) {
            return array_values($rows);
        }

        $archiveSiteId = (int) ($archive?->site_id ?? 0);
        if ($archiveSiteId > 0) {
            return array_values($rows);
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($siteIds): bool {
                $siteId = (int) ($row['site_id'] ?? 0);

                return $siteId > 0 && in_array($siteId, $siteIds, true);
            },
        ));
    }

    /**
     * @return list<int>
     */
    public function resolveArchiveItemSiteIds(SeoProjectArchive $archive): array
    {
        $ids = [];

        $items = $archive->relationLoaded('items')
            ? $archive->items
            : SeoProjectArchiveItem::query()
                ->where('seo_project_archive_id', (int) $archive->getKey())
                ->get(['article_id', 'article_snapshot']);

        $articleIdsNeedingLookup = [];

        foreach ($items as $item) {
            if (! $item instanceof SeoProjectArchiveItem) {
                continue;
            }

            $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
            $fromSnapshot = (int) ($snapshot['site_id'] ?? 0);
            if ($fromSnapshot > 0) {
                $ids[$fromSnapshot] = $fromSnapshot;

                continue;
            }

            $articleId = (int) ($item->article_id ?? ($snapshot['article_id'] ?? 0));
            if ($articleId > 0) {
                $articleIdsNeedingLookup[$articleId] = $articleId;
            }
        }

        if ($articleIdsNeedingLookup !== []) {
            $articleSites = SeoArticle::query()
                ->whereIn('id', array_values($articleIdsNeedingLookup))
                ->pluck('site_id', 'id');
            foreach ($articleSites as $siteId) {
                $sid = (int) $siteId;
                if ($sid > 0) {
                    $ids[$sid] = $sid;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<int>  $siteIds
     */
    private function whereArchiveHasAccessibleItem(Builder $query, string $archivesTable, array $siteIds): void
    {
        $itemsTable = (new SeoProjectArchiveItem)->getTable();
        $articlesTable = (new SeoArticle)->getTable();
        $articlesSoftDelete = Schema::connection('omi_seo_ai')->hasColumn($articlesTable, 'deleted_at');

        $query->whereExists(function ($sub) use ($archivesTable, $itemsTable, $articlesTable, $siteIds, $articlesSoftDelete): void {
            $sub->select(DB::raw('1'))
                ->from("{$itemsTable} as cai")
                ->whereColumn('cai.seo_project_archive_id', "{$archivesTable}.id")
                ->where(function ($inner) use ($siteIds, $articlesTable, $articlesSoftDelete): void {
                    $inner
                        ->whereIn(
                            DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(cai.article_snapshot, '$.site_id')) AS UNSIGNED)"),
                            $siteIds,
                        )
                        ->orWhereExists(function ($articleSub) use ($siteIds, $articlesTable, $articlesSoftDelete): void {
                            $articleSub->select(DB::raw('1'))
                                ->from("{$articlesTable} as caa")
                                ->whereColumn('caa.id', 'cai.article_id')
                                ->whereIn('caa.site_id', $siteIds);
                            if ($articlesSoftDelete) {
                                $articleSub->whereNull('caa.deleted_at');
                            }
                        });
                });
        });
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function normalizeSiteIds(array $siteIds): array
    {
        $normalized = [];
        foreach ($siteIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $normalized[$intId] = $intId;
            }
        }

        return array_values($normalized);
    }
}
