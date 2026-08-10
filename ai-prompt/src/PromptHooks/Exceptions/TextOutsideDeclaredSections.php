<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

final class TextOutsideDeclaredSections extends PromptHookFailure
{
    public function __construct(
        string $message,
        public readonly string $hookKey = '',
        public readonly string $hookVersion = '',
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct(PromptHookFailureCode::TextOutsideDeclaredSections, $message);
    }
}
