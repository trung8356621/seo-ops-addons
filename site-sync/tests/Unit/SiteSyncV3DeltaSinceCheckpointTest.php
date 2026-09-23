<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * V3 delta must send frozen `since` on every /records call (WP rejects blank).
 */
final class SiteSyncV3DeltaSinceCheckpointTest extends TestCase
{
    public function test_delta_checkpoint_and_import_since_constants(): void
    {
        self::assertSame(
            'seo_site_sync_v3_delta_checkpoint_at',
            SiteSyncV3Schema::META_DELTA_CHECKPOINT_AT
        );
        self::assertSame('import_since', SiteSyncV3Schema::META_IMPORT_SINCE);
    }

    public function test_phase_import_sends_since_for_delta_not_full(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'phaseImport');

        self::assertStringContainsString("\$body['since']", $method);
        self::assertStringContainsString('META_IMPORT_SINCE', $method);
        self::assertStringContainsString('ensureImportSinceFrozen', $method);
        self::assertStringContainsString('delta_since_missing', $method);

        $fullBranch = $this->sliceBetween($method, "\$recordsMode === 'full'", '} else {');
        self::assertStringContainsString('snapshot_at', $fullBranch);
        self::assertStringContainsString('snapshot_bounds', $fullBranch);
        self::assertStringNotContainsString("\$body['since']", $fullBranch);

        $deltaBranch = $this->sliceBetween($method, '} else {', '$started = now()');
        self::assertStringContainsString("\$body['since']", $deltaBranch);
        self::assertStringNotContainsString('snapshot_bounds', $deltaBranch);
    }

    public function test_start_freezes_import_since_for_delta(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'start');

        self::assertStringContainsString('resolvePersistentDeltaCheckpoint', $method);
        self::assertStringContainsString('META_IMPORT_SINCE', $method);
        self::assertStringContainsString('v3_baseline_required', $method);
        self::assertStringContainsString('! $forceFull', $method);
    }

    public function test_discover_freezes_import_since_once_for_delta(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'phaseDiscover');

        self::assertStringContainsString('MODE_DELTA', $method);
        self::assertStringContainsString('ensureImportSinceFrozen', $method);
        self::assertStringContainsString('META_IMPORT_SINCE', $method);
        self::assertStringNotContainsString("META_IMPORT_SINCE'] = \$meta['snapshot_at']", $method);
    }

    public function test_resume_backfills_import_since_for_pre_fix_delta_runs(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'resume');

        self::assertStringContainsString('ensureImportSinceFrozen', $method);
        self::assertStringContainsString('MODE_DELTA', $method);
        self::assertStringContainsString('META_IMPORT_SINCE', $method);
        self::assertStringContainsString("\$meta['retry_count'] = 0", $method);
        self::assertStringContainsString('META_GENERATION', $method);
    }

    public function test_catch_up_prefers_import_since_over_snapshot_at(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'phaseCatchUp');

        self::assertStringContainsString('META_IMPORT_SINCE', $method);
        self::assertStringContainsString("'since' => \$since", $method);
        self::assertStringContainsString('delta_since_missing', $method);

        // Primary since chain: catch_up_since → import_since → catch_up_boundary (no now / no snapshot_at).
        $primary = $this->sliceBetween($method, '$since = trim', 'if ($since === \'\' &&');
        self::assertStringContainsString('META_IMPORT_SINCE', $primary);
        self::assertStringNotContainsString('now()', $primary);
        self::assertStringNotContainsString('snapshot_at', $primary);

        // snapshot_at only as force_full fallback when primary chain is empty.
        $forceFullFallback = $this->sliceBetween($method, 'MODE_FORCE_FULL', 'if ($since === \'\')');
        self::assertStringContainsString('snapshot_at', $forceFullFallback);
    }

    public function test_complete_advances_checkpoint_only_after_clean_verify(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'phaseComplete');

        self::assertStringContainsString('META_DELTA_CHECKPOINT_AT', $method);
        self::assertStringContainsString('resolveTerminalDeltaCheckpoint', $method);
        self::assertStringContainsString('$cleanVerify', $method);
        self::assertStringContainsString('v3_delta_checkpoint_advanced', $method);
    }

    public function test_fail_and_cancel_do_not_write_delta_checkpoint(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $fail = $this->methodBody($src, 'failRun');
        $cancel = $this->methodBody($src, 'cancel');

        self::assertStringNotContainsString('META_DELTA_CHECKPOINT_AT', $fail);
        self::assertStringNotContainsString('META_DELTA_CHECKPOINT_AT', $cancel);
    }

    public function test_checkpoint_resolution_fallback_order(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'resolvePersistentDeltaCheckpoint');

        $explicit = strpos($method, 'META_DELTA_CHECKPOINT_AT');
        $fromRun = strpos($method, 'checkpointFromLatestSuccessfulV3Run');
        $baseline = strpos($method, 'META_BASELINE_COMPLETED_AT');

        self::assertNotFalse($explicit);
        self::assertNotFalse($fromRun);
        self::assertNotFalse($baseline);
        self::assertLessThan($fromRun, $explicit);
        self::assertLessThan($baseline, $fromRun);
        self::assertStringNotContainsString('now()', $method);
    }

    public function test_terminal_checkpoint_prefers_catch_up_boundary_not_finished_at(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'resolveTerminalDeltaCheckpoint');

        self::assertStringContainsString('catch_up_boundary_at', $method);
        self::assertStringContainsString('catch_up_since', $method);
        self::assertStringNotContainsString('finished_at', $method);
    }

    public function test_content_and_terms_share_same_import_since_key(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $import = $this->methodBody($src, 'phaseImport');

        // Single frozen key used for whatever import_resource is current.
        self::assertStringContainsString('META_IMPORT_SINCE', $import);
        self::assertSame(1, substr_count($import, "\$body['since']"));
    }

    private function methodBody(string $src, string $method): string
    {
        $ref = new ReflectionMethod(RunSiteSyncV3Orchestrator::class, $method);
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    private function sliceBetween(string $haystack, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($haystack, $startNeedle);
        self::assertNotFalse($start, "missing start needle: {$startNeedle}");
        $end = strpos($haystack, $endNeedle, $start);
        self::assertNotFalse($end, "missing end needle: {$endNeedle}");

        return substr($haystack, $start, $end - $start);
    }
}
