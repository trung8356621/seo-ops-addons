<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

final class PromptHookRetryPolicy
{
    /**
     * @param  list<string>  $on
     */
    public function __construct(
        public readonly int $max = 0,
        public readonly array $on = [],
    ) {}
}
