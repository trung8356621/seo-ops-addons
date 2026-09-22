<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Bounded attempt budget for interactive (synchronous) Prompt execution.
 *
 * Background / article workloads keep resilience settings unchanged.
 */
final class InteractiveAiAttemptBudget
{
    public const DEFAULT_MAX_AI_ATTEMPTS = 3;

    public const DEFAULT_MAX_FREE_ATTEMPTS = 1;

    /**
     * @return array{max_ai_attempts: int, max_free_attempts: int}
     */
    public function resolve(?AiRoutingPolicy $policy = null): array
    {
        $maxFree = self::DEFAULT_MAX_FREE_ATTEMPTS;
        $policyCap = $policy?->freeAttemptCap();
        if ($policyCap !== null) {
            $maxFree = min($maxFree, max(0, $policyCap));
        }

        return [
            'max_ai_attempts' => self::DEFAULT_MAX_AI_ATTEMPTS,
            'max_free_attempts' => $maxFree,
        ];
    }
}
