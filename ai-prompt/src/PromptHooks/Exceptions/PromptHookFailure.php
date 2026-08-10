<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions;

use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;
use RuntimeException;
use Throwable;

/** Typed Prompt Hook failure — không dùng generic RuntimeException cho mọi case. */
class PromptHookFailure extends RuntimeException
{
    private ?int $boundPromptResultId = null;

    public function __construct(
        public readonly PromptHookFailureCode $failureCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function bindPromptResultId(int $promptResultId): self
    {
        if ($promptResultId > 0 && $this->boundPromptResultId === null) {
            $this->boundPromptResultId = $promptResultId;
        }

        return $this;
    }

    public function promptResultId(): ?int
    {
        return $this->boundPromptResultId;
    }
}
