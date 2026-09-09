<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Cost / attempt POLICY for WHO may be tried — never rewrites model ORDER.
 *
 * Runtime logical order comes from AI Center sortable priority
 * ({@see AiModelPriorityService::areaEnabledModels}), not from this enum.
 *
 * FreeFirstWithPaidFallback (legacy name) = default economy budget policy:
 * free API calls are capped (MAX_FREE) and a paid reserve may apply when
 * eligible paid routes exist. It does NOT mean "run all free models first".
 *
 * FreeOnly = explicit cost policy that removes paid candidates.
 */
enum AiExecutionRoutingMode: string
{
    case FreeOnly = 'free_only';
    /** @deprecated Name only — does not imply free-first ordering. Prefer treating as default budget mode. */
    case FreeFirstWithPaidFallback = 'free_first_with_paid_fallback';
    case PaidPreferred = 'paid_preferred';
    case ExplicitModel = 'explicit_model';

    public function allowsPaidRoutes(): bool
    {
        return $this !== self::FreeOnly;
    }

    /**
     * Historical flag. Always false for ordering — manual sortable order wins.
     * Kept so callers that branched on "prefer free" stop reordering.
     */
    public function prefersFreeFirst(): bool
    {
        return false;
    }

    /**
     * When eligible paid routes exist, reserve one total attempt slot so free
     * budget cannot consume the entire MAX_AI window before a later paid model.
     * This is a budget constraint, not an order rewrite.
     */
    public function requiresPaidFallbackReserve(): bool
    {
        return $this === self::FreeFirstWithPaidFallback;
    }
}
