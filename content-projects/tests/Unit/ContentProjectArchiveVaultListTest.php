<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchiveVaultListPresenter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Archived Projects Vault — compact Total (= completed) + Index summary + collapsible filters.
 */
final class ContentProjectArchiveVaultListTest extends TestCase
{
    public function test_list_total_uses_completed_not_raw_total_articles(): void
    {
        $archive = new SeoProjectArchive([
            'total_articles' => 32,
            'articles_count' => 32,
            'completed_articles' => 31,
            'approved_articles' => 1,
            'synced_articles' => 32,
        ]);

        self::assertSame(31, ContentProjectArchiveVaultListPresenter::listTotal($archive));
        self::assertNotSame(
            (int) ($archive->total_articles ?? 0),
            ContentProjectArchiveVaultListPresenter::listTotal($archive),
        );
    }

    public function test_index_summary_ratio_and_reindex_from_aggregate_attributes(): void
    {
        $archive = new SeoProjectArchive([
            'completed_articles' => 31,
        ]);
        $archive->setAttribute('indexed_articles_count', 18);
        $archive->setAttribute('reindexed_articles_count', 6);
        $archive->setAttribute('latest_indexed_at', '2026-08-08 15:30:00');

        $summary = ContentProjectArchiveVaultListPresenter::indexSummary($archive);

        self::assertSame(31, $summary['total']);
        self::assertSame(18, $summary['indexed_count']);
        self::assertSame(6, $summary['reindexed_count']);
        self::assertTrue($summary['has_indexed']);
        self::assertSame('08/08/2026', $summary['latest_indexed_at_label']);
    }

    public function test_index_summary_without_indexed_articles_has_no_fake_latest_date(): void
    {
        $archive = new SeoProjectArchive([
            'completed_articles' => 31,
        ]);
        $archive->setAttribute('indexed_articles_count', 0);
        $archive->setAttribute('reindexed_articles_count', 0);
        $archive->setAttribute('latest_indexed_at', null);

        $summary = ContentProjectArchiveVaultListPresenter::indexSummary($archive);

        self::assertSame(31, $summary['total']);
        self::assertSame(0, $summary['indexed_count']);
        self::assertFalse($summary['has_indexed']);
        self::assertNull($summary['latest_indexed_at_label']);
    }

    public function test_latest_indexed_at_label_uses_format_index_date_max_value(): void
    {
        $latest = Carbon::parse('2026-08-15 09:00:00');
        $label = ArchivePreviewArticlePresenter::formatIndexDate($latest);

        self::assertSame('15/08/2026', $label);

        $archive = new SeoProjectArchive(['completed_articles' => 10]);
        $archive->setAttribute('indexed_articles_count', 2);
        $archive->setAttribute('reindexed_articles_count', 0);
        $archive->setAttribute('latest_indexed_at', $latest->toDateTimeString());

        $summary = ContentProjectArchiveVaultListPresenter::indexSummary($archive);
        self::assertSame('15/08/2026', $summary['latest_indexed_at_label']);
    }

    public function test_apply_index_summary_aggregates_joins_archive_items_to_articles(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchiveVaultListPresenter::class))->getFileName(),
        );

        self::assertStringContainsString('selectSub', $source);
        self::assertStringContainsString('indexed_articles_count', $source);
        self::assertStringContainsString('reindexed_articles_count', $source);
        self::assertStringContainsString('latest_indexed_at', $source);
        self::assertStringContainsString('seo_project_archive_id', $source);
        self::assertStringContainsString('seo_article_profiles', $source);
        self::assertStringContainsString('indexed_at', $source);
        self::assertStringContainsString('previous_indexed_at', $source);
        self::assertStringContainsString('archiveArticlesJoinSubquery', $source);
        self::assertStringContainsString('COUNT(DISTINCT', $source);
        self::assertStringContainsString('deleted_at', $source);
        self::assertStringNotContainsString('articles.indexed_at', $source);
        self::assertStringNotContainsString('articles.previous_indexed_at', $source);
        self::assertStringNotContainsString("where('title'", $source);
    }

    public function test_vault_query_keeps_filter_wiring_after_collapse_ui(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());

        self::assertStringContainsString('whereIn(\'site_id\'', $source);
        self::assertStringContainsString('where(\'site_id\'', $source);
        self::assertStringContainsString('where(\'project_month\'', $source);
        self::assertStringContainsString('where(\'project_year\'', $source);
        self::assertStringContainsString('where(\'owner_id\'', $source);
        self::assertStringContainsString('where(\'archived_by\'', $source);
        self::assertStringContainsString('applySearch', $source);
        self::assertStringContainsString('searchInput', $source);
        self::assertStringContainsString('updatedSiteFilter', $source);
        self::assertStringContainsString('updatedMonthFilter', $source);
        self::assertStringContainsString('updatedYearFilter', $source);
        self::assertStringContainsString('updatedOwnerFilter', $source);
        self::assertStringContainsString('updatedArchivedByFilter', $source);
        self::assertStringContainsString('resetPage()', $source);
    }

    public function test_active_filter_count_excludes_search_and_defaults(): void
    {
        self::assertSame(0, ContentProjectArchiveVaultListPresenter::activeFilterCount(
            '',
            '',
            '',
            '',
            '',
            true,
        ));

        self::assertSame(2, ContentProjectArchiveVaultListPresenter::activeFilterCount(
            '5',
            '8',
            '',
            '',
            '',
            true,
        ));

        self::assertSame(0, ContentProjectArchiveVaultListPresenter::activeFilterCount(
            '5',
            '',
            '',
            '',
            '',
            false,
        ));

        self::assertSame(3, ContentProjectArchiveVaultListPresenter::activeFilterCount(
            '',
            '3',
            '2026',
            '9',
            '',
            true,
        ));
    }

    public function test_vault_page_wires_aggregates_filters_and_restore(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());

        self::assertStringContainsString('ContentProjectArchiveVaultListPresenter', $source);
        self::assertStringContainsString('applyIndexSummaryAggregates', $source);
        self::assertStringContainsString('clearFilters', $source);
        self::assertStringContainsString('getActiveFilterCount', $source);
        self::assertStringContainsString('restoreArchive', $source);
        self::assertStringContainsString('exportArchive', $source);
        self::assertStringContainsString('exportMonth', $source);

        $viewPath = LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php');
        $view = (string) file_get_contents($viewPath);

        self::assertStringContainsString('filtersOpen: false', $view);
        self::assertStringContainsString('archive_filters', $view);
        self::assertStringContainsString('archive_col_index', $view);
        self::assertStringContainsString('listTotal', $view);
        self::assertStringContainsString('indexSummary', $view);
        self::assertStringContainsString('clearFilters', $view);
        self::assertStringContainsString('restoreArchive', $view);
        self::assertStringContainsString('exportMonth', $view);
        self::assertStringContainsString('archive_export_month', $view);
        self::assertStringNotContainsString('$archive->site?->domain', $view);
        self::assertStringNotContainsString('archive_col_completed', $view);
        self::assertStringNotContainsString('archive_col_approved', $view);
        self::assertStringNotContainsString('archive_col_synced', $view);
        self::assertStringNotContainsString('archive_col_avg_seo', $view);
        self::assertStringNotContainsString('total_articles ?? $archive->articles_count', $view);
    }

    public function test_preview_and_excel_still_expose_article_index_timestamps(): void
    {
        $previewSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchivePreview::class))->getFileName(),
        );
        self::assertStringContainsString('markArticleIndexed', $previewSource);
        self::assertStringContainsString('ArchivePreviewArticlePresenter', $previewSource);

        $presenterSource = (string) file_get_contents(
            (new ReflectionClass(ArchivePreviewArticlePresenter::class))->getFileName(),
        );
        self::assertStringContainsString('indexed_at_label', $presenterSource);
        self::assertStringContainsString('previous_indexed_at_label', $presenterSource);

        $exportSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArchiveExportService::class))->getFileName(),
        );
        self::assertStringContainsString("'indexed_at' => 'Index gần nhất'", $exportSource);
        self::assertStringContainsString("'previous_indexed_at' => 'Index lần trước'", $exportSource);
        self::assertStringContainsString("'domain' => 'Domain'", $exportSource);
    }
}
