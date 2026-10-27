<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingContextResolver;
use Omnichannel\Addons\ContentProjects\Jobs\WakeGlobalMonthlyBatchJob;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalAutoGenerationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLockReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Final hardening regressions:
 * 1) Paid routing isolation (no Free during Paid strategy)
 * 2) Durable Auto Retry wake redispatch contract
 * 3) Global Lock retained during waiting_retry; Emergency Stop disables wake
 */
final class GlobalAutoCostHardeningTest extends TestCase
{
    private GlobalAutoGenerationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new GlobalAutoGenerationPolicy;
    }

    // ─── 1. PAID ROUTING ISOLATION ───────────────────────────────────────

    public function test_paid_strategy_settings_enforce_paid_preferred_not_default_alone(): void
    {
        $paid = $this->policy->childRunSettingsForStrategy(GlobalAutoGenerationPolicy::STRATEGY_PAID);

        self::assertSame(AiCostPolicy::Default->value, $paid[AiCostPolicy::SETTING_KEY]);
        self::assertSame('paid_preferred', $paid['ai_generation_mode']);
        self::assertSame('paid_preferred', $paid['_item_generation_mode']);
        self::assertTrue((bool) $paid['global_auto_cost_paid_only']);
        self::assertTrue($this->policy->settingsEnforcePaidOnly($paid));

        $ctx = new AiRoutingContext(
            userId: 1,
            costPolicy: AiCostPolicy::tryFromMixed($paid[AiCostPolicy::SETTING_KEY]),
            itemGenerationMode: (string) $paid['ai_generation_mode'],
            freeOnly: false,
        );
        $mode = (new AiRoutingContextResolver)->resolveMode($ctx);
        self::assertSame(AiExecutionRoutingMode::PaidPreferred, $mode);

        // Free strategy remains FreeOnly — Individual Default path untouched.
        $free = $this->policy->childRunSettingsForStrategy(GlobalAutoGenerationPolicy::STRATEGY_FREE);
        self::assertSame(AiCostPolicy::FreeOnly->value, $free[AiCostPolicy::SETTING_KEY]);
        self::assertFalse($this->policy->settingsEnforcePaidOnly($free));
        self::assertSame(['default', 'free_only'], array_map(
            static fn (AiCostPolicy $c): string => $c->value,
            AiCostPolicy::cases(),
        ));
    }

    public function test_free_fail_then_paid_executes_exactly_one_paid_zero_extra_free(): void
    {
        $pool = [
            ['id' => 'free-a', 'is_free' => true],
            ['id' => 'free-b', 'is_free' => true],
            ['id' => 'paid-a', 'is_free' => false],
            ['id' => 'paid-b', 'is_free' => false],
        ];

        $freeRequests = 0;
        $paidRequests = 0;

        // STEP 1 — Free strategy (fake provider): only Free candidates.
        $freeEligible = $this->policy->filterCandidatesForStrategy(
            GlobalAutoGenerationPolicy::STRATEGY_FREE,
            $pool,
        );
        self::assertNotEmpty($freeEligible);
        self::assertTrue(array_reduce($freeEligible, static fn (bool $ok, array $c): bool => $ok && $c['is_free'], true));

        // Fake Free attempt fails after exactly one Free request.
        $freeRequests++;
        $afterFree = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_FREE,
            'success' => false,
            'error_category' => AiFailureClass::ProviderInvalidOutput->value,
            'now_ts' => 1_700_000_000,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_RUN_PAID, $afterFree['action']);
        self::assertSame(1, $freeRequests);
        self::assertSame(0, $paidRequests);

        // STEP 2 — Paid strategy: Free models must be excluded.
        $paidSettings = $this->policy->childRunSettingsForStrategy(GlobalAutoGenerationPolicy::STRATEGY_PAID);
        self::assertTrue($this->policy->settingsEnforcePaidOnly($paidSettings));

        $paidEligible = $this->policy->filterCandidatesForStrategy(
            GlobalAutoGenerationPolicy::STRATEGY_PAID,
            $pool,
        );
        self::assertCount(2, $paidEligible);
        self::assertTrue(array_reduce($paidEligible, static fn (bool $ok, array $c): bool => $ok && ! $c['is_free'], true));
        self::assertSame([], $this->policy->filterCandidatesForStrategy(
            GlobalAutoGenerationPolicy::STRATEGY_PAID,
            array_values(array_filter($pool, static fn (array $c): bool => $c['is_free'])),
        ));

        // Exactly one Paid request; Free counter unchanged during Paid strategy.
        $paidRequests++;
        $afterPaid = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_PAID,
            'success' => true,
            'free_attempted' => true,
            'paid_attempted' => false,
            'now_ts' => 1_700_000_010,
        ]);

        self::assertSame(GlobalAutoGenerationPolicy::ACTION_SUCCEEDED, $afterPaid['action']);
        self::assertTrue($afterPaid['persist_once']);
        self::assertSame(1, $freeRequests, 'Paid strategy must not issue additional Free requests');
        self::assertSame(1, $paidRequests, 'Exactly one Paid request after Free failure');
    }

    // ─── 2. DURABLE AUTO RETRY ───────────────────────────────────────────

    public function test_reconciler_redispatches_due_waiting_retry_and_never_releases_during_cooldown(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLockReconciler::class))->getFileName(),
        );

        self::assertStringContainsString('STATUS_WAITING_RETRY', $src);
        self::assertStringContainsString('waiting_retry_cooldown_hold', $src);
        self::assertStringContainsString('redispatched_due_waiting_retry_wake', $src);
        self::assertStringContainsString('ensureAutoRetryWakeScheduled', $src);
        self::assertStringContainsString('hasActiveWakeOrAdvanceJobsForBatch', $src);
        self::assertStringContainsString('WakeGlobalMonthlyBatchJob', $src);
        self::assertStringContainsString('heartbeat($ownerToken)', $src);

        // Must NOT release solely because pending==0 while waiting_retry remains.
        $cooldownPos = strpos($src, 'waiting_retry_cooldown_hold');
        $emptyReleasePos = strpos($src, 'finalized_empty_pending_batch_and_released_lock');
        self::assertNotFalse($cooldownPos);
        self::assertNotFalse($emptyReleasePos);
        self::assertLessThan(
            $emptyReleasePos,
            $cooldownPos,
            'waiting_retry retain path must be evaluated before empty-pending release',
        );

        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('function ensureAutoRetryWakeScheduled', $orch);
        self::assertStringContainsString('function wakeForAutoRetry', $orch);
        self::assertStringContainsString('child_still_active', $orch);
        self::assertTrue(class_exists(WakeGlobalMonthlyBatchJob::class));
    }

    public function test_wake_idempotent_skips_when_child_still_active(): void
    {
        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        $wakePos = strpos($orch, 'public function wakeForAutoRetry');
        self::assertNotFalse($wakePos);
        $slice = substr($orch, $wakePos, 3500);
        self::assertStringContainsString('child_still_active', $slice);
        self::assertStringContainsString('STATUS_RUNNING', $slice);
        self::assertStringContainsString('current_project_run_id', $slice);
        self::assertStringContainsString('auto_rerun_disabled', $slice);
    }

    // ─── 3. GLOBAL LOCK LIFECYCLE ────────────────────────────────────────

    public function test_emergency_stop_disables_future_wake_and_releases_after_settle(): void
    {
        $orch = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );

        self::assertStringContainsString("'auto_rerun_disabled' => true", $orch);
        self::assertStringContainsString('auto_rerun_disabled', $orch);

        $requestStopPos = strpos($orch, 'public function requestStop');
        self::assertNotFalse($requestStopPos);
        $stopSlice = substr($orch, $requestStopPos, 2000);
        self::assertStringContainsString('auto_rerun_disabled', $stopSlice);

        $schedulePos = strpos($orch, 'private function scheduleWake');
        self::assertNotFalse($schedulePos);
        $scheduleSlice = substr($orch, $schedulePos, 1200);
        self::assertStringContainsString('auto_rerun_disabled', $scheduleSlice);
        self::assertStringContainsString('stopRequested', $scheduleSlice);

        $recon = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalGenerationLockReconciler::class))->getFileName(),
        );
        self::assertStringContainsString('finalized_stopped_batch_and_released_lock', $recon);
        self::assertStringContainsString('auto_rerun_disabled', $recon);
        self::assertStringContainsString('stop_requested_without_active_child', $recon);

        self::assertSame('waiting_retry', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY);
        self::assertSame('stopped_ai_unavailable', SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE);
    }

    public function test_individual_ai_cost_policy_enum_unchanged_by_paid_isolation(): void
    {
        // Hardening must not invent AiCostPolicy::PaidOnly on the shared enum.
        $values = array_map(static fn (AiCostPolicy $c): string => $c->value, AiCostPolicy::cases());
        self::assertSame(['default', 'free_only'], $values);
        self::assertSame('normal', AiCostPolicy::Default->generationModeValue());
        self::assertSame('free_only', AiCostPolicy::FreeOnly->generationModeValue());
    }
}
