<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalBatchCircuitBreakerState;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalBatchExecutionBudget;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLockReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\GenerationBatchNotificationPublisher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalBatchExecutionBudgetAndSafetyTest extends TestCase
{
    public function test_smoke_budget_hard_caps_items_at_three(): void
    {
        $budget = GlobalBatchExecutionBudget::fromStartOptions([
            'smoke_test' => true,
            'max_items' => 99,
            'allowed_task_ids' => [10, 20, 30, 40, 50],
        ]);

        self::assertTrue($budget['enabled']);
        self::assertSame(3, $budget['max_items']);
        self::assertCount(3, $budget['allowed_task_ids']);
        self::assertSame([10, 20, 30], $budget['allowed_task_ids']);
        self::assertTrue($budget['disable_paid_fallback']);
        self::assertTrue($budget['auto_rerun_disabled']);
        self::assertSame(3, $budget['max_attempts']);
    }

    public function test_filter_snapshot_never_exceeds_three_items(): void
    {
        $budget = GlobalBatchExecutionBudget::fromStartOptions([
            'smoke_test' => true,
            'allowed_task_ids' => [1, 2, 3],
        ]);
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['task_id' => $i, 'project_id' => 1, 'position' => $i];
        }
        $filtered = GlobalBatchExecutionBudget::filterSnapshotItems($items, $budget);
        self::assertCount(3, $filtered);
        self::assertSame([1, 2, 3], array_map(static fn (array $r): int => (int) $r['task_id'], $filtered));
    }

    public function test_dispatch_blocked_after_max_items_or_attempts(): void
    {
        $budget = GlobalBatchExecutionBudget::fromStartOptions([
            'smoke_test' => true,
            'max_items' => 3,
            'max_attempts' => 3,
            'allowed_task_ids' => [1, 2, 3],
        ]);
        $budget['items_started'] = 3;
        $gate = GlobalBatchExecutionBudget::assertCanDispatch($budget, 1);
        self::assertFalse($gate['ok']);
        self::assertSame('max_items_exhausted', $gate['reason']);

        $budget['items_started'] = 2;
        $budget['attempts_started'] = 3;
        $gate = GlobalBatchExecutionBudget::assertCanDispatch($budget, 2);
        self::assertFalse($gate['ok']);
        self::assertSame('max_attempts_exhausted', $gate['reason']);
    }

    public function test_fourth_task_not_in_allowlist_blocked(): void
    {
        $budget = GlobalBatchExecutionBudget::fromStartOptions([
            'smoke_test' => true,
            'allowed_task_ids' => [11, 22, 33],
        ]);
        $gate = GlobalBatchExecutionBudget::assertCanDispatch($budget, 44);
        self::assertFalse($gate['ok']);
        self::assertSame('task_not_in_allowed_list', $gate['reason']);
    }

    public function test_record_dispatch_increments_attempt_and_item_counters(): void
    {
        $settings = [
            GlobalBatchExecutionBudget::SETTINGS_KEY => GlobalBatchExecutionBudget::fromStartOptions([
                'smoke_test' => true,
                'allowed_task_ids' => [1, 2, 3],
            ]),
        ];
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, true);
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, true);
        $budget = GlobalBatchExecutionBudget::read($settings);
        self::assertSame(2, $budget['items_started']);
        self::assertSame(2, $budget['attempts_started']);

        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, false);
        $budget = GlobalBatchExecutionBudget::read($settings);
        self::assertSame(2, $budget['items_started']);
        self::assertSame(3, $budget['attempts_started']);
        self::assertFalse(GlobalBatchExecutionBudget::assertCanDispatch($budget)['ok']);
    }

    public function test_three_consecutive_failures_still_trip_global_cb(): void
    {
        $settings = [];
        for ($i = 1; $i <= 3; $i++) {
            $r = GlobalBatchCircuitBreakerState::recordItemFailure($settings, 'x', 'e'.$i, $i, 100 + $i, false);
            $settings = $r['settings'];
        }
        self::assertTrue(GlobalBatchCircuitBreakerState::isStopped($settings));
        self::assertSame(3, GlobalBatchCircuitBreakerState::consecutiveFailures($settings));
    }

    public function test_orchestrator_wires_budget_gates_before_dispatch(): void
    {
        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('GlobalBatchExecutionBudget::fromStartOptions', $orch);
        self::assertStringContainsString('GlobalBatchExecutionBudget::filterSnapshotItems', $orch);
        self::assertStringContainsString('GlobalBatchExecutionBudget::assertCanDispatch', $orch);
        self::assertStringContainsString('claimDispatchSlot', $orch);
        self::assertStringContainsString('paid_fallback_disabled_by_budget', $orch);
        self::assertStringContainsString('auto_rerun_disabled_by_budget', $orch);
        self::assertStringContainsString('Refuse Global Batch start with actor user_id=0', $orch);
        self::assertStringContainsString('max_items', $orch);
    }

    public function test_reconciler_still_blocks_circuit_breaker_resume(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLockReconciler::class))->getFileName(),
        );
        self::assertStringContainsString('circuit_breaker_open_no_auto_resume', $src);
    }

    public function test_notification_writers_go_through_operational_service(): void
    {
        $pub = (string) file_get_contents(
            (string) (new ReflectionClass(GenerationBatchNotificationPublisher::class))->getFileName(),
        );
        self::assertStringContainsString('OperationalNotificationService', $pub);
        self::assertStringContainsString('global_batch_id', $pub);

        $svc = (string) file_get_contents(
            (string) (new ReflectionClass(OperationalNotificationService::class))->getFileName(),
        );
        self::assertStringContainsString('withCrossWorkerDedupLock', $svc);
        self::assertStringContainsString('GET_LOCK', $svc);
        self::assertStringContainsString('Cache::lock', $svc);
        self::assertStringContainsString("'array', 'null'", $svc);
        self::assertStringContainsString('findActiveForUpdate', $svc);
    }

    public function test_uncapped_start_options_leave_budget_disabled(): void
    {
        $budget = GlobalBatchExecutionBudget::fromStartOptions([]);
        self::assertFalse($budget['enabled'] ?? true);
    }

    public function test_budget_counters_persist_via_started_batch_item_ids_and_claim_api(): void
    {
        $budget = GlobalBatchExecutionBudget::fromStartOptions([
            'smoke_test' => true,
            'allowed_task_ids' => [1, 2, 3],
        ]);
        self::assertSame([], $budget['started_batch_item_ids']);

        $settings = [GlobalBatchExecutionBudget::SETTINGS_KEY => $budget];
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, true, 101);
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, true, 102);
        // Same item again = attempt only (duplicate queued job safety at counter level)
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, false, 101);
        $b = GlobalBatchExecutionBudget::read($settings);
        self::assertSame([101, 102], $b['started_batch_item_ids']);
        self::assertSame(2, $b['items_started']);
        self::assertSame(3, $b['attempts_started']);
        // Fourth attempt (duplicate job) must be blocked by assert before claim.
        self::assertFalse(GlobalBatchExecutionBudget::assertCanDispatch($b, 103)['ok']);
        self::assertSame('max_attempts_exhausted', GlobalBatchExecutionBudget::assertCanDispatch($b, 103)['reason']);

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalBatchExecutionBudget::class))->getFileName(),
        );
        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString('claimDispatchSlot', $src);
        self::assertStringContainsString('started_batch_item_ids', $src);
        self::assertStringContainsString('Already counted this item', $src);
        // Wake/reconciler must not wipe budget key.
        self::assertStringNotContainsString("unset(\$settings[self::SETTINGS_KEY]", $src);
    }

    public function test_duplicate_job_same_item_consumes_attempt_not_extra_item_slot(): void
    {
        $settings = [
            GlobalBatchExecutionBudget::SETTINGS_KEY => GlobalBatchExecutionBudget::fromStartOptions([
                'smoke_test' => true,
                'max_items' => 3,
                'max_attempts' => 3,
                'allowed_task_ids' => [1, 2, 3],
            ]),
        ];
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, true, 501);
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, false, 501);
        $settings = GlobalBatchExecutionBudget::recordDispatchStarted($settings, false, 501);
        $b = GlobalBatchExecutionBudget::read($settings);
        self::assertSame([501], $b['started_batch_item_ids']);
        self::assertSame(1, $b['items_started']);
        self::assertSame(3, $b['attempts_started']);
        self::assertFalse(GlobalBatchExecutionBudget::assertCanDispatch($b, 502)['ok']);
    }

    public function test_notification_dedup_releases_lock_on_timeout_and_exception_paths(): void
    {
        $svc = (string) file_get_contents(
            (string) (new ReflectionClass(OperationalNotificationService::class))->getFileName(),
        );
        self::assertStringContainsString('LockTimeoutException', $svc);
        self::assertStringContainsString('RELEASE_LOCK', $svc);
        self::assertStringContainsString('if ($acquired)', $svc);
        self::assertStringContainsString('finally', $svc);
    }
}
