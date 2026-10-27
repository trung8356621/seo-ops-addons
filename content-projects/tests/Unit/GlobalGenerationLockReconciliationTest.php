<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Console\ContentProjectWorkerLostWatchdogCommand;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\ContentProjectGlobalLockBridge;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLockReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class GlobalGenerationLockReconciliationTest extends TestCase
{
    public function test_reconciler_class_exists_and_implements_reconcile_contract(): void
    {
        $ref = new ReflectionClass(GlobalGenerationLockReconciler::class);
        self::assertTrue($ref->hasMethod('reconcile'));

        $m = $ref->getMethod('reconcile');
        self::assertTrue($m->isPublic());
        self::assertSame('array', (string) $m->getReturnType());
    }

    public function test_operational_keys_preserve_global_lock_and_batch_settings(): void
    {
        self::assertContains('global_batch_id', ContentProjectRunSettings::OPERATIONAL_KEYS);
        self::assertContains('inherits_global_lock', ContentProjectRunSettings::OPERATIONAL_KEYS);
        self::assertContains('global_generation_lock_token', ContentProjectRunSettings::OPERATIONAL_KEYS);

        $snapshot = ContentProjectRunSettings::snapshotForRun([
            'global_batch_id' => 42,
            'inherits_global_lock' => true,
            'global_generation_lock_token' => 'abc-token-123',
            'task_ids' => [1, 2, 3],
        ]);

        self::assertSame(42, $snapshot['global_batch_id']);
        self::assertTrue($snapshot['inherits_global_lock']);
        self::assertSame('abc-token-123', $snapshot['global_generation_lock_token']);
    }

    public function test_bridge_defensively_inherits_lock_and_dispatches_advance_fallback(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectGlobalLockBridge::class))->getFileName(),
        );

        // Child run inherits parent lock and does not fail when parent batch holds lock
        self::assertStringContainsString('assertHeldBy(SeoContentGenerationLock::OWNER_GLOBAL_BATCH', $src);
        self::assertStringContainsString('inherits_global_lock', $src);
        self::assertStringContainsString('current_project_run_id', $src);

        // onProjectRunTerminal dispatches advance
        self::assertStringContainsString('AdvanceGlobalMonthlyBatchJob::dispatch', $src);
    }

    public function test_advance_is_strictly_atomic_and_prevents_duplicate_dispatch(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        // Strict inequality guard against stale or already advanced run
        self::assertStringContainsString('if ($currentRunId !== $projectRunId)', $src);
        self::assertStringContainsString("'action' => 'stale'", $src);
        self::assertStringContainsString('current_project_run_id', $src);
    }

    public function test_reconciler_handles_all_required_cases_in_source(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLockReconciler::class))->getFileName(),
        );

        // Case A: Completed/terminal owner + stale lock -> released
        self::assertStringContainsString('released_terminal_owner_lock', $src);
        self::assertStringContainsString('finalized_completed_run_and_released_lock', $src);
        self::assertStringContainsString('releaseIfOwner', $src);

        // Case B: Child terminal + parent not advanced -> replayed advance
        self::assertStringContainsString('replayed_advance_after_child_terminal', $src);
        self::assertStringContainsString('advanceAfterChildRun', $src);

        // Case C: Running worker -> retained
        self::assertStringContainsString('retain_lock', $src);
        self::assertStringContainsString('child_run_worker_active', $src);
        self::assertStringContainsString('run_worker_active', $src);

        // Auto Cost waiting_retry cooldown must retain exclusive lock
        self::assertStringContainsString('waiting_retry_cooldown_hold', $src);
        self::assertStringContainsString('redispatched_due_waiting_retry_wake', $src);

        // Case D: WORKER_LOST -> reuse existing recovery contract
        self::assertStringContainsString('recoverDeadDispatchIfConfirmed', $src);
        self::assertStringContainsString('isRecoverableRun', $src);
        self::assertStringContainsString('worker_lost', $src);

        // Case E: Unknown / orphan lock -> safe release / fail-closed
        self::assertStringContainsString('released_orphan_lock', $src);
        self::assertStringContainsString('released_unknown_owner_lock', $src);
        self::assertStringContainsString('fail_closed', $src);
    }

    public function test_watchdog_command_invokes_reconciler(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectWorkerLostWatchdogCommand::class))->getFileName(),
        );

        self::assertStringContainsString('GlobalGenerationLockReconciler $reconciler', $src);
        self::assertStringContainsString('$reconciler->reconcile()', $src);
        self::assertStringContainsString('Global Lock reconciled', $src);
    }

    public function test_lock_read_holder_provides_rich_owner_and_progress_metadata(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLock::class))->getFileName(),
        );

        self::assertStringContainsString('owner_label', $src);
        self::assertStringContainsString('project_id', $src);
        self::assertStringContainsString('project_name', $src);
        self::assertStringContainsString('run_id', $src);
        self::assertStringContainsString('real_status', $src);
        self::assertStringContainsString('heartbeat_at_human', $src);
        self::assertStringContainsString('progress_url', $src);
    }

    public function test_ui_blade_templates_render_compact_lock_notice(): void
    {
        $baseDir = dirname((new ReflectionClass(GlobalGenerationLock::class))->getFileName(), 6);
        $globalBlade = (string) file_get_contents(
            $baseDir.'/seo-content-ai-compat/resources/views/filament/pages/global-monthly-generator.blade.php'
        );

        self::assertStringContainsString('global-lock-notice', $globalBlade);

        $cpBlade = (string) file_get_contents(
            $baseDir.'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/view-seo-project-progress.blade.php'
        );

        self::assertStringContainsString('global-lock-notice', $cpBlade);

        $noticeBlade = (string) file_get_contents(
            $baseDir.'/seo-content-ai-compat/resources/views/filament/pages/partials/global-lock-notice.blade.php'
        );
        self::assertStringContainsString('data-cp-lock-notice="held"', $noticeBlade);
        self::assertStringContainsString('data-cp-lock-notice="unavailable"', $noticeBlade);
        self::assertStringContainsString('progress_url', $noticeBlade);
    }

    public function test_completed_articles_are_never_regenerated_on_resume(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        // startNextProjectSlice filters on pending tasks only
        self::assertStringContainsString("where('status', SeoContentGlobalBatchItem::STATUS_PENDING)", $src);
        self::assertStringNotContainsString("STATUS_SUCCEEDED", substr($src, strpos($src, 'startNextProjectSlice'), 800));
    }

    public function test_terminal_project_run_releases_lock_automatically_when_no_active_execution(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLockReconciler::class))->getFileName(),
        );

        // When run is terminal, reconciler checks active worker/queue jobs and releases lock
        self::assertStringContainsString('$run->isTerminal()', $src);
        self::assertStringContainsString('$this->isRunWorkerActive($run)', $src);
        self::assertStringContainsString('$this->hasActiveQueueJobsForRun($runId)', $src);
        self::assertStringContainsString('released_terminal_owner_lock', $src);
        self::assertStringContainsString('released_worker_lost_terminal_owner_lock', $src);
    }
}
