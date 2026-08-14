<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Archived Projects Vault list presentation helpers.
 *
 * Vault "Total" uses archived completed_articles (not raw item/total_articles).
 * Index summary aggregates live seo_article_profiles timestamps via historical archive items.
 *
 * @phpstan-type IndexSummary array{
 *     total: int,
 *     indexed_count: int,
 *     reindexed_count: int,
 *     latest_indexed_at: mixed,
 *     latest_indexed_at_label: string|null,
 *     has_indexed: bool,
 * }
 */
final class ContentProjectArchiveVaultListPresenter
{
    /**
     * Vault list Total === archived completed metric (old "Completed" column).
     */
    public static function listTotal(SeoProjectArchive $archive): int
    {
        return max(0, (int) ($archive->completed_articles ?? 0));
    }

    /**
     * Attach Index aggregates without loading archive items/articles into PHP.
     *
     * @param  Builder<SeoProjectArchive>  $query
     * @return Builder<SeoProjectArchive>
     */
    public static function applyIndexSummaryAggregates(Builder $query): Builder
    {
        $archivesTable = $query->getModel()->getTable();
        $itemsTable = (new SeoProjectArchiveItem)->getTable();
        $articlesTable = (new SeoArticle)->getTable();
        // indexed_at lives on seo_article_profiles (dropped from articles).
        $profilesTable = 'seo_article_profiles';

        $query->select("{$archivesTable}.*");

        $query->selectSub(
            self::archiveArticlesJoinSubquery($itemsTable, $articlesTable, $archivesTable, $profilesTable)
                ->selectRaw("COUNT(DISTINCT {$articlesTable}.id)")
                ->whereNotNull("{$profilesTable}.indexed_at"),
            'indexed_articles_count',
        );

        $query->selectSub(
            self::archiveArticlesJoinSubquery($itemsTable, $articlesTable, $archivesTable, $profilesTable)
                ->selectRaw("COUNT(DISTINCT {$articlesTable}.id)")
                ->whereNotNull("{$profilesTable}.previous_indexed_at"),
            'reindexed_articles_count',
        );

        $query->selectSub(
            self::archiveArticlesJoinSubquery($itemsTable, $articlesTable, $archivesTable, $profilesTable)
                ->selectRaw("MAX({$profilesTable}.indexed_at)")
                ->whereNotNull("{$profilesTable}.indexed_at"),
            'latest_indexed_at',
        );

        return $query;
    }

    /**
     * @return IndexSummary
     */
    public static function indexSummary(SeoProjectArchive $archive): array
    {
        $total = self::listTotal($archive);
        $indexedCount = max(0, (int) ($archive->getAttribute('indexed_articles_count') ?? 0));
        $reindexedCount = max(0, (int) ($archive->getAttribute('reindexed_articles_count') ?? 0));
        $latestRaw = $archive->getAttribute('latest_indexed_at');
        $latestLabel = ArchivePreviewArticlePresenter::formatIndexDate($latestRaw);

        return [
            'total' => $total,
            'indexed_count' => $indexedCount,
            'reindexed_count' => $reindexedCount,
            'latest_indexed_at' => $latestRaw,
            'latest_indexed_at_label' => $latestLabel,
            'has_indexed' => $indexedCount > 0,
        ];
    }

    /**
     * Active detailed filters only (search excluded).
     */
    public static function activeFilterCount(
        string $siteFilter,
        string $monthFilter,
        string $yearFilter,
        string $ownerFilter,
        string $archivedByFilter,
        bool $siteFilterAvailable = true,
    ): int {
        $count = 0;

        if ($siteFilterAvailable && $siteFilter !== '' && (int) $siteFilter > 0) {
            $count++;
        }

        if ($monthFilter !== '' && (int) $monthFilter > 0) {
            $count++;
        }

        if ($yearFilter !== '' && (int) $yearFilter > 0) {
            $count++;
        }

        if ($ownerFilter !== '' && (int) $ownerFilter > 0) {
            $count++;
        }

        if ($archivedByFilter !== '' && (int) $archivedByFilter > 0) {
            $count++;
        }

        return $count;
    }

    private static function archiveArticlesJoinSubquery(
        string $itemsTable,
        string $articlesTable,
        string $archivesTable,
        string $profilesTable,
    ): QueryBuilder {
        return SeoProjectArchiveItem::query()
            ->getQuery()
            ->from($itemsTable)
            ->join($articlesTable, "{$articlesTable}.id", '=', "{$itemsTable}.article_id")
            ->leftJoin($profilesTable, "{$profilesTable}.article_id", '=', "{$articlesTable}.id")
            ->whereColumn("{$itemsTable}.seo_project_archive_id", "{$archivesTable}.id")
            ->whereNotNull("{$itemsTable}.article_id")
            // Raw join bypasses SeoArticle SoftDeletes; keep parity with preview article load.
            ->whereNull("{$articlesTable}.deleted_at");
    }
}
