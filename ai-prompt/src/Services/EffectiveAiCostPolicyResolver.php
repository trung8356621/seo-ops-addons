<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\AiPrompt\Support\PromptTaskFreeOnlyPolicy;

/**
 * Most-restrictive effective FreeOnly policy for a routing decision.
 *
 * Sources (any FreeOnly wins):
 * - explicit AiRoutingContext.freeOnly flag
 * - context / scope cost policy
 * - snapshotted ai_cost_policy / ai_generation_mode on variables
 * - explicit micro-task FreeOnly ({@see PromptTaskFreeOnlyPolicy})
 *
 * Does NOT read live user preference — that must be snapshotted at run start.
 */
final class EffectiveAiCostPolicyResolver
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function resolve(
        ?AiCostPolicy $contextPolicy = null,
        bool $explicitFreeOnlyFlag = false,
        ?string $hookKey = null,
        array $variables = [],
    ): AiCostPolicy {
        if ($explicitFreeOnlyFlag) {
            return AiCostPolicy::FreeOnly;
        }

        if (($contextPolicy ?? AiCostPolicy::Default)->isFreeOnly()) {
            return AiCostPolicy::FreeOnly;
        }

        if (AiCostPolicyScope::current()->isFreeOnly()) {
            return AiCostPolicy::FreeOnly;
        }

        $fromVariables = $this->fromVariables($variables);
        if ($fromVariables->isFreeOnly()) {
            return AiCostPolicy::FreeOnly;
        }

        if (PromptTaskFreeOnlyPolicy::requires($hookKey)) {
            return AiCostPolicy::FreeOnly;
        }

        return AiCostPolicy::Default;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function resolveForContext(AiRoutingContext $context, array $variables = []): AiCostPolicy
    {
        return $this->resolve(
            contextPolicy: $context->costPolicy,
            explicitFreeOnlyFlag: $context->freeOnly,
            hookKey: $context->hookKey ?? $context->canonicalPromptKey,
            variables: $variables,
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fromVariables(array $variables): AiCostPolicy
    {
        if (array_key_exists(AiCostPolicy::SETTING_KEY, $variables)) {
            return AiCostPolicy::tryFromMixed($variables[AiCostPolicy::SETTING_KEY]);
        }

        if (array_key_exists('ai_generation_mode', $variables)) {
            return AiCostPolicy::tryFromMixed($variables['ai_generation_mode']);
        }

        return AiCostPolicy::Default;
    }
}
