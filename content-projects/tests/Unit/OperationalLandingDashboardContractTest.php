<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Widgets\OperationalLandingDashboardWidget;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\OperationalLandingDashboardReadModel;
use Omnichannel\Addons\SearchFoundation\Filament\Pages\Dashboard;
use Omnichannel\Addons\Seo\Filament\Widgets\AllDomainsListWidget;
use Omnichannel\Addons\Seo\Filament\Widgets\SeoScoreChart;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class OperationalLandingDashboardContractTest extends TestCase
{
    public function test_dashboard_widgets_only_operational_landing(): void
    {
        $method = new ReflectionMethod(Dashboard::class, 'getWidgets');
        $src = $this->methodSource(Dashboard::class, 'getWidgets');

        self::assertStringContainsString('OperationalLandingDashboardWidget::class', $src);
        self::assertStringNotContainsString('SeoScoreChart::class', $src);
        self::assertStringNotContainsString('AllDomainsListWidget::class', $src);
        self::assertStringNotContainsString('DashboardDomainArticlesChartWidget::class', $src);
        self::assertStringNotContainsString('DashboardWriterArticlesChartWidget::class', $src);
        self::assertTrue($method->getNumberOfParameters() === 0);
    }

    public function test_dashboard_no_longer_branches_on_all_domains_mode(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(Dashboard::class))->getFileName());

        self::assertStringNotContainsString('isAllDomainsDashboard', $src);
        self::assertStringNotContainsString('InteractsWithSeoAllDomainsDashboard', $src);
    }

    public function test_read_model_exposes_role_specific_entrypoints(): void
    {
        $class = new ReflectionClass(OperationalLandingDashboardReadModel::class);

        self::assertTrue($class->hasMethod('forEffectiveRole'));
        self::assertTrue($class->hasMethod('contentManager'));
        self::assertTrue($class->hasMethod('managerPlanner'));

        $src = (string) file_get_contents((string) $class->getFileName());
        self::assertStringContainsString('ROLE_CONTENT_MANAGER', $src);
        self::assertStringContainsString('PREVIEW_LIMIT', $src);
        self::assertStringContainsString('QUEUE_LIMIT', $src);
        self::assertStringContainsString('limit(self::PREVIEW_LIMIT)', $src);
        self::assertStringContainsString('limit(self::QUEUE_LIMIT)', $src);
    }

    public function test_content_manager_payload_keys_contract(): void
    {
        $src = $this->methodSource(OperationalLandingDashboardReadModel::class, 'contentManager');

        self::assertStringContainsString("'pending_review'", $src);
        self::assertStringContainsString("'reviewed'", $src);
        self::assertStringContainsString("'due_today'", $src);
        self::assertStringContainsString("'done_today'", $src);
        self::assertStringContainsString("'pending_rows'", $src);
        self::assertStringContainsString("'reviewed_rows'", $src);
        self::assertStringContainsString("'month_progress'", $src);
        self::assertStringNotContainsString('getScoreDistribution', $src);
        self::assertStringNotContainsString('domainsHealthOverview', $src);
    }

    public function test_manager_planner_payload_keys_contract(): void
    {
        $src = $this->methodSource(OperationalLandingDashboardReadModel::class, 'managerPlanner');

        self::assertStringContainsString("'needs_review'", $src);
        self::assertStringContainsString("'approved'", $src);
        self::assertStringContainsString("'scheduled_today'", $src);
        self::assertStringContainsString("'publish_errors'", $src);
        self::assertStringContainsString("'ai_running'", $src);
        self::assertStringContainsString("'attention'", $src);
        self::assertStringContainsString("'activity'", $src);
        self::assertStringContainsString("'publish_queue'", $src);
        self::assertStringNotContainsString('getScoreDistribution', $src);
        self::assertStringNotContainsString('forMonth()', $src);
    }

    public function test_landing_dashboard_ignores_global_site_scope(): void
    {
        $src = $this->methodSource(OperationalLandingDashboardReadModel::class, 'scopedSiteIds');

        self::assertStringContainsString('accessibleSiteIds', $src);
        self::assertStringNotContainsString('globalSiteId', $src);
    }

    public function test_widget_layout_uses_stable_css_classes(): void
    {
        $blade = dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'widgets'
            .DIRECTORY_SEPARATOR.'operational-landing-dashboard.blade.php';

        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringContainsString('ops-landing__kpi-grid--5', $bladeSrc);
        self::assertStringContainsString('ops-landing-attention', $bladeSrc);
        self::assertStringContainsString('ops-landing-timeline', $bladeSrc);
        self::assertStringContainsString('ops-dashboard-progress-card', $bladeSrc);
        self::assertStringNotContainsString('@vite(', $bladeSrc);
        self::assertStringNotContainsString('grid-template-columns:repeat(auto-fit', $bladeSrc);
        self::assertStringNotContainsString('content_project.', $bladeSrc);

        $provider = (string) file_get_contents(dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo'
            .DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.'SeoServiceProvider.php');
        self::assertStringContainsString('operational-landing-dashboard.css', $provider);
        self::assertStringContainsString('STYLES_AFTER', $provider);

        $progressCard = dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'widgets'
            .DIRECTORY_SEPARATOR.'partials'
            .DIRECTORY_SEPARATOR.'ops-dashboard-progress-card.blade.php';
        self::assertFileExists($progressCard);
        self::assertStringContainsString('ops-landing-progress', (string) file_get_contents($progressCard));
    }

    public function test_activity_uses_presentation_mapper(): void
    {
        $src = $this->methodSource(OperationalLandingDashboardReadModel::class, 'todayActivity');
        self::assertStringContainsString('OperationalLandingDashboardActivityPresenter', $src);
        self::assertStringNotContainsString("trim(\$action.(\$result !== '' ? ' · '.\$result : ''))", $src);
    }

    public function test_month_progress_exposes_coherent_rows_contract(): void
    {
        $src = $this->methodSource(OperationalLandingDashboardReadModel::class, 'monthProgressCounts');
        self::assertStringContainsString("'has_month_projects'", $src);
        self::assertStringContainsString("'rows'", $src);
        self::assertStringContainsString('whereYear', $src);
        self::assertStringContainsString('whereMonth', $src);
    }

    public function test_queue_preview_is_bounded(): void
    {
        $src = $this->methodSource(OperationalLandingDashboardReadModel::class, 'publishQueuePreview');
        self::assertStringContainsString('QUEUE_LIMIT', $src);
        self::assertStringContainsString('limit(self::QUEUE_LIMIT)', $src);
    }

    public function test_widget_view_and_access_gate(): void
    {
        $widgetSrc = (string) file_get_contents((string) (new ReflectionClass(OperationalLandingDashboardWidget::class))->getFileName());

        self::assertStringContainsString('operational-landing-dashboard', $widgetSrc);
        self::assertStringContainsString('canAccessContentFeatures', $widgetSrc);
        self::assertStringContainsString('forEffectiveRole', $widgetSrc);

        $blade = dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'widgets'
            .DIRECTORY_SEPARATOR.'operational-landing-dashboard.blade.php';

        self::assertFileExists($blade);
        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringContainsString("\$variant === 'content_manager'", $bladeSrc);
        self::assertStringContainsString('ops_pending_empty', $bladeSrc);
        self::assertStringContainsString('ops_reviewed_empty', $bladeSrc);
        self::assertStringContainsString('ops_attention_empty', $bladeSrc);
        self::assertStringContainsString('ops_queue_empty', $bladeSrc);
        self::assertStringNotContainsString('seo-score-chart', $bladeSrc);
    }

    public function test_design_ssot_exists(): void
    {
        $design = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'design.md';
        self::assertFileExists($design);
        $src = (string) file_get_contents($design);
        self::assertStringContainsString('Role-aware Dashboard', $src);
        self::assertStringContainsString('Statistics Layout Contract', $src);
        self::assertStringContainsString('Performance Rules', $src);
    }

    public function test_legacy_heavy_widgets_no_longer_referenced_from_dashboard(): void
    {
        self::assertTrue(class_exists(SeoScoreChart::class));
        self::assertTrue(class_exists(AllDomainsListWidget::class));
        $dashboard = (string) file_get_contents((string) (new ReflectionClass(Dashboard::class))->getFileName());
        self::assertStringNotContainsString(SeoScoreChart::class, $dashboard);
        self::assertStringNotContainsString(AllDomainsListWidget::class, $dashboard);
    }

    public function test_role_constants_align_with_access_control(): void
    {
        self::assertSame('content_manager', SeoAccessControl::ROLE_CONTENT_MANAGER);
        self::assertSame('planner', SeoAccessControl::ROLE_PLANNER);
        self::assertSame('manager', SeoAccessControl::ROLE_MANAGER);
    }

    private function methodSource(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $file = (string) $ref->getFileName();
        $start = (int) $ref->getStartLine();
        $end = (int) $ref->getEndLine();
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
