<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Manager Báo cáo — list/proof/approve + proof-first review UX contracts.
 */
final class SeedingManagerReportsContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function src(string $relative): string
    {
        return (string) file_get_contents($this->addonRoot().'/src/'.$relative);
    }

    private function js(string $relative): string
    {
        return (string) file_get_contents($this->addonRoot().'/resources/js/seeding/'.$relative);
    }

    public function test_provider_registers_manager_report_and_approve_routes(): void
    {
        $provider = $this->src('SeedingServiceProvider.php');
        self::assertStringContainsString('SeedingManagerReportsController', $provider);
        self::assertStringContainsString("'/manager/reports'", $provider);
        self::assertStringContainsString("'/manager/reports/{reportId}/proof'", $provider);
        self::assertStringContainsString("'/manager/reports/{reportId}/approve'", $provider);
        self::assertStringContainsString("->name('seeding.manager.reports')", $provider);
        self::assertStringContainsString("->name('seeding.manager.reports.proof')", $provider);
        self::assertStringContainsString("->name('seeding.manager.reports.approve')", $provider);
        self::assertStringNotContainsString("Route::get('/reports'", $provider);
    }

    public function test_controller_is_manager_gated_for_list_proof_approve(): void
    {
        $controller = $this->src('Http/Controllers/SeedingManagerReportsController.php');
        self::assertStringContainsString('assertCanManage', $controller);
        self::assertSame(3, substr_count($controller, 'assertCanManage'));
        self::assertStringContainsString('listForManager', $controller);
        self::assertStringContainsString('streamProofForManager', $controller);
        self::assertStringContainsString('approveForManager', $controller);
        self::assertStringContainsString('function approve', $controller);
        self::assertStringNotContainsString('submit(', $controller);
        self::assertStringNotContainsString('reject', strtolower($controller));
    }

    public function test_approval_migration_on_omi_seeding(): void
    {
        $path = $this->addonRoot().'/database/migrations/2026_09_25_100000_add_approval_to_seeding_reports.php';
        self::assertFileExists($path);
        $migration = (string) file_get_contents($path);
        self::assertStringContainsString("protected \$connection = 'omi_seeding'", $migration);
        self::assertStringContainsString('approved_at', $migration);
        self::assertStringContainsString('approved_by', $migration);
        self::assertStringContainsString('seeding_reports', $migration);
    }

    public function test_model_and_presenter_expose_approval_fields(): void
    {
        $model = $this->src('Models/SeedingReport.php');
        self::assertStringContainsString("'approved_at'", $model);
        self::assertStringContainsString("'approved_by'", $model);
        self::assertStringContainsString("'approved_at' => 'datetime'", $model);
        self::assertStringContainsString('function isApproved', $model);

        $presenter = $this->src('Support/SeedingTopicPresenter.php');
        self::assertStringContainsString("'approval_status'", $presenter);
        self::assertStringContainsString("'approval_status_label'", $presenter);
        self::assertStringContainsString("'is_approved'", $presenter);
        self::assertStringContainsString("'approved_at'", $presenter);
        self::assertStringContainsString("'approved_by'", $presenter);
        self::assertStringContainsString('Chờ duyệt', $presenter);
        self::assertStringContainsString('Đã duyệt', $presenter);
    }

    public function test_approve_service_is_idempotent_and_scoped(): void
    {
        $service = $this->src('Services/SeedingReportService.php');
        self::assertStringContainsString('function approveForManager', $service);
        self::assertStringContainsString('findForManager', $service);
        self::assertStringContainsString('approved_at === null', $service);
        self::assertStringContainsString('whereNull(\'approved_at\')', $service);
        self::assertStringContainsString('whereNotNull(\'approved_at\')', $service);
        // Must not touch Topic completion inside approve.
        $start = strpos($service, 'function approveForManager');
        self::assertNotFalse($start);
        $body = substr($service, $start, 1200);
        self::assertStringNotContainsString('completed_comments', $body);
        self::assertStringNotContainsString('targetComments', $body);
        self::assertStringNotContainsString('daily_link', $body);
    }

    public function test_report_service_scopes_through_topic_installation(): void
    {
        $service = $this->src('Services/SeedingReportService.php');
        self::assertStringContainsString('function listForManager', $service);
        self::assertStringContainsString('function findForManager', $service);
        self::assertStringContainsString('function streamProofForManager', $service);
        self::assertStringContainsString('managerScopedQuery', $service);
        self::assertStringContainsString('whereHas(\'topic\'', $service);
        self::assertStringContainsString('forInstallation', $service);
        self::assertStringContainsString('orderByDesc(\'reported_at\')', $service);
        self::assertStringContainsString('orderByDesc(\'id\')', $service);
        self::assertStringContainsString('safeLocalProofPath', $service);
        self::assertStringContainsString('seeding/proofs/', $service);
        self::assertStringContainsString("str_contains(\$path, '..')", $service);
        self::assertStringContainsString("Storage::disk('local')", $service);
        self::assertStringContainsString('function submit', $service);
    }

    public function test_presenter_manager_report_exposes_ui_context_without_filesystem_path(): void
    {
        $presenter = $this->src('Support/SeedingTopicPresenter.php');
        self::assertStringContainsString('function managerReport', $presenter);
        self::assertStringContainsString("'reported_at_label'", $presenter);
        self::assertStringContainsString("'proof_url'", $presenter);
        self::assertStringContainsString('/api/seeding/manager/reports/', $presenter);
        $methodStart = strpos($presenter, 'function managerReport');
        self::assertNotFalse($methodStart);
        $methodBody = substr($presenter, $methodStart, 3200);
        self::assertStringNotContainsString("'proof_path'", $methodBody);
    }

    public function test_safe_local_proof_path_rejects_traversal_and_arbitrary_paths(): void
    {
        $serviceClass = \Omnichannel\Addons\Seeding\Services\SeedingReportService::class;
        $method = new ReflectionMethod($serviceClass, 'safeLocalProofPath');
        $method->setAccessible(true);

        $ref = new \ReflectionClass($serviceClass);
        /** @var object $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        self::assertSame(
            'seeding/proofs/2/abc.png',
            $method->invoke($instance, 'seeding/proofs/2/abc.png')
        );
        self::assertNull($method->invoke($instance, '../seeding/proofs/2/x.png'));
        self::assertNull($method->invoke($instance, 'seeding/proofs/../../.env'));
        self::assertNull($method->invoke($instance, '/etc/passwd'));
        self::assertNull($method->invoke($instance, 'C:/Windows/system32'));
        self::assertNull($method->invoke($instance, 'tmp/evil.png'));
        self::assertNull($method->invoke($instance, ''));
        self::assertNull($method->invoke($instance, null));
    }

    public function test_api_js_exposes_fetch_and_approve_manager_reports(): void
    {
        $api = $this->js('api.js');
        self::assertStringContainsString('fetchManagerReports', $api);
        self::assertStringContainsString('approveManagerReport', $api);
        self::assertStringContainsString('/api/seeding/manager/reports', $api);
        self::assertStringContainsString('/approve', $api);
        self::assertStringContainsString('managerReportProofUrl', $api);
    }

    public function test_manager_panel_reports_tab_and_review_modal_contracts(): void
    {
        $panel = $this->js('components/ManagerPanel.jsx');
        $modal = $this->js('components/ReportReviewModal.jsx');
        $css = (string) file_get_contents($this->addonRoot().'/resources/css/seeding-workspace.css');

        self::assertStringContainsString("subTab === 'reports'", $panel);
        self::assertStringContainsString('data-section="manager-reports"', $panel);
        self::assertStringContainsString('data-table="manager-reports"', $panel);
        self::assertStringContainsString('fetchManagerReports', $panel);
        self::assertStringContainsString('ReportReviewModal', $panel);
        self::assertStringContainsString('reportApprovalStatus', $panel);
        self::assertStringContainsString('Chờ duyệt', $panel);
        self::assertStringContainsString('Đã duyệt', $panel);
        self::assertStringContainsString('REPORT_APPROVAL_FILTERS', $panel);
        self::assertStringNotContainsString(
            'Bảng báo cáo sẽ mở rộng từ seeding_reports',
            $panel
        );
        self::assertStringContainsString('data-section="manager-members-placeholder"', $panel);
        self::assertStringNotContainsString('daily_link_progress', $panel);

        self::assertStringContainsString('data-layout="proof-first"', $modal);
        self::assertStringContainsString('data-pane="proof"', $modal);
        self::assertStringContainsString('data-pane="meta"', $modal);
        self::assertStringContainsString('data-nav="prev"', $modal);
        self::assertStringContainsString('data-nav="next"', $modal);
        self::assertStringContainsString('data-nav="index"', $modal);
        self::assertStringContainsString('data-nav="group"', $modal);
        self::assertStringContainsString('data-action="approve"', $modal);
        self::assertStringContainsString('ArrowLeft', $modal);
        self::assertStringContainsString('ArrowRight', $modal);
        self::assertStringContainsString('new Image()', $modal);
        self::assertStringContainsString('approveManagerReport', $modal);
        self::assertStringContainsString('goNextPending', $modal);

        self::assertStringContainsString('seeding-ws__modal--report-review', $css);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(240px, 22vw)', $css);
        self::assertStringContainsString('width: calc(100vw - 0.8rem)', $css);
        self::assertStringContainsString('object-fit: contain', $css);
    }

    public function test_submission_controller_untouched_for_manager_list(): void
    {
        $store = $this->src('Http/Controllers/SeedingReportController.php');
        self::assertStringContainsString('assertCanMutate', $store);
        self::assertStringContainsString('function __invoke', $store);
        self::assertStringNotContainsString('listForManager', $store);
        self::assertStringNotContainsString('assertCanManage', $store);
        self::assertStringNotContainsString('approveForManager', $store);
    }
}
