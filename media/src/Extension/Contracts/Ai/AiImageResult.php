<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Extension\Contracts\Ai;

final class AiImageResult
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $url = '',
        public readonly string $modelUsed = '',
        public readonly ?array $usage = null,
        public readonly string $message = '',
    ) {}

    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function success(string $url, string $modelUsed = '', ?array $usage = null): self
    {
        return new self(ok: true, url: $url, modelUsed: $modelUsed, usage: $usage);
    }

    public static function failure(string $message, string $modelUsed = ''): self
    {
        return new self(ok: false, url: '', modelUsed: $modelUsed, usage: null, message: $message);
    }
}
