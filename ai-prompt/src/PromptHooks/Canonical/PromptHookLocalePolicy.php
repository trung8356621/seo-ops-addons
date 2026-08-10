<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

final class PromptHookLocalePolicy
{
    public function __construct(
        public readonly string $mode = 'site',
        public readonly string $fallback = 'en',
        public readonly ?string $fixed = null,
    ) {}
}
