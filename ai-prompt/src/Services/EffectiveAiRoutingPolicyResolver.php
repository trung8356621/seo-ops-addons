<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;

/**
 * Resolves effective routing policy with global FreeOnly hard precedence.
 *
 * A Prompt policy must never weaken a global FreeOnly restriction.
 */
final class EffectiveAiRoutingPolicyResolver
{
    public function __construct(
        private readonly PromptRoutingPolicyResolver $promptResolver = new PromptRoutingPolicyResolver(),
        private readonly EffectiveAiCostPolicyResolver $costPolicyResolver = new EffectiveAiCostPolicyResolver(),
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{
     *     requested: AiRoutingPolicy,
     *     effective: AiRoutingPolicy,
     *     global_free_only: bool
     * }
     */
    public function resolve(
        ?SeoPrompt $prompt = null,
        ?string $hookKey = null,
        ?AiRoutingPolicy $explicit = null,
        ?AiRoutingContext $context = null,
        array $variables = [],
    ): array {
        $requested = $explicit
            ?? $this->promptResolver->resolve($prompt, $hookKey ?? $context?->hookKey);

        $globalFreeOnly = $this->isGlobalFreeOnly($context, $hookKey ?? $context?->hookKey, $variables);
        $effective = $globalFreeOnly ? AiRoutingPolicy::FreeOnly : $requested;

        return [
            'requested' => $requested,
            'effective' => $effective,
            'global_free_only' => $globalFreeOnly,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function isGlobalFreeOnly(?AiRoutingContext $context, ?string $hookKey, array $variables): bool
    {
        if ($context !== null) {
            return $this->costPolicyResolver->resolveForContext($context, $variables)->isFreeOnly()
                || $context->freeOnly
                || (($context->routingMode ?? null)?->allowsPaidRoutes() === false);
        }

        return $this->costPolicyResolver->resolve(
            contextPolicy: null,
            explicitFreeOnlyFlag: false,
            hookKey: $hookKey,
            variables: $variables,
        )->isFreeOnly();
    }
}
