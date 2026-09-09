<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Policy for WHO may be tried — never executes provider APIs.
 *
 * Do not conflate "prefer free" with "free only".
 */
enum AiExecutionRoutingMode: string
{
    case FreeOnly = 'free_only';
    case FreeFirstWithPaidFallback = 'free_first_with_paid_fallback';
    case PaidPreferred = 'paid_preferred';
    case ExplicitModel = 'explicit_model';

    public function allowsPaidRoutes(): bool
    {
        return $this !== self::FreeOnly;
    }

    public function prefersFreeFirst(): bool
    {
        return $this === self::FreeFirstWithPaidFallback;
    }

    public function requiresPaidFallbackReserve(): bool
    {
        return $this === self::FreeFirstWithPaidFallback;
    }
}
