<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Runtime ownership: Settings binding map hook_key → prompt_id.
 * Hook does not activate a Prompt — reference does.
 */
final class SettingsPromptBindingResolver
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly PromptHookEditorCatalog $catalog,
    ) {}

    public function resolve(string $hookKey): SeoPrompt
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            throw new PromptHookException(
                PromptHookErrorCode::HookPromptNotConfigured,
                'Capability chưa được cấu hình Prompt: (empty hook_key)',
            );
        }

        if (! $this->catalog->isSettingsVisible($hookKey) && ! $this->settings->hasLegacyBindingSource($hookKey)) {
            // Allow resolve for known settings-visible OR migrated legacy keys during transition.
            // Unknown hooks still resolve if binding exists (Task-only hooks should not call this).
        }

        $promptId = $this->settings->getBoundPromptId($hookKey);
        if ($promptId === null) {
            throw new PromptHookException(
                PromptHookErrorCode::HookPromptNotConfigured,
                "Capability chưa được cấu hình Prompt: {$hookKey}",
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new PromptHookException(
                PromptHookErrorCode::HookPromptNotConfigured,
                "Capability chưa được cấu hình Prompt: {$hookKey} (prompt #{$promptId} missing)",
            );
        }

        $promptHook = trim((string) ($prompt->hook_key ?? ''));
        if ($promptHook !== '' && $promptHook !== $hookKey) {
            throw new PromptHookException(
                PromptHookErrorCode::HookPromptMismatch,
                "Settings binding [{$hookKey}] points to prompt #{$promptId} with hook_key [{$promptHook}].",
            );
        }

        return $prompt;
    }

    public function resolveId(string $hookKey): ?int
    {
        return $this->settings->getBoundPromptId($hookKey);
    }

    public function isConfigured(string $hookKey): bool
    {
        return $this->settings->getBoundPromptId($hookKey) !== null;
    }
}
