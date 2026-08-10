<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

final class DefinitionNotFound extends PromptHookFailure
{
    public function __construct(string $key, ?string $version = null)
    {
        parent::__construct(
            PromptHookFailureCode::DefinitionNotFound,
            $version !== null
                ? "Prompt hook definition not found [{$key}@{$version}]."
                : "Prompt hook definition not found [{$key}].",
        );
    }
}