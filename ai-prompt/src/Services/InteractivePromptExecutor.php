<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\PromptExecutionRequest;
use Omnichannel\Addons\AiPrompt\DataTransfer\PromptExecutionResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;

/**
 * Reusable synchronous (interactive) Prompt / compiled-text execution entry.
 *
 * Uses the shared AI routing planner, providers, validation, and attempt history.
 * Does NOT dispatch background AI jobs. Module-agnostic — no Seeding knowledge.
 */
final class InteractivePromptExecutor
{
    public function __construct(
        private readonly CanonicalAiTextExecutionService $canonical,
        private readonly PromptExecutionProfileResolver $profiles = new PromptExecutionProfileResolver(),
        private readonly EffectiveAiRoutingPolicyResolver $routingPolicies = new EffectiveAiRoutingPolicyResolver(),
    ) {}

    public function execute(PromptExecutionRequest $request): PromptExecutionResult
    {
        $hookKey = trim($request->hookKey);
        $compiled = trim((string) ($request->compiledPrompt ?? ''));
        if ($compiled === '') {
            throw new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException(
                'Interactive execution requires a compiled prompt body.',
            );
        }
        if ($hookKey === '') {
            throw new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException(
                'Interactive execution requires a hook_key.',
            );
        }

        $prompt = $request->prompt;
        $profile = $request->executionProfileOverride
            ?? $this->profiles->resolve($prompt, $hookKey);

        $policyResolution = $this->routingPolicies->resolve(
            prompt: $prompt,
            hookKey: $hookKey,
            explicit: $request->routingPolicy,
            context: $request->routingContext,
            variables: $request->variables,
        );

        $context = $this->buildContext(
            request: $request,
            hookKey: $hookKey,
            profile: $profile,
            requested: $policyResolution['requested'],
            effective: $policyResolution['effective'],
        );

        [$text, $usage, $candidate] = $this->canonical->generate(
            $compiled,
            $hookKey,
            $profile,
            $context,
            array_merge($request->options, [
                'execution_transport' => AiExecutionTransport::Interactive->value,
                'routing_policy' => $policyResolution['effective']->value,
                'routing_policy_requested' => $policyResolution['requested']->value,
                'routing_policy_effective' => $policyResolution['effective']->value,
            ]),
        );

        return new PromptExecutionResult(
            text: $text,
            usage: is_array($usage) ? $usage : null,
            candidate: $candidate,
            executionProfile: $profile,
            routingPolicyRequested: $policyResolution['requested'],
            routingPolicyEffective: $policyResolution['effective'],
            executionTransport: AiExecutionTransport::Interactive,
            meta: [
                'hook_key' => $hookKey,
                'global_free_only' => $policyResolution['global_free_only'],
                'prompt_id' => $prompt?->id,
            ],
        );
    }

    /**
     * Convenience for compiled-text callers (System AI text port, utilities).
     *
     * @param  array<string, mixed>  $options
     */
    public function executeCompiled(
        string $compiledPrompt,
        string $hookKey,
        ?AiExecutionProfile $profile = null,
        ?AiRoutingPolicy $routingPolicy = null,
        ?SeoPrompt $prompt = null,
        array $options = [],
        ?AiRoutingContext $context = null,
    ): PromptExecutionResult {
        return $this->execute(new PromptExecutionRequest(
            hookKey: $hookKey,
            compiledPrompt: $compiledPrompt,
            prompt: $prompt,
            variables: is_array($options['variables'] ?? null) ? $options['variables'] : [],
            executionProfileOverride: $profile,
            routingPolicy: $routingPolicy,
            transport: AiExecutionTransport::Interactive,
            options: $options,
            routingContext: $context,
        ));
    }

    private function buildContext(
        PromptExecutionRequest $request,
        string $hookKey,
        AiExecutionProfile $profile,
        AiRoutingPolicy $requested,
        AiRoutingPolicy $effective,
    ): AiRoutingContext {
        $base = $request->routingContext ?? new AiRoutingContext(
            userId: $this->resolveUserId(),
            hookKey: $hookKey,
            canonicalPromptKey: $hookKey,
            promptTaskType: 'interactive_text',
            modelArea: $profile->value,
        );

        return $base->with([
            'hookKey' => $base->hookKey ?: $hookKey,
            'canonicalPromptKey' => $base->canonicalPromptKey ?? $hookKey,
            'modelArea' => $base->modelArea ?? $profile->value,
            'promptTaskType' => $base->promptTaskType ?? 'interactive_text',
            'routingPolicy' => $effective,
            'routingPolicyRequested' => $requested,
            'routingPolicyEffective' => $effective,
            'executionTransport' => AiExecutionTransport::Interactive,
            'freeOnly' => $effective === AiRoutingPolicy::FreeOnly ? true : $base->freeOnly,
        ]);
    }

    private function resolveUserId(): ?int
    {
        $id = (int) (auth()->id() ?? 0);

        return $id > 0 ? $id : null;
    }
}
