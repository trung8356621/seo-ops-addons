<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;

/** Map typed hook failures to safe UI strings (no secrets / stack / raw provider body). */
final class PromptHookUiFailureMapper
{
    /**
     * @return array{title: string, body: string, category: string}
     */
    public function map(PromptHookFailure $failure, string $hookKey = '', string $version = '', ?string $correlationId = null): array
    {
        $category = $failure->failureCode->value;
        $parts = [
            $hookKey !== '' ? "{$hookKey}@".($version !== '' ? $version : '?') : null,
            $category,
            $failure->getMessage(),
            $correlationId !== null && $correlationId !== '' ? "correlation_id={$correlationId}" : null,
        ];

        return [
            'title' => (string) __('seo-content-ai::prompt_hooks.execution_failed_title'),
            'body' => implode(' — ', array_values(array_filter($parts))),
            'category' => $category,
        ];
    }
}
