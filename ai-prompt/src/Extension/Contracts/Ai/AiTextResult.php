<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai;

final class AiTextResult
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly string $modelUsed,
        public readonly ?array $usage = null,
        public readonly string $message = '',
    ) {}

    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function success(string $text, string $modelUsed, ?array $usage = null): self
    {
        return new self(true, $text, $modelUsed, $usage, '');
    }

    public static function failure(string $message, string $modelUsed = ''): self
    {
        return new self(false, '', $modelUsed, null, $message);
    }
}
