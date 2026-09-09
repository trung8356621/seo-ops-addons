<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\ArticleModelOrderAuthority;

/**
 * Resolves explicit routing context BEFORE the physical-route loop.
 * Does not execute provider APIs.
 */
final class AiRoutingContextResolver
{
    /**
     * Map UI / cost policy / generation mode → execution routing mode.
     *
     * Mapping:
     * - freeOnly flag OR AiCostPolicy::FreeOnly → FREE_ONLY
     * - explicit preferred model (required) → EXPLICIT_MODEL
     * - BestQuality / quality → PAID_PREFERRED
     * - FastEconomy / default / free-first preference → FREE_FIRST_WITH_PAID_FALLBACK
     *
     * Economy/default must NOT silently become FREE_ONLY.
     */
    public function resolveMode(AiRoutingContext $context): AiExecutionRoutingMode
    {
        if ($context->freeOnly || $context->costPolicy()->isFreeOnly()) {
            return AiExecutionRoutingMode::FreeOnly;
        }

        // preferredModelId alone = try-first, then normal fallback (NOT hard-stop).
        // Only requirePreferredModel forbids cross-logical-model fallback.
        if ($context->preferredModelId !== null
            && $context->preferredModelId > 0
            && $context->requirePreferredModel) {
            return AiExecutionRoutingMode::ExplicitModel;
        }

        // Preferred (non-required): keep default/free-first/paid-preferred policy;
        // preferred model is only prepended by applyItemRoutingPreferences.

        $generationMode = strtolower(trim((string) ($context->itemGenerationMode ?? '')));
        if (in_array($generationMode, ['best_quality', 'quality', 'paid_preferred'], true)) {
            return AiExecutionRoutingMode::PaidPreferred;
        }

        // FastEconomy and Default: free-first WITH paid fallback — never FreeOnly.
        return AiExecutionRoutingMode::FreeFirstWithPaidFallback;
    }

    /**
     * Enrich context with resolved mode, budgets, and decision source metadata.
     * Budgets remain as configured caps; AttemptBudgetPolicy applies them at plan time.
     */
    public function enrich(AiRoutingContext $context, ?int $maxAiAttempts = null, ?int $maxFreeAttempts = null): AiRoutingContext
    {
        $mode = $this->resolveMode($context);
        $source = $this->decisionSource($context, $mode);

        return $context->withRoutingDecision(
            routingMode: $mode,
            maxAiAttempts: $maxAiAttempts,
            maxFreeAttempts: $maxFreeAttempts,
            routingDecisionSource: $source,
        );
    }

    private function decisionSource(AiRoutingContext $context, AiExecutionRoutingMode $mode): string
    {
        if ($context->freeOnly) {
            return 'context.free_only_flag';
        }
        if ($context->costPolicy()->isFreeOnly()) {
            return 'cost_policy.free_only';
        }
        if ($context->preferredModelId !== null && $context->preferredModelId > 0) {
            return $context->requirePreferredModel
                ? 'explicit_model.required'
                : 'preferred_model.prepend_only';
        }
        if ($context->itemGenerationMode !== null && trim($context->itemGenerationMode) !== '') {
            return 'item_generation_mode.'.$context->itemGenerationMode;
        }
        if ($context->hookKey !== null && ! ArticleModelOrderAuthority::allowsGenerationModeReorder($context->hookKey)) {
            return 'article_model_order_authority+default_free_first';
        }

        return 'default.'.$mode->value;
    }
}
