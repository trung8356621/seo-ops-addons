<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

/**
 * Live AI shadow — all gates must pass. Default off.
 * Multi-worker: in-memory budget store is NOT sufficient for production live shadow.
 */
final class PromptHookLiveShadowGate
{
    public function __construct(
        private readonly PromptHookMigrationFlags $flags,
    ) {}

    public function allows(string $hookKey): bool
    {
        if (! $this->flags->liveShadowEnabled()) {
            return false;
        }

        $envs = config('seo-content-ai.prompt_hooks.live_shadow_environments', ['local', 'staging']);
        if (! is_array($envs) || ! in_array(app()->environment(), $envs, true)) {
            return false;
        }

        $hooks = config('seo-content-ai.prompt_hooks.live_shadow_hook_allowlist', []);
        if (! is_array($hooks) || ! in_array($hookKey, $hooks, true)) {
            return false;
        }

        $storeDriver = (string) config('seo-content-ai.prompt_hooks.budget_store', 'memory');
        if ($storeDriver === 'memory' && ! (bool) config('seo-content-ai.prompt_hooks.live_shadow_allow_memory_budget', false)) {
            return false;
        }

        $rate = (float) config('seo-content-ai.prompt_hooks.live_shadow_sample_rate', 0.0);
        if ($rate <= 0) {
            return false;
        }
        if ($rate < 1.0 && (mt_rand() / mt_getrandmax()) > $rate) {
            return false;
        }

        return true;
    }
}
