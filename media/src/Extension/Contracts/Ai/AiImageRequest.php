<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Extension\Contracts\Ai;

final class AiImageRequest
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $model = '',
        public readonly array $options = [],
    ) {}
}
