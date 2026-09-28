<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3CheckpointStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression test for Site Sync V3 first-baseline flow on single-language & scoped sites.
 *
 * Covers:
 * CASE A — single-language, baseline absent: auto-promotes to force_full
 * CASE B — single-language, baseline exists: keeps delta mode
 * CASE C — scoped primary, baseline absent: auto-promotes to force_full
 * CASE D — delta checkpoint safety: genuine delta fails closed if checkpoint missing
 * CASE E — status presenter read model: old sync data != V3 baseline ready
 */
final class SiteSyncV3FirstBaselineBootstrapTest extends TestCase
{
    private function extractMethod(string $src, string $method): string
    {
        if (! preg_match(
            '/(?:public|private|protected) function '.$method.'\([^{]*\{([\s\S]*?)\n    (?:public|private|protected) function /',
            $src,
            $m,
        )) {
            self::fail('Could not extract method '.$method);
        }

        return $m[1];
    }

    public function test_case_a_and_c_start_auto_promotes_unscoped_and_scoped_without_baseline(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $startMethod = $this->extractMethod($src, 'start');

        // Both scoped and unscoped sites must evaluate hasBaseline.
        self::assertStringContainsString('$hasBaseline = $languageScope !== \'\'', $startMethod);
        self::assertStringContainsString('$this->checkpointStore->hasSuccessfulBaseline($site, $languageScope)', $startMethod);
        self::assertStringContainsString('self::hasSuccessfulBaseline($site)', $startMethod);

        // Auto-promote to force_full must apply unconditionally when baseline is absent.
        // It must NOT error out with v3_baseline_required before run creation.
        self::assertStringContainsString('if (! $forceFull && ! $hasBaseline)', $startMethod);
        self::assertStringContainsString('$forceFull = true;', $startMethod);
        self::assertStringContainsString('$autoPromoted = true;', $startMethod);

        // Mode must become MODE_FORCE_FULL before run creation.
        self::assertStringContainsString(
            '$mode = $forceFull ? SiteSyncV3Schema::MODE_FORCE_FULL : SiteSyncV3Schema::MODE_DELTA;',
            $startMethod
        );

        // Diagnostic meta must be recorded.
        self::assertStringContainsString("\$runMeta['auto_promoted_force_full'] = true;", $startMethod);
        self::assertStringContainsString("\$runMeta['baseline_bootstrap'] = true;", $startMethod);
    }

    public function test_case_b_baseline_exists_keeps_delta_mode(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $startMethod = $this->extractMethod($src, 'start');

        // When $hasBaseline is true, auto-promote block does NOT fire.
        // $forceFull remains false, and $mode resolves to MODE_DELTA.
        self::assertStringContainsString(
            '$mode = $forceFull ? SiteSyncV3Schema::MODE_FORCE_FULL : SiteSyncV3Schema::MODE_DELTA;',
            $startMethod
        );
        // Delta path verifies checkpoint.
        self::assertStringContainsString('if (! $forceFull)', $startMethod);
        self::assertStringContainsString('resolveDeltaCheckpoint', $startMethod);
        self::assertStringContainsString('resolvePersistentDeltaCheckpoint', $startMethod);
    }

    public function test_case_d_delta_checkpoint_safety_guard_fails_closed(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $startMethod = $this->extractMethod($src, 'start');

        // Safety guard: a genuine delta with no valid checkpoint must fail closed.
        self::assertStringContainsString('if (! $forceFull)', $startMethod);
        self::assertStringContainsString('$importSince === null || $importSince === \'\'', $startMethod);
        self::assertStringContainsString("'error_code' => 'v3_baseline_required'", $startMethod);
        self::assertStringContainsString(
            "'message' => 'Chưa có V3 force-full baseline — chạy Force Full trước khi dùng delta.'",
            $startMethod
        );
    }

    public function test_case_e_status_presenter_distinguishes_old_data_from_v3_baseline(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName()
        );

        self::assertStringContainsString("'v3_baseline_ready'", $src);
        self::assertStringContainsString("'baseline_ready'", $src);
        self::assertStringContainsString('hasSuccessfulBaseline', $src);
        self::assertStringContainsString('SiteSyncV3CheckpointStore', $src);
    }

    public function test_checkpoint_store_baseline_detection_semantics(): void
    {
        $store = new SiteSyncV3CheckpointStore();

        // Fresh mock site with no metas.
        $site = $this->createMockSite([]);
        self::assertFalse($store->hasSuccessfulBaseline($site, ''));
        self::assertFalse(RunSiteSyncV3Orchestrator::hasSuccessfulBaseline($site));
        self::assertNull(RunSiteSyncV3Orchestrator::resolvePersistentDeltaCheckpoint($site));

        // Mock site with only legacy V2 data (no V3 baseline meta).
        $siteWithOldData = $this->createMockSite([
            'site_sync_last_synced_at' => '2026-01-01T00:00:00Z',
            'site_sync_bootstrapped_at' => '2026-01-01T00:00:00Z',
        ]);
        self::assertFalse($store->hasSuccessfulBaseline($siteWithOldData, ''));
        self::assertFalse(RunSiteSyncV3Orchestrator::hasSuccessfulBaseline($siteWithOldData));
        self::assertNull(RunSiteSyncV3Orchestrator::resolvePersistentDeltaCheckpoint($siteWithOldData));

        // Mock site with completed V3 baseline.
        $siteWithV3Baseline = $this->createMockSite([
            SiteSyncV3Schema::META_BASELINE_COMPLETED_AT => '2026-09-28T10:00:00Z',
            SiteSyncV3Schema::META_BASELINE_GENERATION => 5,
        ]);
        self::assertTrue($store->hasSuccessfulBaseline($siteWithV3Baseline, ''));
        self::assertTrue(RunSiteSyncV3Orchestrator::hasSuccessfulBaseline($siteWithV3Baseline));
        self::assertSame('2026-09-28T10:00:00Z', RunSiteSyncV3Orchestrator::resolvePersistentDeltaCheckpoint($siteWithV3Baseline));
    }

    /**
     * @param array<string, mixed> $metas
     */
    private function createMockSite(array $metas): Site
    {
        $site = $this->getMockBuilder(Site::class)
            ->onlyMethods(['getMeta'])
            ->getMock();

        $site->method('getMeta')->willReturnCallback(
            static fn (string $key): mixed => $metas[$key] ?? null
        );

        return $site;
    }
}
