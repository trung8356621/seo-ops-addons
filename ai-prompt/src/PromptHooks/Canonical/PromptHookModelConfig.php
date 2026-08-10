<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

final class PromptHookModelConfig
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $name,
        public readonly array $settings,
        public readonly string $capability = 'text',
        public readonly bool $structuredOutput = false,
    ) {}
}
