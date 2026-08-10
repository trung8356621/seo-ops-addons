<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Data;

/**
 * @phpstan-type ConditionSources array{
 *   event: array<string, mixed>,
 *   payload: array<string, mixed>,
 *   context: array<string, mixed>,
 *   subject: array<string, mixed>,
 *   previous: array<string, mixed>
 * }
 */
final class ConditionOperatorDefinition
{
    /**
     * @param  callable(mixed $actual, mixed $expected, array<string, mixed> $clause, ConditionSources $sources): bool  $evaluator
     */
    public function __construct(
        public readonly string $name,
        public readonly mixed $evaluator,
        public readonly string $description = '',
        public readonly string $module = 'unknown',
    ) {}
}
