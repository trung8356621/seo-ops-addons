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
        return $this->validateValue($schema, $input, 'input');
    }

    /** @return list<string> */
    private function validateValue(array $schema, mixed $value, string $path): array
    {
        $errors = [];
        $type = (string) ($schema['type'] ?? '');
        if ($type !== '' && !$this->matchesType($value, $type)) {
            return ["Invalid type for {$path}; expected {$type}."];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "Invalid enum value for {$path}.";
        }
        if (is_numeric($value) && isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "Field {$path} must be at least {$schema['minimum']}.";
        }
        if (is_numeric($value) && isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "Field {$path} cannot exceed {$schema['maximum']}.";
        }

        if ($type === 'array' && is_array($value)) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                $errors[] = "Field {$path} requires at least {$schema['minItems']} item(s).";
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $errors[] = "Field {$path} cannot exceed {$schema['maxItems']} item(s).";
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $item) {
                    $errors = array_merge($errors, $this->validateValue($schema['items'], $item, "{$path}.{$index}"));
                }
            }
        }

        if ($type !== 'object' || !is_array($value)) {
            return $errors;
        }

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($required as $field) {
            if (!array_key_exists($field, $value) || $this->isEmptyValue($value[$field])) {
                $errors[] = "Missing required field: {$path}.{$field}";
            }
        }

        foreach ($value as $field => $fieldValue) {
            if (!array_key_exists($field, $properties)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "Unknown field: {$path}.{$field}";
                }
                continue;
            }

            $def = $properties[$field];
            if (!is_array($def)) {
                continue;
            }

            $errors = array_merge($errors, $this->validateValue($def, $fieldValue, "{$path}.{$field}"));
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

