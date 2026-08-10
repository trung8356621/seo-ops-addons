<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai;

final class AiProviderHealthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
    ) {}

    public static function healthy(string $message = 'ok'): self
    {
        return new self(true, $message);
    }

    public static function unhealthy(string $message): self
    {
        return new self(false, $message);
    }
}
