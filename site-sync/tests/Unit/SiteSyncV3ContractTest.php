<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncV3Job;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Progress\SiteSyncStepCatalog;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncV3ContractTest extends TestCase
{
    public function test_protocol_constants(): void
    {
        self::assertSame('site_sync.v3', SiteSyncV3Schema::VERSION);
        self::assertSame(3, SiteSyncV3Schema::PROTOCOL);
        self::assertSame([
            'discover',
            'import',
            'reconcile_stale',
            'catch_up',
            'verify',
            'complete',
            'needs_attention',
        ], SiteSyncV3Schema::PHASES);
    }

    public function test_v3_importer_never_writes_body_or_wp_post_content(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );

        self::assertStringContainsString('FORBIDDEN_BODY_KEYS', $src);
        self::assertStringContainsString('stripForbiddenBodyKeys', $src);
        self::assertStringContainsString("'body' => null", $src);
        self::assertStringNotContainsString('->update([\'body\'', $src);
        self::assertStringNotContainsString("forceFill(['body'", $src);
        self::assertStringNotContainsString('SyncDomainContentService', $src);
        self::assertStringContainsString("'wp_post_content'", $src); // delete forbidden meta
        self::assertStringContainsString('Do not touch body', $src);
    }

    public function test_v3_importer_extracts_focus_keywords_array(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );

        self::assertStringContainsString("focus_keywords", $src);
        self::assertStringContainsString("\$seo['focus_keywords']", $src);
        self::assertStringContainsString('provider_score', $src);
        self::assertStringContainsString('array_key_exists(\'links\'', $src);
        self::assertStringContainsString('SeoLinkMap', $src);
        self::assertStringContainsString('LINK_SYNC_MARKER', $src);
    }

    public function test_v3_orchestrator_does_not_replay_batches_or_keyword_step(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringNotContainsString('seo_site_sync_batches', $src);
        self::assertStringNotContainsString('SeoSiteSyncBatch', $src);
        self::assertStringNotContainsString('sync_provider_keywords', $src);
        self::assertStringNotContainsString('SiteSyncBatchReconciler', $src);
        self::assertStringContainsString('SiteSyncV3BulkImporter', $src);
        self::assertStringContainsString('ProcessSiteSyncV3Job', $src);
    }

    public function test_v3_orchestrator_phase_methods_exist_without_stubs(): void
    {
        $ref = new ReflectionClass(RunSiteSyncV3Orchestrator::class);
        $src = (string) file_get_contents((string) $ref->getFileName());

        self::assertTrue($ref->hasMethod('phaseReconcileStale'));
        self::assertTrue($ref->hasMethod('phaseCatchUp'));
        self::assertTrue($ref->hasMethod('phaseVerify'));
        self::assertStringContainsString('phaseReconcileStale', $src);
        self::assertStringContainsString('phaseCatchUp', $src);
        self::assertStringContainsString('phaseVerify', $src);
        self::assertStringNotContainsString('phaseStub', $src);
        self::assertStringNotContainsString('Phase C stub', $src);
        self::assertStringNotContainsString('catch-up/verify not implemented yet', $src);
    }

    public function test_v3_discover_persists_snapshot_at(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString("\$meta['snapshot_at']", $src);
        self::assertStringContainsString("\$discover['snapshot_at']", $src);
        self::assertStringContainsString("\$meta['initial_expected_total']", $src);
        self::assertStringContainsString("\$meta['initial_expected_by_type']", $src);
        self::assertStringContainsString("\$meta['site_revision']", $src);
        self::assertStringContainsString("\$meta['snapshot_bounds']", $src);
        self::assertStringContainsString("\$meta['snapshot_content_max_id']", $src);
    }

    public function test_v3_import_guards_sync_cursor_not_advancing(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString('sync_cursor_not_advancing', $src);
        self::assertStringContainsString('cursorsEqual', $src);
        self::assertStringContainsString("'snapshot_at'", $src);
    }

    public function test_v3_client_uses_keyset_cursor_not_offset(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(WordPressSiteSyncV3Client::class))->getFileName()
        );

        self::assertStringContainsString('/omi-seo-ai/v1/sync/v3/discover', $src);
        self::assertStringContainsString('/omi-seo-ai/v1/sync/v3/records', $src);
        self::assertStringContainsString("unset(\$body['offset'])", $src);
        self::assertStringNotContainsString("'offset' =>", $src);
    }

    public function test_v3_import_phase_posts_keyset_cursor(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString("'cursor' => \$cursor", $src);
        self::assertStringContainsString('never send offset', $src);
        self::assertStringNotContainsString("'offset'", $src);
    }

    public function test_v3_ui_phase_labels(): void
    {
        self::assertSame('Đang chuẩn bị đồng bộ', SiteSyncStepCatalog::v3Label('discover'));
        self::assertSame('Đang đồng bộ dữ liệu', SiteSyncStepCatalog::v3Label('import'));
        self::assertSame('Đang đối soát dữ liệu cũ', SiteSyncStepCatalog::v3Label('reconcile_stale'));
        self::assertSame('Đang kiểm tra thay đổi mới', SiteSyncStepCatalog::v3Label('catch_up'));
        self::assertSame('Đang xác minh dữ liệu', SiteSyncStepCatalog::v3Label('verify'));
        self::assertSame('Hoàn tất', SiteSyncStepCatalog::v3Label('complete'));
        self::assertSame(6, SiteSyncStepCatalog::v3TotalSteps());
        self::assertSame(6, count(SiteSyncStepCatalog::v3Keys()));
    }

    public function test_v3_user_macro_progress_is_exactly_three_groups(): void
    {
        self::assertSame(3, SiteSyncStepCatalog::v3MacroTotalSteps());
        $groups = SiteSyncStepCatalog::v3MacroGroups();
        self::assertCount(3, $groups);
        self::assertSame(['discover'], $groups[0]['phases']);
        self::assertSame(['import', 'reconcile_stale', 'catch_up'], $groups[1]['phases']);
        self::assertSame(['verify', 'complete'], $groups[2]['phases']);

        $timeline = SiteSyncStepCatalog::v3MacroTimeline('reconcile_stale', 'needs_attention');
        self::assertCount(3, $timeline);
        self::assertSame('completed', $timeline[0]['status']);
        self::assertSame('failed', $timeline[1]['status']);
        self::assertSame('pending', $timeline[2]['status']);

        $technical = SiteSyncStepCatalog::v3Timeline('reconcile_stale', 'needs_attention');
        self::assertCount(6, $technical);
        self::assertSame('failed', $technical[2]['status']); // reconcile_stale
    }

    public function test_v2_seven_steps_project_to_three_macros(): void
    {
        $v2 = SiteSyncStepCatalog::timeline([
            ['step_key' => 'detect_capability', 'status' => 'completed', 'step_order' => 1],
            ['step_key' => 'request_snapshot_delta', 'status' => 'completed', 'step_order' => 2],
            ['step_key' => 'sync_site_profile', 'status' => 'running', 'step_order' => 3],
            ['step_key' => 'sync_url_catalog', 'status' => 'pending', 'step_order' => 4],
            ['step_key' => 'sync_provider_keywords', 'status' => 'pending', 'step_order' => 5],
            ['step_key' => 'missing_capability_fallback', 'status' => 'pending', 'step_order' => 6],
            ['step_key' => 'finalize', 'status' => 'pending', 'step_order' => 7],
        ]);
        self::assertCount(7, $v2);
        $macros = SiteSyncStepCatalog::v2MacroTimeline($v2);
        self::assertCount(3, $macros);
        self::assertSame('completed', $macros[0]['status']);
        self::assertSame('running', $macros[1]['status']);
        self::assertSame('pending', $macros[2]['status']);
    }

    public function test_feature_flag_and_job_exist(): void
    {
        self::assertTrue(method_exists(SiteSyncFeatureFlags::class, 'protocolV3Enabled'));
        self::assertTrue(class_exists(ProcessSiteSyncV3Job::class));
        self::assertTrue(class_exists(WordPressSiteSyncV3Client::class));
        self::assertTrue(class_exists(\Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncProtocolRouter::class));
    }

    public function test_v3_run_state_migration_exists(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_08_31_160000_site_sync_v3_run_state.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('protocol_version', $src);
        self::assertStringContainsString('seo_site_sync_v3_receipts', $src);
        self::assertStringContainsString('last_seen_sync_generation', $src);
        self::assertStringContainsString("omi_seo_ai", $src);
    }

    public function test_v3_baseline_meta_constants_exist(): void
    {
        self::assertSame(
            'seo_site_sync_v3_baseline_completed_at',
            SiteSyncV3Schema::META_BASELINE_COMPLETED_AT
        );
        self::assertSame(
            'seo_site_sync_v3_baseline_generation',
            SiteSyncV3Schema::META_BASELINE_GENERATION
        );
    }

    public function test_v3_orchestrator_requires_force_full_before_baseline(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString('v3_baseline_required', $src);
        self::assertStringContainsString('hasSuccessfulBaseline', $src);
        self::assertStringContainsString('META_BASELINE_COMPLETED_AT', $src);
        self::assertStringContainsString('META_BASELINE_GENERATION', $src);
    }
}
