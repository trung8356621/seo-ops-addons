<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

/**
 * Canonical binding entry for Settings-owned hooks.
 * Workflow binds via Task flow_data.prompt_id (PromptUsageLocator).
 * Agent / Quick Chat reserved — not wired yet.
 */
final class PromptBindingResolver implements \Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook
{
    public function __construct(
        private readonly SettingsPromptBindingResolver $settingsResolver,
    ) {}

    public function resolveSettingsHook(string $hookKey): SeoPrompt
    {
        return $this->settingsResolver->resolve($hookKey);
    }

    public function resolveSettingsHookId(string $hookKey): ?int
    {
        return $this->settingsResolver->resolveId($hookKey);
    }

    public function isSettingsHookConfigured(string $hookKey): bool
    {
        return $this->settingsResolver->isConfigured($hookKey);
    }
}
