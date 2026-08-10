<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

final class PromptHookLoggingPolicy
{
    public function __construct(
        public readonly bool $storeFullPrompt = false,
        public readonly bool $redactSensitive = true,
    ) {}
}
