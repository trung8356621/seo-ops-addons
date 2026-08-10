<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Data;

final class AutomationActionResult
{
    /**
     * @param  array<string, mixed>  $output
     * @param  list<array{event_name: string, payload?: array<string, mixed>, context?: array<string, mixed>}>  $dispatchEvents
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $output = [],
        public readonly string $message = '',
        public readonly ?string $errorCode = null,
        public readonly array $dispatchEvents = [],
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  list<array{event_name: string, payload?: array<string, mixed>, context?: array<string, mixed>}>  $dispatchEvents
     */
    public static function success(array $output = [], string $message = '', array $dispatchEvents = []): self
    {
        return new self(true, $output, $message, null, $dispatchEvents);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public static function failure(string $errorCode, string $message, array $output = []): self
    {
        return new self(false, $output, $message, $errorCode);
    }
}
