<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectOperationsCenter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectOperationLogger;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectAiCostAggregateService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectAuditSearchService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectCommandBusMonitorService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectDailyReportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectErrorCenterService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectMetricKeys;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsDashboardService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsHealthService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsReplayService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectPublishAnalyticsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectSiteHealthService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectWpAdapterMetricsService;
use App\Filament\Pages\ContentOperationsRedirect;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectOperationsCenterTest extends TestCase
{
    public function test_metric_keys_are_prometheus_ready(): void
    {
        $keys = ContentProjectMetricKeys::all();

        self::assertContains(ContentProjectMetricKeys::AI_GENERATE_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::PUBLISH_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::PUBLISH_RETRY_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::ARCHIVE_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::RESTORE_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::WORKSPACE_DESTROY_TOTAL, $keys);
        self::assertContains(ContentProjectMetricKeys::QUEUE_WAIT_SECONDS, $keys);
        self::assertContains(ContentProjectMetricKeys::PUBLISH_DURATION_MS, $keys);
    }

    public function test_operations_center_page_is_manager_gated_and_read_only_surface(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectOperationsCenter::class))->getFileName(),
        );

        self::assertStringContainsString("slug = 'content-operations'", $source);
        self::assertStringContainsString('canAccessContentOperations', $source);
        self::assertStringContainsString('ContentProjectOpsReplayService', $source);
        self::assertStringContainsString('ContentProjectCommandBusMonitorService', $source);
        self::assertStringContainsString("'site_sync'", $source);
        self::assertStringContainsString('loadSiteSync', $source);
        self::assertStringContainsString('McpCapabilityMarkdownPresenter', $source);
        self::assertStringContainsString('loadMcpCapabilityDoc', $source);
        self::assertStringNotContainsString('SeoProjectRun::', $source);

        $siteSyncOps = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\SiteSync\Filament\Pages\SiteSyncOperationsCenter::class))->getFileName(),
        );
        self::assertStringContainsString('shouldRegisterNavigation', $siteSyncOps);
        self::assertStringContainsString('return false;', $siteSyncOps);
    }

    public function test_operation_center_view_contains_site_sync_tab_and_sections(): void
    {
        $viewPath = dirname((new ReflectionClass(ContentProjectOperationsCenter::class))->getFileName(), 3)
            .'/resources/views/filament/pages/content-project-operations-center.blade.php';
        $view = (string) file_get_contents($viewPath);

        self::assertStringContainsString("tab === 'site_sync'", $view);
        self::assertStringContainsString('Site Sync', $view);
        self::assertStringContainsString('Recent runs', $view);
        self::assertStringContainsString('Inbound events', $view);
        self::assertStringContainsString('Diagnostics', $view);
    }

    public function test_site_sync_run_actions_are_status_aware(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectOperationsCenter::class))->getFileName(),
        );
        $viewPath = dirname((new ReflectionClass(ContentProjectOperationsCenter::class))->getFileName(), 3)
            .'/resources/views/filament/pages/content-project-operations-center.blade.php';
        $view = (string) file_get_contents($viewPath);

        self::assertStringContainsString("'show_report'", $source);
        self::assertStringContainsString("'show_resume'", $source);
        self::assertStringContainsString("'show_cancel'", $source);
        self::assertStringContainsString("'show_restart'", $source);
        self::assertStringContainsString("'completed', 'completed_with_warnings'", $source);
        self::assertStringContainsString("'failed', 'paused'", $source);
        self::assertStringContainsString("'pending', 'running'", $source);
        self::assertStringContainsString("'canceled', 'cancelled', 'superseded'", $source);

        self::assertStringContainsString("@if (\$run['show_report'])", $view);
        self::assertStringContainsString("@if (\$run['show_resume'])", $view);
        self::assertStringContainsString("@if (\$run['show_cancel'])", $view);
        self::assertStringContainsString("@if (\$run['show_restart'])", $view);
    }

    public function test_replay_service_uses_command_bus_only(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectOpsReplayService::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectCommandBus', $source);
        self::assertStringContainsString('commandBus->dispatch', $source);
        self::assertStringNotContainsString('ContentPublisher', $source);
        self::assertStringNotContainsString('WordPressContentPublisher', $source);
    }

    public function test_ai_cost_aggregate_does_not_select_prompt_text(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAiCostAggregateService::class))->getFileName(),
        );

        self::assertStringContainsString('token_usage', $source);
        self::assertStringNotContainsString('prompt_text', $source);
        self::assertStringNotContainsString('output_text', $source);
        self::assertStringNotContainsString('response_text', $source);
    }

    public function test_audit_search_does_not_read_prompt_or_output(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAuditSearchService::class))->getFileName(),
        );

        self::assertStringContainsString('seo_content_project_business_audits', $source);
        self::assertStringNotContainsString('prompt_results', $source);
        self::assertStringNotContainsString('prompt_text', $source);
        self::assertStringNotContainsString('output_text', $source);
    }

    public function test_ops_services_exist_for_all_surfaces(): void
    {
        foreach ([
            ContentProjectOpsDashboardService::class,
            ContentProjectCommandBusMonitorService::class,
            ContentProjectPublishAnalyticsService::class,
            ContentProjectWpAdapterMetricsService::class,
            ContentProjectErrorCenterService::class,
            ContentProjectOpsHealthService::class,
            ContentProjectSiteHealthService::class,
            ContentProjectDailyReportService::class,
            ContentProjectOperationLogger::class,
            ContentOperationsRedirect::class,
        ] as $class) {
            self::assertTrue(class_exists($class), $class.' missing');
        }
    }

    public function test_admin_redirect_slug_is_content_operations(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentOperationsRedirect::class))->getFileName(),
        );

        self::assertStringContainsString("slug = 'content-operations'", $source);
        self::assertStringContainsString('ContentProjectOperationsCenter', $source);
    }

    public function test_operations_doc_is_referenced_from_ops_surface(): void
    {
        // Remote hosts often sync PHP only — do not require docs/ on disk.
        $pageSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectOperationsCenter::class))->getFileName(),
        );
        self::assertStringContainsString('OPERATIONS_AND_OBSERVABILITY.md', $pageSource);

        $keysSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectMetricKeys::class))->getFileName(),
        );
        self::assertStringContainsString('ai_generate_total', $keysSource);
        self::assertStringContainsString('publish_total', $keysSource);

        $docPath = null;
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $candidate = $dir.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'OPERATIONS_AND_OBSERVABILITY.md';
            if (is_file($candidate)) {
                $docPath = $candidate;
                break;
            }
            $dir = dirname($dir);
        }

        if ($docPath === null) {
            self::markTestSkipped('docs/modules/OPERATIONS_AND_OBSERVABILITY.md not present on this host');
        }

        $body = (string) file_get_contents($docPath);
        self::assertStringContainsString('Operation Center', $body);
        self::assertStringContainsString('Replay', $body);
        self::assertStringContainsString('ai_generate_total', $body);
    }
}
