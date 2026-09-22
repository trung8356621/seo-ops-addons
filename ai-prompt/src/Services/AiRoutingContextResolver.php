<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\AiPrompt\Support\ArticleModelOrderAuthority;
use Omnichannel\Addons\AiPrompt\Support\InteractiveAiAttemptBudget;

/**
 * Resolves explicit routing context BEFORE the physical-route loop.
 * Does not execute provider APIs.
 */
final class AiRoutingContextResolver
{
    /**
     * Map UI / cost policy / generation mode → execution routing MODE (cost/budget).
     * Does NOT set candidate ORDER — AI Center sortable order always wins.
     *
     * Mapping:
     * - freeOnly flag OR AiCostPolicy::FreeOnly → FREE_ONLY (excludes paid)
     * - explicit preferred model (required) → EXPLICIT_MODEL
     * - BestQuality / quality → PAID_PREFERRED (budget label only; no reorder)
     * - FastEconomy / default → FREE_FIRST_WITH_PAID_FALLBACK (legacy name = default budgets)
     *
     * Economy/default must NOT silently become FREE_ONLY.
     */
    public function resolveMode(AiRoutingContext $context): AiExecutionRoutingMode
    {
        $policy = $context->effectiveRoutingPolicy();
        if ($policy === AiRoutingPolicy::FreeOnly
            || $context->freeOnly
            || $context->costPolicy()->isFreeOnly()) {
            return AiExecutionRoutingMode::FreeOnly;
        }

        // preferredModelId alone = try-first, then normal fallback (NOT hard-stop).
        // Only requirePreferredModel forbids cross-logical-model fallback.
        if ($context->preferredModelId !== null
            && $context->preferredModelId > 0
            && $context->requirePreferredModel) {
            return AiExecutionRoutingMode::ExplicitModel;
        }

        // Preferred (non-required): preferred model is only prepended by applyItemRoutingPreferences.

        $generationMode = strtolower(trim((string) ($context->itemGenerationMode ?? '')));
        if (in_array($generationMode, ['best_quality', 'quality', 'paid_preferred'], true)) {
            return AiExecutionRoutingMode::PaidPreferred;
        }

        // QuickFree / Normal default economy budget mode (legacy enum name).
        return AiExecutionRoutingMode::FreeFirstWithPaidFallback;
    }

    /**
     * Enrich context with resolved mode, budgets, routing policy, and decision source metadata.
     * Budgets remain as configured caps; AttemptBudgetPolicy applies them at plan time.
     */
    public function enrich(AiRoutingContext $context, ?int $maxAiAttempts = null, ?int $maxFreeAttempts = null): AiRoutingContext
    {
        $policyResolution = (new EffectiveAiRoutingPolicyResolver())->resolve(
            prompt: null,
            hookKey: $context->hookKey ?? $context->canonicalPromptKey,
            explicit: $context->routingPolicy ?? $context->routingPolicyRequested,
            context: $context,
        );
        $effectivePolicy = $policyResolution['effective'];
        $requestedPolicy = $policyResolution['requested'];

        $context = $context->with([
            'routingPolicy' => $effectivePolicy,
            'routingPolicyRequested' => $requestedPolicy,
            'routingPolicyEffective' => $effectivePolicy,
            'freeOnly' => $effectivePolicy === AiRoutingPolicy::FreeOnly ? true : $context->freeOnly,
            'costPolicy' => $effectivePolicy === AiRoutingPolicy::FreeOnly
                ? AiCostPolicy::FreeOnly
                : $context->costPolicy,
        ]);

        if ($context->executionTransport()->isInteractive()) {
            $interactiveBudget = (new InteractiveAiAttemptBudget())->resolve($effectivePolicy);
            $maxAiAttempts = min(
                $maxAiAttempts ?? $interactiveBudget['max_ai_attempts'],
                $interactiveBudget['max_ai_attempts'],
            );
            $maxFreeAttempts = min(
                $maxFreeAttempts ?? $interactiveBudget['max_free_attempts'],
                $interactiveBudget['max_free_attempts'],
            );
        }

        $policyCap = $effectivePolicy->freeAttemptCap();
        if ($policyCap !== null) {
            $maxFreeAttempts = min($maxFreeAttempts ?? $policyCap, $policyCap);
        }

        $mode = $this->resolveMode($context);
        $source = $this->decisionSource($context, $mode, $effectivePolicy);

        return $context->withRoutingDecision(
            routingMode: $mode,
            maxAiAttempts: $maxAiAttempts,
            maxFreeAttempts: $maxFreeAttempts,
            routingDecisionSource: $source,
        );
    }

    private function decisionSource(
        AiRoutingContext $context,
        AiExecutionRoutingMode $mode,
        ?AiRoutingPolicy $policy = null,
    ): string {
        if (($policy ?? $context->effectiveRoutingPolicy()) === AiRoutingPolicy::FreeOnly
            || $context->freeOnly) {
            return 'routing_policy.free_only';
        }
        if ($context->costPolicy()->isFreeOnly()) {
            return 'cost_policy.free_only';
        }
        if (($policy ?? $context->effectiveRoutingPolicy()) === AiRoutingPolicy::QuickFree) {
            return 'routing_policy.quick_free';
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
            return 'manual_sortable_order+default_budget_mode';
        }

        return 'default.'.$mode->value;
    }
}
