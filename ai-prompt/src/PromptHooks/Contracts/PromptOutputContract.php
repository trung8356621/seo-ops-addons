<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Contracts;

/**
 * Immutable resolved output contract (reusable prompt fragment).
 */
final class PromptOutputContract
{
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $body,
        public readonly string $path,
    ) {}

    public function cacheKey(): string
    {
        return $this->key.'@'.$this->version;
    }
}
