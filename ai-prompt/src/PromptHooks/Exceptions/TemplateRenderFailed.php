<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

final class TemplateRenderFailed extends PromptHookFailure
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(PromptHookFailureCode::TemplateRenderFailed, $message, $previous);
    }
}