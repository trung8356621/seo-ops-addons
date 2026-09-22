<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\InteractivePromptExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;

/**
 * Legacy adapter: System AI text port → InteractivePromptExecutor (shared routing stack).
 * Synchronous / interactive transport. No DB migration. Preserves FreeOnly / priority / history.
 */
final class LegacyCanonicalAiTextExecutionPort implements AiTextExecutionPort
{
    public function __construct(
        private readonly InteractivePromptExecutor $interactive,
        private readonly PromptExecutionProfileResolver $profiles = new PromptExecutionProfileResolver(),
        private readonly PromptRoutingPolicyResolver $routingPolicies = new PromptRoutingPolicyResolver(),
    ) {}

    public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
    {
        $prompt = $this->resolvePrompt($options);
        $profile = $this->resolveProfile($hookKey, $prompt, $options);
        $policy = $this->resolvePolicy($hookKey, $prompt, $options);

        $result = $this->interactive->executeCompiled(
            compiledPrompt: $compiledPrompt,
            hookKey: $hookKey,
            profile: $profile,
            routingPolicy: $policy,
            prompt: $prompt,
            options: $options,
        );

        $candidate = $result->candidate;
        $provider = null;
        $model = null;
        $physicalRoute = null;
        if (is_object($candidate) && method_exists($candidate, 'physicalRouteKey')) {
            $physicalRoute = (string) $candidate->physicalRouteKey();
        }
        if (is_object($candidate)) {
            if (isset($candidate->provider)) {
                $provider = (string) $candidate->provider;
            }
            if (isset($candidate->model)) {
                $model = (string) $candidate->model;
            }
        }

        return [
            'text' => $result->text,
            'usage' => $result->usage,
            'provider' => $provider,
            'model' => $model,
            'physical_route' => $physicalRoute,
            'trace' => [
                'adapter' => 'legacy_canonical_interactive',
                'hook_key' => $hookKey,
                'execution_transport' => $result->executionTransport->value,
                'routing_policy_requested' => $result->routingPolicyRequested->value,
                'routing_policy_effective' => $result->routingPolicyEffective->value,
                'execution_profile' => $result->executionProfile->value,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveProfile(string $hookKey, ?SeoPrompt $prompt, array $options): AiExecutionProfile
    {
        $profileRaw = $options['profile'] ?? $options['execution_profile'] ?? null;
        if (is_string($profileRaw) && trim($profileRaw) !== '') {
            $parsed = AiExecutionProfile::tryFrom(trim($profileRaw));
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $this->profiles->resolve($prompt, $hookKey);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolvePolicy(string $hookKey, ?SeoPrompt $prompt, array $options): AiRoutingPolicy
    {
        $raw = $options['routing_policy'] ?? null;
        $explicit = AiRoutingPolicy::tryFromMixed($raw);
        if ($explicit !== null) {
            return $explicit;
        }

        return $this->routingPolicies->resolve($prompt, $hookKey);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolvePrompt(array $options): ?SeoPrompt
    {
        if (isset($options['prompt']) && $options['prompt'] instanceof SeoPrompt) {
            return $options['prompt'];
        }

        $promptId = (int) ($options['prompt_id'] ?? 0);
        if ($promptId <= 0) {
            return null;
        }

        try {
            return SeoPrompt::query()->find($promptId);
        } catch (\Throwable) {
            return null;
        }
    }
}
