<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationConditionEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationConditionResult;

final class DefaultAgentAutomationConditionEvaluator implements AgentAutomationConditionEvaluator
{
    /** @var list<string> */
    public const OPERATORS = [
        'equals',
        'not_equals',
        'greater_than',
        'greater_than_or_equal',
        'less_than',
        'less_than_or_equal',
        'contains',
        'not_contains',
        'in',
        'not_in',
        'is_empty',
        'is_not_empty',
        'changed',
        'increased',
        'decreased',
        'older_than_minutes',
    ];

    public function validateSchema(array $condition, array $allowedPaths): array
    {
        $errors = [];
        if ($this->looksLikeCode($condition)) {
            return ['arbitrary_expression_rejected'];
        }

        $mode = (string) ($condition['mode'] ?? 'all');
        if (! in_array($mode, ['all', 'any'], true)) {
            $errors[] = 'invalid_mode';
        }

        $rules = $condition['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            $errors[] = 'rules_required';

            return $errors;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                $errors[] = 'invalid_rule';
                continue;
            }
            if ($this->looksLikeCode($rule)) {
                $errors[] = 'arbitrary_expression_rejected';
                continue;
            }
            $op = (string) ($rule['operator'] ?? '');
            if (! in_array($op, self::OPERATORS, true)) {
                $errors[] = 'unsupported_operator';
            }
            $path = (string) ($rule['path'] ?? '');
            if ($path === '' || ! $this->isAllowedPath($path, $allowedPaths)) {
                $errors[] = 'invalid_path';
            }
            if (isset($rule['expression']) || isset($rule['php']) || isset($rule['sql']) || isset($rule['regex'])) {
                $errors[] = 'arbitrary_expression_rejected';
            }
        }

        return array_values(array_unique($errors));
    }

    public function evaluate(
        array $condition,
        array $current,
        ?array $baseline,
        array $allowedPaths,
    ): AgentAutomationConditionResult {
        $schemaErrors = $this->validateSchema($condition, $allowedPaths);
        if ($schemaErrors !== []) {
            return new AgentAutomationConditionResult(
                matched: false,
                changed: false,
                evaluations: [],
                fingerprint: null,
                errors: $schemaErrors,
            );
        }

        $mode = (string) ($condition['mode'] ?? 'all');
        /** @var list<array<string, mixed>> $rules */
        $rules = array_values(array_filter($condition['rules'] ?? [], 'is_array'));
        $evaluations = [];
        $bools = [];

        foreach ($rules as $rule) {
            $path = (string) $rule['path'];
            $op = (string) $rule['operator'];
            $expected = $rule['value'] ?? null;
            $currentValue = $this->pathGet($current, $path);
            $baselineValue = $baseline !== null ? $this->pathGet($baseline, $path) : null;

            $eval = $this->evalOne($op, $currentValue, $expected, $baselineValue);
            $evaluations[] = [
                'path' => $path,
                'operator' => $op,
                'matched' => $eval['matched'],
                'error' => $eval['error'],
            ];
            if ($eval['error'] !== null) {
                return new AgentAutomationConditionResult(
                    matched: false,
                    changed: false,
                    evaluations: $evaluations,
                    fingerprint: null,
                    errors: [$eval['error']],
                );
            }
            $bools[] = $eval['matched'];
        }

        $matched = $mode === 'any'
            ? in_array(true, $bools, true)
            : ! in_array(false, $bools, true);

        $fingerprint = hash('sha256', json_encode([
            'current' => $this->fingerprintSlice($current, $allowedPaths),
            'matched' => $matched,
        ], JSON_THROW_ON_ERROR));

        $changed = $baseline === null || $fingerprint !== hash('sha256', json_encode([
            'current' => $this->fingerprintSlice($baseline, $allowedPaths),
            'matched' => true,
        ], JSON_THROW_ON_ERROR));

        if ($baseline !== null) {
            $prevFp = (string) ($baseline['_fingerprint'] ?? '');
            if ($prevFp !== '') {
                $changed = $prevFp !== $fingerprint;
            }
        }

        return new AgentAutomationConditionResult(
            matched: $matched,
            changed: $changed,
            evaluations: $evaluations,
            fingerprint: $fingerprint,
        );
    }

    /**
     * @return array{matched: bool, error: ?string}
     */
    private function evalOne(string $op, mixed $current, mixed $expected, mixed $baseline): array
    {
        return match ($op) {
            'equals' => $this->cmpEqual($current, $expected, true),
            'not_equals' => $this->cmpEqual($current, $expected, false),
            'greater_than' => $this->cmpNumeric($current, $expected, static fn (float $a, float $b): bool => $a > $b),
            'greater_than_or_equal' => $this->cmpNumeric($current, $expected, static fn (float $a, float $b): bool => $a >= $b),
            'less_than' => $this->cmpNumeric($current, $expected, static fn (float $a, float $b): bool => $a < $b),
            'less_than_or_equal' => $this->cmpNumeric($current, $expected, static fn (float $a, float $b): bool => $a <= $b),
            'contains' => $this->cmpContains($current, $expected, true),
            'not_contains' => $this->cmpContains($current, $expected, false),
            'in' => $this->cmpIn($current, $expected, true),
            'not_in' => $this->cmpIn($current, $expected, false),
            'is_empty' => ['matched' => $this->isEmpty($current), 'error' => null],
            'is_not_empty' => ['matched' => ! $this->isEmpty($current), 'error' => null],
            'changed' => ['matched' => $this->valuesDiffer($current, $baseline), 'error' => null],
            'increased' => $this->cmpDelta($current, $baseline, static fn (float $a, float $b): bool => $a > $b),
            'decreased' => $this->cmpDelta($current, $baseline, static fn (float $a, float $b): bool => $a < $b),
            'older_than_minutes' => $this->cmpOlderThan($current, $expected),
            default => ['matched' => false, 'error' => 'unsupported_operator'],
        };
    }

    /**
     * @return array{matched: bool, error: ?string}
     */
    private function cmpEqual(mixed $a, mixed $b, bool $expectEqual): array
    {
        if (is_bool($a) || is_bool($b)) {
            if (! is_bool($a) || ! is_bool($b)) {
                return ['matched' => false, 'error' => 'incompatible_types'];
            }

            return ['matched' => ($a === $b) === $expectEqual, 'error' => null];
        }
        if (is_numeric($a) && is_numeric($b) && ! is_string($a) && ! is_string($b)) {
            return ['matched' => (((float) $a) === ((float) $b)) === $expectEqual, 'error' => null];
        }
        if (is_string($a) && is_string($b)) {
            return ['matched' => ($a === $b) === $expectEqual, 'error' => null];
        }
        if (gettype($a) !== gettype($b) && ! (is_numeric($a) && is_numeric($b))) {
            return ['matched' => false, 'error' => 'incompatible_types'];
        }

        return ['matched' => ($a == $b) === $expectEqual, 'error' => null];
    }

    /**
     * @param  callable(float, float): bool  $fn
     * @return array{matched: bool, error: ?string}
     */
    private function cmpNumeric(mixed $a, mixed $b, callable $fn): array
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return ['matched' => false, 'error' => 'incompatible_types'];
        }

        return ['matched' => $fn((float) $a, (float) $b), 'error' => null];
    }

    /**
     * @param  callable(float, float): bool  $fn
     * @return array{matched: bool, error: ?string}
     */
    private function cmpDelta(mixed $current, mixed $baseline, callable $fn): array
    {
        if ($baseline === null) {
            return ['matched' => false, 'error' => null];
        }
        if (! is_numeric($current) || ! is_numeric($baseline)) {
            return ['matched' => false, 'error' => 'incompatible_types'];
        }

        return ['matched' => $fn((float) $current, (float) $baseline), 'error' => null];
    }

    /**
     * @return array{matched: bool, error: ?string}
     */
    private function cmpContains(mixed $haystack, mixed $needle, bool $expect): array
    {
        if (is_string($haystack) && is_string($needle)) {
            $found = str_contains($haystack, $needle);

            return ['matched' => $found === $expect, 'error' => null];
        }
        if (is_array($haystack)) {
            $found = in_array($needle, $haystack, true);

            return ['matched' => $found === $expect, 'error' => null];
        }

        return ['matched' => false, 'error' => 'incompatible_types'];
    }

    /**
     * @return array{matched: bool, error: ?string}
     */
    private function cmpIn(mixed $value, mixed $list, bool $expect): array
    {
        if (! is_array($list)) {
            return ['matched' => false, 'error' => 'incompatible_types'];
        }
        $found = in_array($value, $list, true);

        return ['matched' => $found === $expect, 'error' => null];
    }

    /**
     * @return array{matched: bool, error: ?string}
     */
    private function cmpOlderThan(mixed $current, mixed $minutes): array
    {
        if (! is_numeric($minutes)) {
            return ['matched' => false, 'error' => 'incompatible_types'];
        }
        $ts = null;
        if (is_numeric($current)) {
            $ts = (int) $current;
        } elseif (is_string($current) && $current !== '') {
            $parsed = strtotime($current);
            $ts = $parsed === false ? null : $parsed;
        }
        if ($ts === null) {
            return ['matched' => false, 'error' => 'incompatible_types'];
        }
        $ageMinutes = (time() - $ts) / 60;

        return ['matched' => $ageMinutes > (float) $minutes, 'error' => null];
    }

    private function isEmpty(mixed $v): bool
    {
        if ($v === null) {
            return true;
        }
        if (is_string($v)) {
            return trim($v) === '';
        }
        if (is_array($v)) {
            return $v === [];
        }

        return false;
    }

    private function valuesDiffer(mixed $a, mixed $b): bool
    {
        return serialize($a) !== serialize($b);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pathGet(array $data, string $path): mixed
    {
        $parts = explode('.', $path);
        $cur = $data;
        foreach ($parts as $part) {
            if (! is_array($cur) || ! array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    /**
     * @param  list<string>  $allowedPaths
     */
    private function isAllowedPath(string $path, array $allowedPaths): bool
    {
        if ($allowedPaths === []) {
            return (bool) preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/', $path);
        }
        foreach ($allowedPaths as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function fingerprintSlice(array $data, array $paths): array
    {
        if ($paths === []) {
            return $data;
        }
        $out = [];
        foreach ($paths as $path) {
            $out[$path] = $this->pathGet($data, $path);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function looksLikeCode(array $payload): bool
    {
        $encoded = strtolower(json_encode($payload, JSON_THROW_ON_ERROR));
        foreach (['<?php', 'javascript:', 'eval(', 'function(', 'select ', ' drop ', 'regex:', '/.*/', '->', '::'] as $needle) {
            if (str_contains($encoded, $needle)) {
                return true;
            }
        }

        return false;
    }
}
