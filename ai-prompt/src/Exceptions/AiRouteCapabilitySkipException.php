<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

use Omnichannel\Addons\AiPrompt\DataTransfer\PromptBudgetPlan;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;

/**
 * Pre-execution capability skip — do not call provider; try next route.
 * Not a provider failure; not AI_ROUTES_EXHAUSTED from output errors.
 */
final class AiRouteCapabilitySkipException extends PromptRunException
{
    public function __construct(
        string $reason,
        public readonly ?PromptBudgetPlan $plan = null,
    ) {
        parent::__construct(
            'AI_ROUTE_CAPABILITY_SKIP: '.$reason,
            0,
            null,
            [
                'classification' => AiFailureClass::ContextLimitExceeded->value,
                'retryable' => true,
                'capability_skip' => true,
                'skip_reason' => $reason,
                'budget' => $plan?->toDiagnostics() ?? [],
            ],
        );
    }
}
