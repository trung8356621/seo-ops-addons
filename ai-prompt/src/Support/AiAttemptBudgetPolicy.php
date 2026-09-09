<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Attempt-budget math for FREE_FIRST / mixed routing.
 *
 * One ACTUAL provider API call = one attempt.
 * Health/policy/disabled skips consume zero attempts.
 *
 * Invariant for free-first:
 * - free attempts capped by MAX_FREE_ATTEMPTS
 * - total attempts capped by MAX_AI_ATTEMPTS
 * - when a genuinely eligible paid fallback exists, preserve at least ONE paid opportunity
 *   (never reserve one slot per paid physical candidate)
 */
final class AiAttemptBudgetPolicy
{
    /**
     * @return array{
     *     max_ai_attempts: int,
     *     max_free_attempts: int,
     *     reserved_paid_slots: int,
     *     free_budget: int,
     *     required_paid_fallback_reserve: int
     * }
     */
    public function resolve(
        int $maxAiAttempts,
        int $maxFreeAttempts,
        bool $genuinelyEligiblePaidFallbackExists,
        AiExecutionRoutingMode $mode,
    ): array {
        $maxAi = max(1, $maxAiAttempts);
        $maxFree = max(0, min($maxFreeAttempts, $maxAi));

        if ($mode === AiExecutionRoutingMode::FreeOnly || ! $genuinelyEligiblePaidFallbackExists) {
            return [
                'max_ai_attempts' => $maxAi,
                'max_free_attempts' => $maxFree,
                'reserved_paid_slots' => 0,
                'free_budget' => min($maxFree, $maxAi),
                'required_paid_fallback_reserve' => 0,
            ];
        }

        if (! $mode->requiresPaidFallbackReserve()) {
            // Paid-preferred / explicit: free may still appear as fallback if present,
            // but do not reserve paid slots against the free budget.
            return [
                'max_ai_attempts' => $maxAi,
                'max_free_attempts' => $maxFree,
                'reserved_paid_slots' => 0,
                'free_budget' => min($maxFree, $maxAi),
                'required_paid_fallback_reserve' => 0,
            ];
        }

        // FREE_FIRST: reserve exactly 1 global attempt for paid fallback when needed.
        $requiredPaidFallbackReserve = 1;
        $freeBudget = min(
            $maxFree,
            max(0, $maxAi - $requiredPaidFallbackReserve),
        );

        return [
            'max_ai_attempts' => $maxAi,
            'max_free_attempts' => $maxFree,
            'reserved_paid_slots' => $requiredPaidFallbackReserve,
            'free_budget' => $freeBudget,
            'required_paid_fallback_reserve' => $requiredPaidFallbackReserve,
        ];
    }

    /**
     * When no remaining paid candidate is attemptable, reclaim the reserved slot
     * so remaining free routes may use the full total budget.
     *
     * @param  array{
     *     max_ai_attempts: int,
     *     max_free_attempts: int,
     *     reserved_paid_slots: int,
     *     free_budget: int,
     *     required_paid_fallback_reserve: int
     * }  $budget
     * @return array{
     *     max_ai_attempts: int,
     *     max_free_attempts: int,
     *     reserved_paid_slots: int,
     *     free_budget: int,
     *     required_paid_fallback_reserve: int
     * }
     */
    public function reclaimPaidReserveWhenNoPaidRemain(array $budget): array
    {
        if ((int) ($budget['reserved_paid_slots'] ?? 0) <= 0) {
            return $budget;
        }

        $maxAi = (int) $budget['max_ai_attempts'];
        $maxFree = (int) $budget['max_free_attempts'];

        return [
            'max_ai_attempts' => $maxAi,
            'max_free_attempts' => $maxFree,
            'reserved_paid_slots' => 0,
            'free_budget' => min($maxFree, $maxAi),
            'required_paid_fallback_reserve' => 0,
        ];
    }
}
