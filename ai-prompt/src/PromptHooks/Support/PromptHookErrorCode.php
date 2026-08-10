<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Support;

enum PromptHookErrorCode: string
{
    case HookNotFound = 'HOOK_NOT_FOUND';
    case HookPromptNotConfigured = 'HOOK_PROMPT_NOT_CONFIGURED';
    case HookPromptMismatch = 'HOOK_PROMPT_MISMATCH';
    case HookInputInvalid = 'HOOK_INPUT_INVALID';
    case HookModelUnsupported = 'HOOK_MODEL_UNSUPPORTED';
    case HookExecutionFailed = 'HOOK_EXECUTION_FAILED';
    case HookOutputInvalid = 'HOOK_OUTPUT_INVALID';
    case HookArticleForbidden = 'HOOK_ARTICLE_FORBIDDEN';
    case HookArticleNotFound = 'HOOK_ARTICLE_NOT_FOUND';
    case HookManifestInvalid = 'HOOK_MANIFEST_INVALID';
    case HookDuplicateKey = 'HOOK_DUPLICATE_KEY';
}
