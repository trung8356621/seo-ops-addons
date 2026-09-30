<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools;

final class SeoToolInputValidator
{
    /**
     * Validate an input array against a JSON Schema structure.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $input
     * @return list<string> List of validation error messages, empty if valid.
     */
    public function validate(array $schema, array $input): array
    {
        $errors = [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($required as $field) {
            if (!array_key_exists($field, $input) || $this->isEmptyValue($input[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        foreach ($input as $field => $value) {
            if (!array_key_exists($field, $properties)) {
                $errors[] = "Unknown field: {$field}";
                continue;
            }

            $def = $properties[$field];
            if (!is_array($def)) {
                continue;
            }

            $type = (string) ($def['type'] ?? 'string');
            if ($value !== null && !$this->matchesType($value, $type)) {
                $errors[] = "Invalid type for {$field}; expected {$type}.";
                continue;
            }

            if ($value !== null && $type === 'string' && isset($def['enum']) && is_array($def['enum'])) {
                if (!in_array($value, $def['enum'], true)) {
                    $errors[] = "Invalid enum value for {$field}.";
                }
            }

            if ($value !== null && $type === 'array' && isset($def['minItems']) && is_int($def['minItems'])) {
                if (count($value) < $def['minItems']) {
                    $errors[] = "Field {$field} requires at least {$def['minItems']} item(s).";
                }
            }

            if ($value !== null && $type === 'array' && isset($def['maxItems']) && is_int($def['maxItems'])) {
                if (count($value) > $def['maxItems']) {
                    $errors[] = "Field {$field} cannot exceed {$def['maxItems']} item(s).";
                }
            }
        }

        return $errors;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer', 'int' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean', 'bool' => is_bool($value),
            'object' => is_array($value),
            'array' => is_array($value),
            default => true,
        };
    }
}
