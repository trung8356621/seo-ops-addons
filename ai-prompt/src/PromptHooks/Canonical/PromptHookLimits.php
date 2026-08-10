<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

final class PromptHookLimits
{
    /**
     * @param  list<string>|null  $allowedPreviousOutputKeys
     */
    public function __construct(
        public readonly int $maxPreviousOutputsTotalBytes = 200_000,
        public readonly int $maxPreviousOutputsItemBytes = 100_000,
        public readonly int $maxPreviousOutputsItems = 32,
        public readonly ?array $allowedPreviousOutputKeys = null,
        public readonly int $maxStringLength = 500_000,
    ) {}
}
