<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Illuminate\Validation\ValidationException;

final class PromptDeleteGuard
{
    public function __construct(
        private readonly PromptUsageLocator $usageLocator,
    ) {}

    public function assertDeletable(int $promptId): void
    {
        $usages = $this->usageLocator->summarize($promptId);
        if ($usages === []) {
            return;
        }

        throw ValidationException::withMessages([
            'prompt' => "Prompt đang được sử dụng bởi:\n- ".implode("\n- ", $usages)
                ."\nTháo binding trước khi xóa, hoặc dùng Force detach.",
        ]);
    }

    public function detachUsages(int $promptId): int
    {
        return $this->usageLocator->detachAll($promptId);
    }

    public function assertHookChangeAllowed(int $promptId, ?string $oldHookKey, ?string $newHookKey): void
    {
        $old = trim((string) $oldHookKey);
        $new = trim((string) $newHookKey);
        if ($old === $new) {
            return;
        }

        $bindings = app(\Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService::class)
            ->getPromptHookBindings();

        if ($old !== '' && isset($bindings[$old]) && (int) $bindings[$old] === $promptId) {
            throw ValidationException::withMessages([
                'hook_key' => "Prompt đang được Settings binding cho [{$old}]. Tháo binding trước khi đổi Hook.",
            ]);
        }
    }
}
