<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

final class HookDisabled extends PromptHookFailure
{
    public function __construct(string $key, string $version)
    {
        parent::__construct(
            PromptHookFailureCode::HookDisabled,
            "Prompt hook disabled [{$key}@{$version}].",
        );
    }
}