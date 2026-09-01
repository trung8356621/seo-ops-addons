<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Widgets\AllDomainsProjectsWidget;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\Concerns\ResolvesContentProjectMonthDashboardCharts;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\DashboardDomainArticlesChartWidget;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\DashboardWriterArticlesChartWidget;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthChartPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\Dashboard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DashboardKeywordOverviewService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Omnichannel\Addons\Seo\Filament\Widgets\AllDomainsListWidget;
use Omnichannel\Addons\Seo\Filament\Widgets\AllDomainsTeamWidget;
use Omnichannel\Addons\Seo\Filament\Widgets\KeywordOverviewWidget;
use Omnichannel\Addons\Seo\Filament\Widgets\SeoOverviewStats;
use Omnichannel\Addons\Seo\Filament\Widgets\SeoScoreChart;
use Omnichannel\Addons\WordPress\Filament\Widgets\WpPluginReleaseWidget;
use Omnichannel\Addons\WordPress\Filament\Widgets\WpSyncStatusTable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class SeoWorkspaceDashboardContractTest extends TestCase
{
    public function test_single_domain_dashboard_widget_registration(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(Dashboard::class))->getFileName(),
        );

        self::assertStringNotContainsString('SeoOverviewStats::class', $src);
        self::assertStringContainsString('KeywordOverviewWidget::class', $src);
        self::assertStringContainsString('SeoScoreChart::class', $src);
        self::assertStringContainsString('WpSyncStatusTable::class', $src);
        self::assertStringNotContainsString('stat_ai_queue', $src);
    }

    public function test_all_domains_dashboard_replaces_legacy_project_widgets_with_month_charts(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(Dashboard::class))->getFileName(),
        );

        self::assertStringNotContainsString('AllDomainsProjectsWidget::class', $src);
        self::assertStringNotContainsString('AllDomainsTeamWidget::class', $src);
        self::assertStringContainsString('DashboardDomainArticlesChartWidget::class', $src);
        self::assertStringContainsString('DashboardWriterArticlesChartWidget::class', $src);
        self::assertStringContainsString('AllDomainsListWidget::class', $src);
        self::assertStringContainsString('WpPluginReleaseWidget::class', $src);
    }

    public function test_legacy_all_domains_widget_classes_remain_available(): void
    {
        self::assertTrue(class_exists(AllDomainsProjectsWidget::class));
        self::assertTrue(class_exists(AllDomainsTeamWidget::class));
        self::assertTrue(class_exists(SeoOverviewStats::class));
    }

    public function test_dashboard_month_charts_reuse_content_project_shared_stack(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(ResolvesContentProjectMonthDashboardCharts::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectMonthlyWorkloadService', $trait);
        self::assertStringContainsString('ContentProjectMonthChartPresenter', $trait);
        self::assertStringContainsString('ContentProjectMonthContext::current()', $trait);
        self::assertStringContainsString('presentDomain', $trait);
        self::assertStringContainsString('presentWriter', $trait);

        $domainWidget = (string) file_get_contents(
            (string) (new ReflectionClass(DashboardDomainArticlesChartWidget::class))->getFileName(),
        );
        $writerWidget = (string) file_get_contents(
            (string) (new ReflectionClass(DashboardWriterArticlesChartWidget::class))->getFileName(),
        );

        self::assertStringContainsString('ResolvesContentProjectMonthDashboardCharts', $domainWidget);
        self::assertStringContainsString('ResolvesContentProjectMonthDashboardCharts', $writerWidget);
        self::assertStringContainsString('content-project-month-charts', LegacyAddonPath::read(
            'resources/views/filament/widgets/dashboard-domain-articles-chart.blade.php',
        ));
        self::assertStringContainsString('content-project-month-charts', LegacyAddonPath::read(
            'resources/views/filament/widgets/dashboard-writer-articles-chart.blade.php',
        ));
        self::assertStringContainsString("variant=\"domain\"", LegacyAddonPath::read(
            'resources/views/filament/widgets/dashboard-domain-articles-chart.blade.php',
        ));
        self::assertStringContainsString("variant=\"writer\"", LegacyAddonPath::read(
            'resources/views/filament/widgets/dashboard-writer-articles-chart.blade.php',
        ));
    }

    public function test_content_project_chart_component_unchanged_for_list_and_archive(): void
    {
        $listBlade = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        );
        $archiveBlade = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php',
        );
        $charts = LegacyAddonPath::read(
            'resources/views/components/content-project-month-charts.blade.php',
        );

        self::assertStringContainsString('content-project-month-charts', $listBlade);
        self::assertStringContainsString('content-project-month-charts', $archiveBlade);
        self::assertStringContainsString("'variant' => 'both'", $charts);
        self::assertStringContainsString('chart_articles_by_domain', $charts);
        self::assertStringContainsString('chart_articles_by_writer', $charts);
    }

    public function test_domain_workload_still_includes_all_accessible_sites(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('SeoAccessControl::accessibleSitesQuery()', $src);
        self::assertStringContainsString("'total_count' => 0", $src);
    }

    public function test_keyword_overview_service_uses_canonical_inventory_and_link_sorting(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DashboardKeywordOverviewService::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordUiInventoryQuery', $src);
        self::assertStringContainsString('KeywordClusterQuery', $src);
        self::assertStringContainsString("'sort' => 'links_desc'", $src);
        self::assertStringContainsString('SeoLinkMapType::Internal', $src);
        self::assertStringContainsString('->count($siteId', $src);
        self::assertStringNotContainsString('Keyword::query()->forSite', $src);
    }

    public function test_sync_widget_includes_total_synced_content_row(): void
    {
        $blade = LegacyAddonPath::read(
            'resources/views/filament/widgets/wp-sync-status-table.blade.php',
        );

        self::assertStringContainsString('sync_total', $blade);
        self::assertStringContainsString("\$sync['total']", $blade);
    }

    public function test_seo_score_chart_still_exposes_average_score(): void
    {
        $widget = (string) file_get_contents(
            (string) (new ReflectionClass(SeoScoreChart::class))->getFileName(),
        );
        $blade = LegacyAddonPath::read(
            'resources/views/filament/widgets/seo-score-chart.blade.php',
        );

        self::assertStringContainsString('getScoringStatistics', $widget);
        self::assertStringContainsString("'scoring' => \$scoring", $widget);
        self::assertStringContainsString('seo-score-donut-block', $blade);
    }

    public function test_keyword_overview_widget_is_site_scoped(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordOverviewWidget::class))->getFileName(),
        );

        self::assertStringContainsString('InteractsWithSeoDashboardSite', $src);
        self::assertStringContainsString('DashboardKeywordOverviewService', $src);
        self::assertStringContainsString('resolveDashboardSiteId', $src);
    }
}
