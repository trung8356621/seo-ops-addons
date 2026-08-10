<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

final class BudgetExceeded extends PromptHookFailure
{
    public function __construct(string $message)
    {
        parent::__construct(PromptHookFailureCode::BudgetExceeded, $message);
    }
}