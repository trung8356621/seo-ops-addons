<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;

/**
 * Validate agent input against registry JSON Schema.
 */
final class ContentProjectAgentSchemaValidator
{
    public function __construct(
        private readonly ContentProjectCapabilityRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function validate(string $capability, array $input): array
    {
        $schema = $this->registry->jsonSchema($capability);
        if ($schema === null) {
            return ['Unknown capability schema.'];
        }

        $errors = [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($required as $field) {
            if (! array_key_exists($field, $input) || $this->isEmptyValue($input[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        foreach ($input as $field => $value) {
            if (! array_key_exists($field, $properties)) {
                $errors[] = "Unknown field: {$field}";

                continue;
            }

            $def = $properties[$field];
            if (! is_array($def)) {
                continue;
            }

            $type = (string) ($def['type'] ?? 'string');
            if (! $this->matchesType($value, $type)) {
                $errors[] = "Invalid type for {$field}; expected {$type}.";

                continue;
            }

            if ($type === 'string' && isset($def['enum']) && is_array($def['enum'])) {
                if (! in_array($value, $def['enum'], true)) {
                    $errors[] = "Invalid enum value for {$field}.";
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
            'boolean' => is_bool($value),
            'object' => is_array($value),
            'array' => is_array($value),
            default => true,
        };
    }
}
