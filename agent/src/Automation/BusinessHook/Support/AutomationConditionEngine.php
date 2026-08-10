<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationConditionRegistry;

final class AutomationConditionEngine
{
    private const OPERATORS = [
        'equals', 'not_equals', 'in', 'not_in', 'exists', 'not_exists',
        'contains', 'greater_than', 'less_than', 'is_true', 'is_false',
    ];

    public function __construct(
        private readonly AutomationInputMapper $mapper,
        private readonly ?AutomationConditionRegistry $conditionRegistry = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $conditions
     * @param  array{
     *   event: array<string, mixed>,
     *   payload: array<string, mixed>,
     *   context: array<string, mixed>,
     *   subject: array<string, mixed>,
     *   previous: array<string, mixed>
     * }  $sources
     */
    public function matches(?array $conditions, array $sources): bool
    {
        if ($conditions === null || $conditions === []) {
            return true;
        }

        if (isset($conditions['all']) && is_array($conditions['all'])) {
            foreach ($conditions['all'] as $clause) {
                if (! is_array($clause) || ! $this->evaluateClause($clause, $sources)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($conditions['any']) && is_array($conditions['any'])) {
            foreach ($conditions['any'] as $clause) {
                if (is_array($clause) && $this->evaluateClause($clause, $sources)) {
                    return true;
                }
            }

            return false;
        }

        return $this->evaluateClause($conditions, $sources);
    }

    /**
     * @param  array<string, mixed>|null  $conditions
     * @return list<string>
     */
    public function validate(?array $conditions): array
    {
        if ($conditions === null || $conditions === []) {
            return [];
        }

        $errors = [];
        if (isset($conditions['all']) || isset($conditions['any'])) {
            $groupKey = isset($conditions['all']) ? 'all' : 'any';
            $items = $conditions[$groupKey] ?? null;
            if (! is_array($items)) {
                return ['Conditions group must be an array.'];
            }
            foreach ($items as $i => $clause) {
                if (! is_array($clause)) {
                    $errors[] = "Condition [{$groupKey}.{$i}] must be object.";
                    continue;
                }
                $errors = array_merge($errors, $this->validateClause($clause, "{$groupKey}.{$i}"));
            }

            return $errors;
        }

        return $this->validateClause($conditions, 'root');
    }

    /**
     * @param  array<string, mixed>  $clause
     * @param  array<string, mixed>  $sources
     */
    private function evaluateClause(array $clause, array $sources): bool
    {
        if (isset($clause['all']) || isset($clause['any'])) {
            return $this->matches($clause, $sources);
        }

        $field = (string) ($clause['field'] ?? '');
        $operator = (string) ($clause['operator'] ?? '');
        if ($field === '' || $operator === '') {
            throw new AutomationException(
                BusinessHookErrorCode::InvalidCondition->value,
                'Condition requires field and operator.',
            );
        }

        $actual = $this->mapper->resolvePath($field, $sources);
        $expected = $clause['value'] ?? null;

        if (! in_array($operator, self::OPERATORS, true)) {
            if ($this->conditionRegistry?->hasOperator($operator) === true) {
                $definition = $this->conditionRegistry->getOperator($operator);

                return (bool) ($definition->evaluator)($actual, $expected, $clause, $sources);
            }

            throw new AutomationException(
                BusinessHookErrorCode::InvalidCondition->value,
                "Unknown condition operator [{$operator}].",
            );
        }

        return match ($operator) {
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, false),
            'exists' => $actual !== null && $actual !== '',
            'not_exists' => $actual === null || $actual === '',
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'is_true' => $actual === true || $actual === 1 || $actual === '1',
            'is_false' => $actual === false || $actual === 0 || $actual === '0',
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $clause
     * @return list<string>
     */
    private function validateClause(array $clause, string $path): array
    {
        if (isset($clause['all']) || isset($clause['any'])) {
            return $this->validate($clause);
        }

        $errors = [];
        $field = (string) ($clause['field'] ?? '');
        $operator = (string) ($clause['operator'] ?? '');
        if ($field === '') {
            $errors[] = "Condition [{$path}] missing field.";
        } else {
            $root = explode('.', $field)[0] ?? '';
            $allowedRoots = ['event', 'payload', 'context', 'subject', 'previous', ...($this->conditionRegistry?->extraFieldRoots() ?? [])];
            if (! in_array($root, $allowedRoots, true)) {
                $errors[] = "Condition [{$path}] field root must be one of: ".implode('|', $allowedRoots).'.';
            }
        }
        if ($operator === '' || (! in_array($operator, self::OPERATORS, true) && $this->conditionRegistry?->hasOperator($operator) !== true)) {
            $errors[] = "Condition [{$path}] has invalid operator.";
        }

        return $errors;
    }
}
