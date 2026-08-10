<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

final class MismatchedSectionMarker extends PromptHookFailure
{
    public function __construct(
        string $message,
        public readonly string $hookKey = '',
        public readonly string $hookVersion = '',
        public readonly string $sectionKey = '',
        public readonly string $startMarker = '',
        public readonly string $endMarker = '',
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct(PromptHookFailureCode::MismatchedSectionMarker, $message);
    }
}
