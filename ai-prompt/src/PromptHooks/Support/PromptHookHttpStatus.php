<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Support;

final class PromptHookHttpStatus
{
    public static function for(PromptHookErrorCode $code): int
    {
        return match ($code) {
            PromptHookErrorCode::HookNotFound,
            PromptHookErrorCode::HookArticleNotFound => 404,
            PromptHookErrorCode::HookArticleForbidden => 403,
            PromptHookErrorCode::HookExecutionFailed => 502,
            PromptHookErrorCode::HookManifestInvalid => 500,
            default => 422,
        };
    }
}
