<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

/**
 * Kết quả so sánh parity (dùng test + gate promote).
 */
final class ParityCompareResult
{
    /**
     * @param  array<string, array{expected: mixed, actual: mixed}>  $normalizedDiff
     */
    public function __construct(
        public readonly bool $matched,
        public readonly string $callerKey,
        public readonly string $actionKey,
        public readonly string $correlationId,
        public readonly int $durationMs,
        public readonly array $normalizedDiff,
    ) {}
}
