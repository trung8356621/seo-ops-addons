<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

/**
 * Kết quả chuẩn Agent Gateway — không leak numeric IDs nội bộ.
 *
 * @phpstan-type NextAction array{capability: string, reason: string}
 */
final class AgentCapabilityResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @param  list<array{capability: string, reason: string}>  $nextActions
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $code,
        public readonly string $message,
        public readonly array $data = [],
        public readonly array $warnings = [],
        public readonly array $nextActions = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @param  list<array{capability: string, reason: string}>  $nextActions
     * @param  array<string, mixed>  $meta
     */
    public static function ok(
        string $code,
        string $message,
        array $data = [],
        array $warnings = [],
        array $nextActions = [],
        array $meta = [],
    ): self {
        return new self(
            success: true,
            code: $code,
            message: $message,
            data: $data,
            warnings: $warnings,
            nextActions: $nextActions,
            meta: $meta,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @param  list<array{capability: string, reason: string}>  $nextActions
     * @param  array<string, mixed>  $meta
     */
    public static function fail(
        string $code,
        string $message,
        array $data = [],
        array $warnings = [],
        array $nextActions = [],
        array $meta = [],
    ): self {
        return new self(
            success: false,
            code: $code,
            message: $message,
            data: $data,
            warnings: $warnings,
            nextActions: $nextActions,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->message,
            'data' => $this->data,
            'warnings' => $this->warnings,
            'next_actions' => $this->nextActions,
            'meta' => $this->meta,
        ];
    }
}
