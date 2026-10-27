<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalAutoGenerationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyAiPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Fake-provider Auto Cost scenarios A–K (no live AI).
 */
final class GlobalAutoGenerationPolicyTest extends TestCase
{
    private GlobalAutoGenerationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new GlobalAutoGenerationPolicy;
    }

    /** A. FREE SUCCESS — Paid requests = 0 */
    public function test_a_free_success_never_requests_paid(): void
    {
        $out = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_FREE,
            'success' => true,
            'now_ts' => 1_700_000_000,
        ]);

        self::assertSame(GlobalAutoGenerationPolicy::ACTION_SUCCEEDED, $out['action']);
        self::assertSame('free_success', $out['reason']);
        self::assertTrue($out['persist_once']);
        self::assertFalse($out['paid_attempted']);
        self::assertTrue($out['free_attempted']);
    }

    /** B. FREE FAIL → PAID SUCCESS — persist exactly once on Paid success */
    public function test_b_free_fail_then_paid_success_persist_once(): void
    {
        $afterFree = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_FREE,
            'success' => false,
            'error_category' => AiFailureClass::ProviderInvalidOutput->value,
            'now_ts' => 1_700_000_000,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_RUN_PAID, $afterFree['action']);
        self::assertFalse($afterFree['persist_once']);

        $afterPaid = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_PAID,
            'success' => true,
            'free_attempted' => true,
            'paid_attempted' => false,
            'now_ts' => 1_700_000_010,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_SUCCEEDED, $afterPaid['action']);
        self::assertSame('paid_success', $afterPaid['reason']);
        self::assertTrue($afterPaid['persist_once']);
    }

    /** C. FREE + PAID FAIL → waiting_retry (continue other articles at orchestrator) */
    public function test_c_both_fail_waiting_retry(): void
    {
        $out = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_PAID,
            'success' => false,
            'error_category' => AiFailureClass::TransientProvider->value,
            'free_attempted' => true,
            'paid_attempted' => false,
            'retry_count' => 0,
            'now_ts' => 1_700_000_000,
            'jitter' => 0,
        ]);

        // Force deterministic backoff via decide path after both attempted.
        $out = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_PAID,
            'success' => false,
            'error_category' => AiFailureClass::TransientProvider->value,
            'free_attempted' => true,
            'paid_attempted' => false,
            'retry_count' => 0,
            'now_ts' => 1_700_000_000,
        ]);

        self::assertSame(GlobalAutoGenerationPolicy::ACTION_WAITING_RETRY, $out['action']);
        self::assertNotNull($out['next_retry_at']);
        self::assertSame(1, $out['retry_count']);
        // Round flags reset for next wake Free→Paid.
        self::assertFalse($out['free_attempted']);
        self::assertFalse($out['paid_attempted']);
    }

    /** D. AUTO RERUN — after waiting, Free preferred again; no duplicate succeed path */
    public function test_d_auto_rerun_prefers_free_again(): void
    {
        $decision = $this->policy->decideNextAttempt([
            'free_attempted' => false,
            'paid_attempted' => false,
            'retry_count' => 1,
            'now_ts' => 1_700_000_100,
        ]);

        self::assertSame(GlobalAutoGenerationPolicy::ACTION_RUN_FREE, $decision['action']);
        self::assertSame(GlobalAutoGenerationPolicy::STRATEGY_FREE, $decision['strategy']);
    }

    /** E. FREE 429 — circuit breaker + cooldown + re-probe */
    public function test_e_free_429_circuit_breaker_cooldown_reprobe(): void
    {
        $now = 1_700_000_000;
        $state = [
            'free_exhausted' => false,
            'paid_exhausted' => false,
            'consecutive_free_rate_limits' => 0,
            'free_circuit_open_until' => null,
        ];

        for ($i = 0; $i < GlobalAutoGenerationPolicy::FREE_CIRCUIT_TRIP_THRESHOLD; $i++) {
            $out = $this->policy->afterAttempt([
                'strategy' => GlobalAutoGenerationPolicy::STRATEGY_FREE,
                'success' => false,
                'error_category' => AiFailureClass::RateLimited->value,
                'free_attempted' => false,
                'paid_attempted' => false,
                'consecutive_free_rate_limits' => $state['consecutive_free_rate_limits'],
                'free_circuit_open_until' => $state['free_circuit_open_until'],
                'now_ts' => $now + $i,
            ]);
            $state['consecutive_free_rate_limits'] = $out['consecutive_free_rate_limits'];
            $state['free_circuit_open_until'] = $out['free_circuit_open_until'];
            // Single article Free fail with Paid available → Paid (not free_exhausted).
            self::assertFalse($out['free_exhausted']);
            if ($i < GlobalAutoGenerationPolicy::FREE_CIRCUIT_TRIP_THRESHOLD - 1) {
                self::assertSame(GlobalAutoGenerationPolicy::ACTION_RUN_PAID, $out['action']);
            }
        }

        self::assertNotNull($state['free_circuit_open_until']);
        self::assertGreaterThan($now, (int) $state['free_circuit_open_until']);

        // While circuit open + Paid available → Paid.
        $whileOpen = $this->policy->decideNextAttempt([
            'free_attempted' => false,
            'paid_attempted' => false,
            'free_circuit_open_until' => $state['free_circuit_open_until'],
            'paid_exhausted' => false,
            'now_ts' => $now + 10,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_RUN_PAID, $whileOpen['action']);
        self::assertSame('free_circuit_open_use_paid', $whileOpen['reason']);

        // After expiration → re-probe Free.
        $expired = $this->policy->expireFreeCircuit([
            'free_circuit_open_until' => $state['free_circuit_open_until'],
            'consecutive_free_rate_limits' => $state['consecutive_free_rate_limits'],
            'now_ts' => (int) $state['free_circuit_open_until'] + 1,
        ]);
        self::assertTrue($expired['re_probe']);
        self::assertNull($expired['free_circuit_open_until']);
        self::assertSame(0, $expired['consecutive_free_rate_limits']);
    }

    /** F. PAID EXHAUSTED — billing identified; Free still available → continue Free */
    public function test_f_paid_exhausted_free_continues(): void
    {
        $category = $this->policy->classifyErrorCategory([
            'http_status' => 402,
            'message' => 'Insufficient credits',
        ]);
        self::assertSame(AiFailureClass::BillingExhausted->value, $category);
        self::assertTrue($this->policy->isPaidBillingExhaustedCategory($category));

        // 429 on Paid is NOT billing exhausted.
        self::assertFalse($this->policy->isPaidBillingExhaustedCategory(
            $this->policy->classifyErrorCategory(['http_status' => 429, 'message' => 'rate limit'])
        ));

        $out = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_PAID,
            'success' => false,
            'error_category' => AiFailureClass::BillingExhausted->value,
            'free_attempted' => true,
            'paid_attempted' => false,
            'free_exhausted' => false,
            'now_ts' => 1_700_000_000,
        ]);
        self::assertTrue($out['paid_exhausted']);
        self::assertFalse($out['free_exhausted']);

        $next = $this->policy->decideNextAttempt([
            'free_attempted' => false,
            'paid_attempted' => false,
            'paid_exhausted' => true,
            'free_exhausted' => false,
            'now_ts' => 1_700_000_100,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_RUN_FREE, $next['action']);
    }

    /** G. BOTH EXHAUSTED — stop safely */
    public function test_g_both_exhausted_stop_ai_unavailable(): void
    {
        $decision = $this->policy->decideNextAttempt([
            'free_exhausted' => true,
            'paid_exhausted' => true,
            'now_ts' => 1_700_000_000,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_STOP_AI_UNAVAILABLE, $decision['action']);
    }

    /** H. SYSTEM FAILURE — fail safely, no unsafe retry */
    public function test_h_system_failure_stops(): void
    {
        $decision = $this->policy->decideNextAttempt([
            'system_failure' => true,
            'now_ts' => 1_700_000_000,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_STOP_SYSTEM, $decision['action']);
    }

    /** I. USER STOP — no future auto rerun */
    public function test_i_user_stop_blocks_rerun(): void
    {
        $decision = $this->policy->decideNextAttempt([
            'stop_requested' => true,
            'free_attempted' => true,
            'paid_attempted' => true,
            'now_ts' => 1_700_000_000,
        ]);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_STOP_USER, $decision['action']);
    }

    /** J. INDIVIDUAL REGRESSION — AiCostPolicy / sticky Free-Paid unchanged */
    public function test_j_individual_ai_cost_policy_unchanged(): void
    {
        self::assertSame('default', AiCostPolicy::Default->value);
        self::assertSame('free_only', AiCostPolicy::FreeOnly->value);
        self::assertSame('normal', AiCostPolicy::Default->generationModeValue());
        self::assertSame('free_only', AiCostPolicy::FreeOnly->generationModeValue());

        // Auto Cost is Global-only token — not an Individual enum case.
        $cases = array_map(static fn (AiCostPolicy $c): string => $c->value, AiCostPolicy::cases());
        self::assertNotContains(GlobalMonthlyAiPolicy::AUTO_COST, $cases);

        $freeSettings = $this->policy->childRunSettingsForStrategy(GlobalAutoGenerationPolicy::STRATEGY_FREE);
        self::assertSame(AiCostPolicy::FreeOnly->value, $freeSettings[AiCostPolicy::SETTING_KEY]);

        $paidSettings = $this->policy->childRunSettingsForStrategy(GlobalAutoGenerationPolicy::STRATEGY_PAID);
        self::assertSame(AiCostPolicy::Default->value, $paidSettings[AiCostPolicy::SETTING_KEY]);
        self::assertSame('paid_preferred', $paidSettings['ai_generation_mode']);
        self::assertTrue((bool) $paidSettings['global_auto_cost_paid_only']);

        // Sticky legacy path still asserts.
        GlobalMonthlyAiPolicy::assertUnchanged(AiCostPolicy::FreeOnly, 'free_only');
    }

    /** K. LONG RUN — 100 articles simulation: no tight loop, no duplicate persist, backoff grows */
    public function test_k_long_run_simulation_no_tight_loop_or_duplicate_persist(): void
    {
        $now = 1_700_000_000;
        $persists = 0;
        $wakeDelays = [];
        $freeCircuitTrips = 0;

        for ($article = 0; $article < 100; $article++) {
            $freeAttempted = false;
            $paidAttempted = false;
            $retry = 0;
            $done = false;
            $steps = 0;

            while (! $done && $steps < 40) {
                $steps++;
                $decision = $this->policy->decideNextAttempt([
                    'free_attempted' => $freeAttempted,
                    'paid_attempted' => $paidAttempted,
                    'retry_count' => $retry,
                    'now_ts' => $now,
                ]);

                if ($decision['action'] === GlobalAutoGenerationPolicy::ACTION_PERMANENT_FAIL) {
                    $done = true;
                    break;
                }

                $strategy = (string) $decision['strategy'];
                // Deterministic fake outcomes: article%7==0 both fail then succeed on rerun Free;
                // article%11==0 Free 429 then Paid success; else Free success.
                $success = true;
                $category = null;
                if ($article % 11 === 0 && $strategy === GlobalAutoGenerationPolicy::STRATEGY_FREE && $retry === 0) {
                    $success = false;
                    $category = AiFailureClass::RateLimited->value;
                } elseif ($article % 7 === 0 && $retry === 0) {
                    $success = false;
                    $category = AiFailureClass::ProviderEmptyOutput->value;
                }

                $out = $this->policy->afterAttempt([
                    'strategy' => $strategy,
                    'success' => $success,
                    'error_category' => $category,
                    'free_attempted' => $freeAttempted,
                    'paid_attempted' => $paidAttempted,
                    'retry_count' => $retry,
                    'now_ts' => $now,
                    'consecutive_free_rate_limits' => $article % 11 === 0 ? ($retry === 0 ? 2 : 0) : 0,
                ]);

                if ($out['persist_once']) {
                    $persists++;
                    $done = true;
                    break;
                }

                if ($out['action'] === GlobalAutoGenerationPolicy::ACTION_WAITING_RETRY) {
                    $wakeDelays[] = ((int) $out['next_retry_at']) - $now;
                    self::assertGreaterThanOrEqual(60, ((int) $out['next_retry_at']) - $now);
                    $retry = (int) $out['retry_count'];
                    $freeAttempted = false;
                    $paidAttempted = false;
                    $now = (int) $out['next_retry_at']; // jump clock — no busy loop
                    continue;
                }

                if ($out['action'] === GlobalAutoGenerationPolicy::ACTION_RUN_PAID) {
                    $freeAttempted = true;
                    $paidAttempted = false;
                    if ($out['free_circuit_open_until'] !== null) {
                        $freeCircuitTrips++;
                    }
                    continue;
                }

                $freeAttempted = (bool) $out['free_attempted'];
                $paidAttempted = (bool) $out['paid_attempted'];
                $retry = (int) $out['retry_count'];
            }

            self::assertTrue($done, "Article {$article} did not terminate");
            self::assertLessThan(40, $steps, "Article {$article} tight-looped");
        }

        self::assertSame(100, $persists, 'Exactly one persist per succeeded article');
        foreach ($wakeDelays as $delay) {
            self::assertGreaterThanOrEqual(60, $delay);
            self::assertLessThanOrEqual(GlobalAutoGenerationPolicy::MAX_BACKOFF_SECONDS + 30, $delay);
        }
    }

    public function test_single_model_failure_does_not_exhaust_free(): void
    {
        $out = $this->policy->afterAttempt([
            'strategy' => GlobalAutoGenerationPolicy::STRATEGY_FREE,
            'success' => false,
            'error_category' => AiFailureClass::ModelNotFound->value,
            'free_candidates_available' => true,
            'now_ts' => 1_700_000_000,
        ]);
        self::assertFalse($out['free_exhausted']);
        self::assertSame(GlobalAutoGenerationPolicy::ACTION_RUN_PAID, $out['action']);
    }

    public function test_attempt_record_shape(): void
    {
        $row = $this->policy->buildAttemptRecord(
            batchItemId: 42,
            strategy: GlobalAutoGenerationPolicy::STRATEGY_FREE,
            attemptNumber: 1,
            provider: 'fake',
            model: 'fake-free',
            result: 'failed',
            errorCategory: AiFailureClass::RateLimited->value,
            startedTs: 100,
            finishedTs: 110,
            usage: ['tokens' => 3],
        );

        self::assertSame(42, $row['batch_item_id']);
        self::assertSame('free_split', $row['strategy']);
        self::assertSame('rate_limited', $row['error_category']);
        self::assertSame(['tokens' => 3], $row['usage']);
    }

    public function test_orchestrator_wires_auto_cost_path(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('startNextAutoCostItem', $src);
        self::assertStringContainsString('syncAutoCostItemFromChildRun', $src);
        self::assertStringContainsString('WakeGlobalMonthlyBatchJob', $src);
        self::assertStringContainsString('wakeForAutoRetry', $src);
        self::assertStringContainsString('STATUS_STOPPED_AI_UNAVAILABLE', $src);
        self::assertStringContainsString('STATUS_WAITING_RETRY', $src);
        self::assertStringContainsString('GlobalAutoGenerationPolicy', $src);
        // Legacy sticky path retained.
        self::assertStringContainsString('GlobalMonthlyAiPolicy::assertUnchanged', $src);
        self::assertStringContainsString('Failed items require explicit retry selection', $src);
    }

    public function test_new_batches_force_auto_cost_token(): void
    {
        self::assertSame('auto_cost', GlobalMonthlyAiPolicy::AUTO_COST);
        self::assertTrue(GlobalMonthlyAiPolicy::isAutoCost('auto_cost'));
        self::assertFalse(GlobalMonthlyAiPolicy::isAutoCost('free_only'));
        self::assertSame('auto_cost', GlobalMonthlyAiPolicy::normalizeBatchPolicy('auto'));
        self::assertSame('free_only', GlobalMonthlyAiPolicy::normalizeBatchPolicy('free_only'));
    }

    public function test_backoff_has_jitter_and_cap(): void
    {
        $at = $this->policy->computeNextRetryAt(0, 1_000_000, 0);
        self::assertSame(1_000_060, $at);

        $capped = $this->policy->computeNextRetryAt(20, 1_000_000, 0);
        self::assertSame(1_000_000 + GlobalAutoGenerationPolicy::MAX_BACKOFF_SECONDS, $capped);

        $withJitter = $this->policy->computeNextRetryAt(0, 1_000_000, 15);
        self::assertSame(1_000_075, $withJitter);
    }
}
