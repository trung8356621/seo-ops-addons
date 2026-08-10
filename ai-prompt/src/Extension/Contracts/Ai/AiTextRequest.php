<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai;

final class AiTextRequest
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $model,
        public readonly array $options = [],
    ) {}
}
