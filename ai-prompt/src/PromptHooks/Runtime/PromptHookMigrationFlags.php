<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

final class PromptHookMigrationFlags
{
    public function mode(string $hookKey): PromptHookRuntimeMode
    {
        $map = config('seo-content-ai.prompt_hooks.migration', []);
        $value = is_array($map)
            ? ($map[$hookKey] ?? PromptHookRuntimeMode::Legacy->value)
            : PromptHookRuntimeMode::Legacy->value;

        return PromptHookRuntimeMode::fromConfig($value);
    }

    public function liveShadowEnabled(): bool
    {
        return (bool) config('seo-content-ai.prompt_hooks.live_shadow_enabled', false);
    }

    /**
     * Second gate for billable dual-run (legacy + hook provider) in Shadow mode.
     * Default OFF — ordinary shadow uses shadowWithoutProvider (no second provider call).
     */
    public function liveShadowProviderEnabled(): bool
    {
        return (bool) config('seo-content-ai.prompt_hooks.live_shadow_provider_enabled', false);
    }

    public function experimentalAllowed(): bool
    {
        return (bool) config('seo-content-ai.prompt_hooks.experimental_allowed', true);
    }

    /**
     * @return list<string>
     */
    public function experimentalAllowlist(): array
    {
        $list = config('seo-content-ai.prompt_hooks.experimental_allowlist', []);

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }
}
