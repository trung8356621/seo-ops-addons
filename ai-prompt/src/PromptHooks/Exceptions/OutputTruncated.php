<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;
use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason;

final class OutputTruncated extends PromptHookFailure
{
    /**
     * @param  array<string, mixed>|null  $usage  Provider usage + budget diagnostics for attempt history
     */
    public function __construct(
        string $message,
        public readonly ?AiProviderTerminalReason $terminalReason = AiProviderTerminalReason::OutputTruncated,
        public readonly ?string $providerFinishReason = null,
        public readonly ?array $usage = null,
    ) {
        parent::__construct(PromptHookFailureCode::OutputTruncated, $message);
    }
}
