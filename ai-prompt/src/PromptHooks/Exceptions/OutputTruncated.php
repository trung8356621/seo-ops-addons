<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;
use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason;

final class OutputTruncated extends PromptHookFailure
{
    public function __construct(
        string $message,
        public readonly ?AiProviderTerminalReason $terminalReason = AiProviderTerminalReason::OutputTruncated,
        public readonly ?string $providerFinishReason = null,
    ) {
        parent::__construct(PromptHookFailureCode::OutputTruncated, $message);
    }
}
