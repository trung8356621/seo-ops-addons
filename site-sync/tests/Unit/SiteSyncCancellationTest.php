<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncRunExecution;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncCancellationTest extends TestCase
{
    public function test_job_entry_guard_contract(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncStepJob::class))->getFileName());
        self::assertStringContainsString('site_sync.job_skipped_canceled', $src);
        self::assertStringContainsString('shouldSkipJob', $src);
        self::assertStringContainsString('executionGeneration', $src);
    }

    public function test_runner_checks_canceled_before_revive_running(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner::class))->getFileName());
        self::assertStringContainsString('__canceled_stop', $src);
        self::assertStringContainsString('dispatchContinuation', $src);
        self::assertStringContainsString('chunkStoppedPayload', $src);
        self::assertStringNotContainsString('ProcessSiteSyncStepJob::dispatch($runId)', $src);
    }

    public function test_cancel_bumps_execution_generation(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator::class))->getFileName());
        self::assertStringContainsString('site_sync.cancel_requested', $src);
        self::assertStringContainsString('stampCancel', $src);
    }

    public function test_should_skip_canceled_run(): void
    {
        $execution = new SiteSyncRunExecution();
        $run = new SeoSiteSyncRun([
            'status' => 'canceled',
            'meta' => ['execution_generation' => 2],
        ]);

        self::assertTrue($execution->isCanceled($run));
    }

    public function test_stale_generation_detected_by_read_generation(): void
    {
        $execution = new SiteSyncRunExecution();
        $run = new SeoSiteSyncRun([
            'status' => 'running',
            'meta' => ['execution_generation' => 3],
        ]);

        self::assertSame(3, $execution->readGeneration($run));
    }

    public function test_stamp_cancel_increments_generation_and_timestamps(): void
    {
        $execution = new SiteSyncRunExecution();
        $run = new SeoSiteSyncRun([
            'status' => 'running',
            'meta' => ['execution_generation' => 1],
        ]);

        $next = $execution->stampCancel($run);
        self::assertSame(2, $next);
        self::assertSame(2, $execution->readGeneration($run));
        $meta = is_array($run->meta) ? $run->meta : [];
        self::assertNotEmpty($meta[SiteSyncRunExecution::META_CANCELED_AT] ?? null);
    }

    public function test_finalize_guard_skips_canceled(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner::class))->getFileName());
        self::assertStringContainsString('finalizeRunIfComplete', $src);
        self::assertStringContainsString('isCanceled($run)', $src);
    }

    public function test_resume_and_retry_reject_canceled(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator::class))->getFileName());
        self::assertStringContainsString('Run canceled — cannot resume', $src);
        self::assertStringContainsString('Run canceled — cannot retry step', $src);
    }
}
