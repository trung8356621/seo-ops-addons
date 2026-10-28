<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;

/**
 * Global Monthly Auto Cost policy — FREE → PAID → AUTO RERUN.
 *
 * Owned by GlobalMonthly. Does not alter Individual Free-Split / Paid routing.
 */
final class GlobalAutoGenerationPolicy
{
    public const STRATEGY_FREE = 'free_split';

    public const STRATEGY_PAID = 'paid';

    public const ACTION_RUN_FREE = 'run_free';

    public const ACTION_RUN_PAID = 'run_paid';

    public const ACTION_WAITING_RETRY = 'waiting_retry';

    public const ACTION_SUCCEEDED = 'succeeded';

    public const ACTION_CONTINUE_NEXT = 'continue_next';

    public const ACTION_STOP_AI_UNAVAILABLE = 'stop_ai_unavailable';

    public const ACTION_STOP_SYSTEM = 'stop_system';

    public const ACTION_STOP_USER = 'stop_user';

    public const ACTION_WAIT_COOLDOWN = 'wait_cooldown';

    public const ACTION_PERMANENT_FAIL = 'permanent_fail';

    /** Max Free→Paid rounds per article before permanent fail. */
    public const MAX_RERUN_ROUNDS = 5;

    /** Consecutive Free rate-limit failures before opening circuit. */
    public const FREE_CIRCUIT_TRIP_THRESHOLD = 3;

    /** Base Free circuit cooldown seconds. */
    public const FREE_CIRCUIT_COOLDOWN_SECONDS = 300;

    /** Cap for retry backoff seconds. */
    public const MAX_BACKOFF_SECONDS = 1800;

    /**
     * @param  array{
     *   free_attempted?: bool,
     *   paid_attempted?: bool,
     *   retry_count?: int,
     *   strategy?: ?string,
     *   free_exhausted?: bool,
     *   paid_exhausted?: bool,
     *   free_circuit_open_until?: ?int,
     *   consecutive_free_rate_limits?: int,
     *   stop_requested?: bool,
     *   system_failure?: bool,
     *   now_ts?: int,
     * }  $state
     * @return array{
     *   action: string,
     *   strategy: ?string,
     *   next_retry_at: ?int,
     *   free_exhausted: bool,
     *   paid_exhausted: bool,
     *   free_circuit_open_until: ?int,
     *   consecutive_free_rate_limits: int,
     *   reason: string,
     * }
     */
    public function decideNextAttempt(array $state): array
    {
        $now = (int) ($state['now_ts'] ?? time());
        $freeExhausted = (bool) ($state['free_exhausted'] ?? false);
        $paidExhausted = (bool) ($state['paid_exhausted'] ?? false);
        $circuitUntil = isset($state['free_circuit_open_until']) ? (int) $state['free_circuit_open_until'] : null;
        $consecutiveRl = (int) ($state['consecutive_free_rate_limits'] ?? 0);
        $retryCount = (int) ($state['retry_count'] ?? 0);
        $freeAttempted = (bool) ($state['free_attempted'] ?? false);
        $paidAttempted = (bool) ($state['paid_attempted'] ?? false);

        if (! empty($state['stop_requested'])) {
            return $this->decision(self::ACTION_STOP_USER, null, null, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, 'user_stop');
        }

        if (! empty($state['system_failure'])) {
            return $this->decision(self::ACTION_STOP_SYSTEM, null, null, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, 'system_failure');
        }

        if ($freeExhausted && $paidExhausted) {
            return $this->decision(self::ACTION_STOP_AI_UNAVAILABLE, null, null, true, true, $circuitUntil, $consecutiveRl, 'both_exhausted');
        }

        if ($retryCount >= self::MAX_RERUN_ROUNDS && $freeAttempted && $paidAttempted) {
            return $this->decision(self::ACTION_PERMANENT_FAIL, null, null, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, 'max_rerun_rounds');
        }

        $circuitOpen = $circuitUntil !== null && $circuitUntil > $now;
        $freeAvailable = ! $freeExhausted && ! $circuitOpen;

        // Fresh article or new round: prefer Free when available.
        if (! $freeAttempted) {
            if ($freeAvailable) {
                return $this->decision(self::ACTION_RUN_FREE, self::STRATEGY_FREE, null, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, 'prefer_free');
            }
            if (! $paidExhausted) {
                return $this->decision(self::ACTION_RUN_PAID, self::STRATEGY_PAID, null, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, $circuitOpen ? 'free_circuit_open_use_paid' : 'free_unavailable_use_paid');
            }

            // Paid exhausted + Free in cooldown → wait for Free re-probe.
            if ($circuitOpen) {
                return $this->decision(self::ACTION_WAIT_COOLDOWN, null, $circuitUntil, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, 'wait_free_circuit');
            }

            return $this->decision(self::ACTION_STOP_AI_UNAVAILABLE, null, null, $freeExhausted, true, $circuitUntil, $consecutiveRl, 'no_ai_available');
        }

        // Free failed this round → Paid if not yet attempted and available.
        if ($freeAttempted && ! $paidAttempted) {
            if (! $paidExhausted) {
                return $this->decision(self::ACTION_RUN_PAID, self::STRATEGY_PAID, null, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, 'free_failed_try_paid');
            }
            if ($freeAvailable || ($circuitUntil !== null && $circuitUntil > $now)) {
                $next = $circuitUntil !== null && $circuitUntil > $now
                    ? $circuitUntil
                    : $this->computeNextRetryAt($retryCount, $now);

                return $this->decision(self::ACTION_WAITING_RETRY, null, $next, $freeExhausted, true, $circuitUntil, $consecutiveRl, 'paid_exhausted_wait_free');
            }

            return $this->decision(self::ACTION_STOP_AI_UNAVAILABLE, null, null, $freeExhausted, true, $circuitUntil, $consecutiveRl, 'paid_exhausted_free_gone');
        }

        // Both attempted this round → waiting_retry (unless AI fully unavailable).
        if ($freeExhausted && $paidExhausted) {
            return $this->decision(self::ACTION_STOP_AI_UNAVAILABLE, null, null, true, true, $circuitUntil, $consecutiveRl, 'both_exhausted_after_attempts');
        }

        $next = $this->computeNextRetryAt($retryCount, $now);

        return $this->decision(self::ACTION_WAITING_RETRY, null, $next, $freeExhausted, $paidExhausted, $circuitUntil, $consecutiveRl, 'both_failed_schedule_rerun');
    }

    /**
     * Apply attempt outcome and update availability / circuit state.
     *
     * @param  array{
     *   strategy: string,
     *   success: bool,
     *   error_category?: ?string,
     *   free_exhausted?: bool,
     *   paid_exhausted?: bool,
     *   free_circuit_open_until?: ?int,
     *   consecutive_free_rate_limits?: int,
     *   free_attempted?: bool,
     *   paid_attempted?: bool,
     *   retry_count?: int,
     *   now_ts?: int,
     *   free_candidates_available?: ?bool,
     * }  $outcome
     * @return array{
     *   action: string,
     *   strategy: ?string,
     *   next_retry_at: ?int,
     *   free_exhausted: bool,
     *   paid_exhausted: bool,
     *   free_circuit_open_until: ?int,
     *   consecutive_free_rate_limits: int,
     *   free_attempted: bool,
     *   paid_attempted: bool,
     *   retry_count: int,
     *   reason: string,
     *   persist_once: bool,
     * }
     */
    public function afterAttempt(array $outcome): array
    {
        $now = (int) ($outcome['now_ts'] ?? time());
        $strategy = (string) ($outcome['strategy'] ?? self::STRATEGY_FREE);
        $success = (bool) ($outcome['success'] ?? false);
        $category = $this->normalizeCategory($outcome['error_category'] ?? null);
        $freeExhausted = (bool) ($outcome['free_exhausted'] ?? false);
        $paidExhausted = (bool) ($outcome['paid_exhausted'] ?? false);
        $circuitUntil = isset($outcome['free_circuit_open_until']) ? (int) $outcome['free_circuit_open_until'] : null;
        $consecutiveRl = (int) ($outcome['consecutive_free_rate_limits'] ?? 0);
        $freeAttempted = (bool) ($outcome['free_attempted'] ?? false);
        $paidAttempted = (bool) ($outcome['paid_attempted'] ?? false);
        $retryCount = (int) ($outcome['retry_count'] ?? 0);
        $freeCandidatesAvailable = array_key_exists('free_candidates_available', $outcome)
            ? $outcome['free_candidates_available']
            : null;

        if ($strategy === self::STRATEGY_FREE) {
            $freeAttempted = true;
        } else {
            $paidAttempted = true;
        }

        if ($success) {
            return [
                'action' => self::ACTION_SUCCEEDED,
                'strategy' => $strategy,
                'next_retry_at' => null,
                'free_exhausted' => $freeExhausted,
                'paid_exhausted' => $paidExhausted,
                'free_circuit_open_until' => $circuitUntil,
                'consecutive_free_rate_limits' => 0,
                'free_attempted' => $freeAttempted,
                'paid_attempted' => $paidAttempted,
                'retry_count' => $retryCount,
                'reason' => $strategy === self::STRATEGY_FREE ? 'free_success' : 'paid_success',
                'persist_once' => true,
            ];
        }

        // Failure classification → availability / circuit
        if ($strategy === self::STRATEGY_FREE) {
            if ($this->isFreeRateLimitCategory($category)) {
                $consecutiveRl++;
                if ($consecutiveRl >= self::FREE_CIRCUIT_TRIP_THRESHOLD) {
                    $circuitUntil = $now + self::FREE_CIRCUIT_COOLDOWN_SECONDS;
                }
            } else {
                $consecutiveRl = 0;
            }

            // free_exhausted only with strong evidence — never from a single article/model failure.
            if ($category === AiFailureClass::DailyFreeQuotaExhausted->value
                && $freeCandidatesAvailable === false
            ) {
                $freeExhausted = true;
            } elseif ($category === AiFailureClass::RoutesExhausted->value
                && $freeCandidatesAvailable === false
            ) {
                $freeExhausted = true;
            }
            // Single 429 / timeout / empty output / model_not_found → NOT free_exhausted.
        } else {
            if ($this->isPaidBillingExhaustedCategory($category)) {
                $paidExhausted = true;
            }
            // 429/500/502/timeout on Paid → NOT paid_exhausted.
        }

        $next = $this->decideNextAttempt([
            'free_attempted' => $freeAttempted,
            'paid_attempted' => $paidAttempted,
            'retry_count' => $retryCount,
            'free_exhausted' => $freeExhausted,
            'paid_exhausted' => $paidExhausted,
            'free_circuit_open_until' => $circuitUntil,
            'consecutive_free_rate_limits' => $consecutiveRl,
            'now_ts' => $now,
        ]);

        // Entering waiting_retry increments round counter for the next wake.
        $outRetry = $retryCount;
        if ($next['action'] === self::ACTION_WAITING_RETRY) {
            $outRetry = $retryCount + 1;
            // Reset per-round attempt flags so next wake retries Free→Paid.
            $freeAttempted = false;
            $paidAttempted = false;
        }

        return [
            'action' => $next['action'],
            'strategy' => $next['strategy'],
            'next_retry_at' => $next['next_retry_at'],
            'free_exhausted' => $next['free_exhausted'],
            'paid_exhausted' => $next['paid_exhausted'],
            'free_circuit_open_until' => $next['free_circuit_open_until'],
            'consecutive_free_rate_limits' => $next['consecutive_free_rate_limits'],
            'free_attempted' => $freeAttempted,
            'paid_attempted' => $paidAttempted,
            'retry_count' => $outRetry,
            'reason' => $next['reason'],
            'persist_once' => false,
        ];
    }

    /**
     * Expire Free circuit for re-probe after cooldown.
     *
     * @param  array{free_circuit_open_until?: ?int, consecutive_free_rate_limits?: int, now_ts?: int}  $state
     * @return array{free_circuit_open_until: ?int, consecutive_free_rate_limits: int, re_probe: bool}
     */
    public function expireFreeCircuit(array $state): array
    {
        $now = (int) ($state['now_ts'] ?? time());
        $until = isset($state['free_circuit_open_until']) ? (int) $state['free_circuit_open_until'] : null;
        $consecutive = (int) ($state['consecutive_free_rate_limits'] ?? 0);

        if ($until !== null && $until <= $now) {
            return [
                'free_circuit_open_until' => null,
                'consecutive_free_rate_limits' => 0,
                're_probe' => true,
            ];
        }

        return [
            'free_circuit_open_until' => $until,
            'consecutive_free_rate_limits' => $consecutive,
            're_probe' => false,
        ];
    }

    /**
     * Child Project Run settings for one Auto Cost attempt.
     *
     * Free → AiCostPolicy::FreeOnly (no Paid).
     * Paid → paid_preferred generation mode so routing never selects Free models /
     *        Free Pool connections (Default alone is NOT Paid-only).
     *
     * @return array<string, mixed>
     */
    public function childRunSettingsForStrategy(string $strategy): array
    {
        if ($strategy === self::STRATEGY_FREE) {
            return array_merge(GlobalMonthlyAiPolicy::childRunSettings(AiCostPolicy::FreeOnly), [
                'global_auto_cost_strategy' => self::STRATEGY_FREE,
                'global_auto_cost_paid_only' => false,
            ]);
        }

        // Explicit Paid-only constraint scoped to Global Auto Cost Paid attempts.
        // Individual Generator keeps its own Normal/FreeOnly mapping unchanged.
        return [
            AiCostPolicy::SETTING_KEY => AiCostPolicy::Default->value,
            'ai_generation_mode' => 'paid_preferred',
            '_item_generation_mode' => 'paid_preferred',
            'global_auto_cost_strategy' => self::STRATEGY_PAID,
            'global_auto_cost_paid_only' => true,
        ];
    }

    /**
     * Whether child-run settings enforce Paid-only routing (no Free models).
     *
     * @param  array<string, mixed>  $settings
     */
    public function settingsEnforcePaidOnly(array $settings): bool
    {
        if (! empty($settings['global_auto_cost_paid_only'])) {
            return true;
        }

        $mode = strtolower(trim((string) ($settings['ai_generation_mode'] ?? $settings['_item_generation_mode'] ?? '')));

        return in_array($mode, ['paid_preferred', 'paid', 'best_quality', 'quality'], true)
            && ! AiCostPolicy::tryFromMixed($settings[AiCostPolicy::SETTING_KEY] ?? null)->isFreeOnly();
    }

    /**
     * Filter fake/real candidates for a strategy — executable Paid isolation contract.
     *
     * @param  list<object{isFree: bool}|array{is_free?: bool, isFree?: bool}>  $candidates
     * @return list<object|array>
     */
    public function filterCandidatesForStrategy(string $strategy, array $candidates): array
    {
        $wantFree = $strategy === self::STRATEGY_FREE;

        return array_values(array_filter($candidates, static function (mixed $candidate) use ($wantFree): bool {
            if (is_object($candidate) && property_exists($candidate, 'isFree')) {
                return ((bool) $candidate->isFree) === $wantFree;
            }
            if (is_array($candidate)) {
                $isFree = (bool) ($candidate['is_free'] ?? $candidate['isFree'] ?? false);

                return $isFree === $wantFree;
            }

            return false;
        }));
    }

    public function computeNextRetryAt(int $retryCount, ?int $nowTs = null, ?int $jitterSeconds = null): int
    {
        $now = $nowTs ?? time();
        $exp = max(0, $retryCount);
        $base = (int) min(self::MAX_BACKOFF_SECONDS, 60 * (2 ** min($exp, 5)));
        $jitter = $jitterSeconds ?? random_int(0, 30);

        return $now + $base + $jitter;
    }

    public function isFreeRateLimitCategory(?string $category): bool
    {
        $c = $this->normalizeCategory($category);

        return in_array($c, [
            AiFailureClass::RateLimited->value,
            AiFailureClass::DailyFreeQuotaExhausted->value,
        ], true);
    }

    public function isPaidBillingExhaustedCategory(?string $category): bool
    {
        $c = $this->normalizeCategory($category);

        return in_array($c, [
            AiFailureClass::BillingExhausted->value,
            // Account-wide paid lock only — not request-scoped budget nudges alone.
        ], true);
    }

    /**
     * Infer failure category from provider-ish signals (fake-provider friendly).
     *
     * @param  array{http_status?: int|null, message?: string|null, failure_class?: string|null, code?: string|null}  $signals
     */
    public function classifyErrorCategory(array $signals): string
    {
        $explicit = $this->normalizeCategory($signals['failure_class'] ?? $signals['code'] ?? null);
        if ($explicit !== '') {
            return $explicit;
        }

        $http = isset($signals['http_status']) ? (int) $signals['http_status'] : 0;
        $lower = strtolower(trim((string) ($signals['message'] ?? '')));

        if ($http === 402
            || str_contains($lower, 'insufficient credit')
            || str_contains($lower, 'insufficient credits')
            || str_contains($lower, 'billing')
            || str_contains($lower, 'payment required')
        ) {
            return AiFailureClass::BillingExhausted->value;
        }

        if ($http === 429 || str_contains($lower, 'rate limit') || str_contains($lower, 'too many requests')) {
            return AiFailureClass::RateLimited->value;
        }

        if (str_contains($lower, 'free-models-per-day') || str_contains($lower, 'daily free quota')) {
            return AiFailureClass::DailyFreeQuotaExhausted->value;
        }

        if ($http === 404 || str_contains($lower, 'model not found') || str_contains($lower, 'model unavailable')) {
            return AiFailureClass::ModelNotFound->value;
        }

        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return AiFailureClass::TransientProvider->value;
        }

        if ($http === 500 || $http === 502 || $http === 503) {
            return AiFailureClass::TransientProvider->value;
        }

        if (str_contains($lower, 'empty output') || str_contains($lower, 'empty response')) {
            return AiFailureClass::ProviderEmptyOutput->value;
        }

        if (str_contains($lower, 'validation') || str_contains($lower, 'invalid output')) {
            return AiFailureClass::ProviderInvalidOutput->value;
        }

        if ($this->isInfrastructureFailure(AiFailureClass::SystemError->value, $lower)) {
            return AiFailureClass::SystemError->value;
        }

        return AiFailureClass::SystemError->value;
    }

    /**
     * Schema/connection/auth infrastructure failures must fail-fast at Global Batch scope.
     */
    public function isInfrastructureFailure(?string $category, ?string $message): bool
    {
        $cat = $this->normalizeCategory($category);
        $lower = strtolower(trim((string) $message));

        if ($cat === AiFailureClass::SystemError->value) {
            if ($lower === '') {
                return false;
            }
        }

        if (str_contains($lower, 'sqlstate[42s02]')
            || str_contains($lower, 'base table or view not found')
            || str_contains($lower, "doesn't exist")
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'sqlstate[42s22]')
            || str_contains($lower, 'sqlstate[hy000]')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'could not find driver')
            || str_contains($lower, 'access denied for user')
            || str_contains($lower, 'seo_connection_users')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Build attempt history row (stored on batch item attempt_meta).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function buildAttemptRecord(
        int $batchItemId,
        string $strategy,
        int $attemptNumber,
        ?string $provider,
        ?string $model,
        string $result,
        ?string $errorCategory,
        ?int $startedTs,
        ?int $finishedTs,
        ?array $usage = null,
        array $extra = [],
    ): array {
        return array_merge([
            'batch_item_id' => $batchItemId,
            'strategy' => $strategy,
            'attempt_number' => $attemptNumber,
            'provider' => $provider,
            'model' => $model,
            'result' => $result,
            'error_category' => $errorCategory,
            'started_at' => $startedTs,
            'finished_at' => $finishedTs,
            'usage' => $usage,
        ], $extra);
    }

    /**
     * @return array{
     *   action: string,
     *   strategy: ?string,
     *   next_retry_at: ?int,
     *   free_exhausted: bool,
     *   paid_exhausted: bool,
     *   free_circuit_open_until: ?int,
     *   consecutive_free_rate_limits: int,
     *   reason: string,
     * }
     */
    private function decision(
        string $action,
        ?string $strategy,
        ?int $nextRetryAt,
        bool $freeExhausted,
        bool $paidExhausted,
        ?int $circuitUntil,
        int $consecutiveRl,
        string $reason,
    ): array {
        return [
            'action' => $action,
            'strategy' => $strategy,
            'next_retry_at' => $nextRetryAt,
            'free_exhausted' => $freeExhausted,
            'paid_exhausted' => $paidExhausted,
            'free_circuit_open_until' => $circuitUntil,
            'consecutive_free_rate_limits' => $consecutiveRl,
            'reason' => $reason,
        ];
    }

    private function normalizeCategory(mixed $value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }
}
