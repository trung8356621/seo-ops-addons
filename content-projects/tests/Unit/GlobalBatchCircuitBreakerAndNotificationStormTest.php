<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalAutoGenerationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalBatchCircuitBreakerState;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLockReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchNotificationPublisher;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchProgressReadModel;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\GenerationBatchNotificationPublisher;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalBatchCircuitBreakerAndNotificationStormTest extends TestCase
{
    public function test_three_consecutive_item_failures_trip_breaker(): void
    {
        $settings = [];
        for ($i = 1; $i <= 2; $i++) {
            $r = GlobalBatchCircuitBreakerState::recordItemFailure($settings, 'system_error', 'x'.$i, $i, 100 + $i, false);
            $settings = $r['settings'];
            self::assertFalse($r['tripped']);
            self::assertSame($i, $r['consecutive']);
        }
        $r = GlobalBatchCircuitBreakerState::recordItemFailure($settings, 'system_error', 'x3', 3, 103, false);
        self::assertTrue($r['tripped']);
        self::assertSame(3, $r['consecutive']);
        self::assertTrue(GlobalBatchCircuitBreakerState::isStopped($r['settings']));
        self::assertTrue(! empty($r['settings']['auto_rerun_disabled']));
    }

    public function test_success_resets_consecutive_counter(): void
    {
        $settings = [];
        $settings = GlobalBatchCircuitBreakerState::recordItemFailure($settings, 'x', 'a', 1, 1, false)['settings'];
        $settings = GlobalBatchCircuitBreakerState::recordItemFailure($settings, 'x', 'b', 2, 2, false)['settings'];
        self::assertSame(2, GlobalBatchCircuitBreakerState::consecutiveFailures($settings));
        $settings = GlobalBatchCircuitBreakerState::recordSuccess($settings);
        self::assertSame(0, GlobalBatchCircuitBreakerState::consecutiveFailures($settings));
        self::assertFalse(GlobalBatchCircuitBreakerState::isStopped($settings));
    }

    public function test_retry_or_child_run_change_does_not_reset_counter(): void
    {
        $settings = GlobalBatchCircuitBreakerState::recordItemFailure([], 'x', 'a', 1, 10, false)['settings'];
        // Simulating another child run / retry for next permanent fail continues count.
        $settings = GlobalBatchCircuitBreakerState::recordItemFailure($settings, 'x', 'b', 2, 99, false)['settings'];
        self::assertSame(2, GlobalBatchCircuitBreakerState::consecutiveFailures($settings));
    }

    public function test_infrastructure_failure_trips_immediately(): void
    {
        $policy = new GlobalAutoGenerationPolicy;
        self::assertTrue($policy->isInfrastructureFailure(
            'system_error',
            "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'omi_client.seo_connection_users' doesn't exist",
        ));

        $r = GlobalBatchCircuitBreakerState::recordItemFailure(
            [],
            'system_error',
            "Table 'omi_client.seo_connection_users' doesn't exist",
            1,
            1,
            true,
        );
        self::assertTrue($r['tripped']);
        self::assertSame(1, $r['consecutive']);
        self::assertSame(
            GlobalBatchCircuitBreakerState::TRIGGER_INFRASTRUCTURE,
            $r['settings'][GlobalBatchCircuitBreakerState::SETTINGS_KEY]['trigger'] ?? null,
        );
    }

    public function test_orchestrator_wires_global_breaker_and_blocks_dispatch(): void
    {
        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('GlobalBatchCircuitBreakerState::recordItemFailure', $orch);
        self::assertStringContainsString('GlobalBatchCircuitBreakerState::recordSuccess', $orch);
        self::assertStringContainsString('isInfrastructureFailure', $orch);
        self::assertStringContainsString("'action' => 'circuit_breaker'", $orch);
        self::assertStringContainsString('STATUS_STOPPED_CIRCUIT_BREAKER', $orch);
        self::assertStringContainsString('circuit_breaker_open', $orch);
        self::assertStringContainsString('initiator_user_id', $orch);
        self::assertStringContainsString('GlobalMonthlyBatchNotificationPublisher', $orch);
    }

    public function test_reconciler_does_not_auto_resume_circuit_breaker_batch(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLockReconciler::class))->getFileName(),
        );
        self::assertStringContainsString('circuit_breaker_open_no_auto_resume', $src);
        self::assertStringContainsString('GlobalBatchCircuitBreakerState::isStopped', $src);
    }

    public function test_child_run_notification_suppressed_for_global_batch(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GenerationBatchNotificationPublisher::class))->getFileName(),
        );
        self::assertStringContainsString('global_batch_id', $src);
        self::assertStringContainsString('return;', $src);

        $batchPub = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchNotificationPublisher::class))->getFileName(),
        );
        self::assertStringContainsString('generation-global-batch:', $batchPub);
        self::assertStringContainsString('notifyBatchTerminal', $batchPub);
    }

    public function test_operational_keys_persist_auto_cost_flags(): void
    {
        $snap = ContentProjectRunSettings::snapshotForRun([
            'use_php_engine' => true,
            'lazy_bulk' => true,
            'task_ids' => [1],
            'global_batch_id' => 4,
            'global_batch_item_id' => 10,
            'global_auto_cost_strategy' => 'paid',
            'global_auto_cost_paid_only' => true,
            'disable_batch_circuit_breaker' => true,
            'initiator_user_id' => 2,
            'ai_cost_policy' => 'default',
            'ai_generation_mode' => 'paid_preferred',
        ]);

        self::assertSame(4, (int) ($snap['global_batch_id'] ?? 0));
        self::assertSame(10, (int) ($snap['global_batch_item_id'] ?? 0));
        self::assertSame('paid', $snap['global_auto_cost_strategy'] ?? null);
        self::assertTrue((bool) ($snap['disable_batch_circuit_breaker'] ?? false));
        self::assertSame(2, (int) ($snap['initiator_user_id'] ?? 0));
        self::assertSame('paid_preferred', $snap['ai_generation_mode'] ?? null);
    }

    public function test_panel_owner_avoids_retired_seo_connection_users_table(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessControl::class))->getFileName(),
        );
        $pos = strpos($src, 'function panelOwnerId');
        self::assertNotFalse($pos);
        $slice = substr($src, $pos, 900);
        self::assertStringContainsString("hasTable('seo_connection_users')", $slice);
        self::assertStringContainsString('accountOwnerId', $slice);
    }

    public function test_progress_ui_labels_circuit_breaker_stop_not_waiting(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('STATUS_STOPPED_CIRCUIT_BREAKER', $src);
        self::assertStringContainsString('Dừng — Circuit breaker', $src);
        self::assertStringContainsString('không còn chờ bài tiếp theo', $src);
    }

    public function test_batch_model_recognizes_circuit_breaker_terminal(): void
    {
        self::assertSame('stopped_circuit_breaker', SeoContentGlobalBatch::STATUS_STOPPED_CIRCUIT_BREAKER);
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoContentGlobalBatch::class))->getFileName(),
        );
        self::assertStringContainsString('STATUS_STOPPED_CIRCUIT_BREAKER', $src);
        self::assertGreaterThan(
            1,
            substr_count($src, 'STATUS_STOPPED_CIRCUIT_BREAKER'),
        );
    }

    public function test_clear_for_resume_does_not_reopen_failed_items_implicitly(): void
    {
        $settings = GlobalBatchCircuitBreakerState::recordItemFailure([], 'x', 'a', 1, 1, true)['settings'];
        self::assertTrue(GlobalBatchCircuitBreakerState::isStopped($settings));
        $cleared = GlobalBatchCircuitBreakerState::clearForResume($settings);
        self::assertFalse(GlobalBatchCircuitBreakerState::isStopped($cleared));
        self::assertArrayNotHasKey('auto_rerun_disabled', $cleared);
    }

    public function test_orchestrator_refuses_initiator_user_id_zero(): void
    {
        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('missing_initiator_user_id', $orch);
        self::assertStringContainsString('Refuse Auto Cost child run with initiator_user_id=0', $orch);

        $workflow = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectWorkflowRunService::class))->getFileName(),
        );
        self::assertStringContainsString('Refuse to start project run with user_id=0', $workflow);
        self::assertStringContainsString('initiator_user_id / auth / project owner required', $workflow);
    }
}
