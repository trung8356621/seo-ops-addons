<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Safe JSON Schema subset validator for pack skill input/output.
 */
final class AgentPackSafeSchemaValidator
{
    /** @var list<string> */
    private const TYPES = [
        'string', 'integer', 'number', 'boolean', 'array', 'object',
        'enum', 'date', 'datetime', 'month', 'reference',
    ];

    /** @var list<string> */
    private const PATTERN_PRESETS = [
        'slug', 'email_local', 'alphanumeric', 'hash_id', 'ref',
    ];

    /** @var list<string> */
    private const REFERENCE_RESOLVERS = [
        'site', 'project', 'workspace', 'article', 'connection', 'pack',
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $schema, string $label = 'input_schema'): array
    {
        $errors = [];
        $blob = strtolower(json_encode($schema) ?: '');
        foreach (['$ref', 'remote', 'callback', 'php', 'javascript', 'sql', 'eval'] as $bad) {
            if (str_contains($blob, $bad) && $bad !== 'ref') {
                $errors[] = $label.'.forbidden:'.$bad;
            }
        }
        if (isset($schema['$ref']) || isset($schema['$schema'])) {
            $errors[] = $label.'.remote_schema_ref';
        }

        $fields = $schema['properties'] ?? $schema['fields'] ?? $schema;
        if (! is_array($fields)) {
            return ['ok' => false, 'errors' => [$label.'.invalid']];
        }

        // If top-level is JSON Schema object with properties.
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $fields = $schema['properties'];
        } elseif (isset($schema['fields']) && is_array($schema['fields'])) {
            $fields = $schema['fields'];
        } elseif ($this->looksLikeFieldMap($schema)) {
            $fields = $schema;
        } else {
            $fields = [];
        }

        foreach ($fields as $name => $def) {
            if (! is_string($name) || $name === '' || preg_match('/[^a-z0-9_]/', $name)) {
                $errors[] = $label.'.invalid_field_name:'.$name;

                continue;
            }
            if (! is_array($def)) {
                $errors[] = $label.'.invalid_field:'.$name;

                continue;
            }
            $type = (string) ($def['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                $errors[] = $label.'.unsupported_type:'.$name.':'.$type;
            }
            if (isset($def['pattern']) && is_string($def['pattern'])) {
                // Arbitrary regex forbidden — only presets.
                if (! in_array($def['pattern'], self::PATTERN_PRESETS, true)
                    && ! (isset($def['pattern_preset']) && in_array((string) $def['pattern_preset'], self::PATTERN_PRESETS, true))) {
                    if (! isset($def['pattern_preset'])) {
                        $errors[] = $label.'.arbitrary_regex:'.$name;
                    }
                }
            }
            if (isset($def['pattern_preset']) && ! in_array((string) $def['pattern_preset'], self::PATTERN_PRESETS, true)) {
                $errors[] = $label.'.bad_pattern_preset:'.$name;
            }
            if ($type === 'reference') {
                $resolver = (string) ($def['resolver'] ?? '');
                if (! in_array($resolver, self::REFERENCE_RESOLVERS, true)) {
                    $errors[] = $label.'.bad_reference_resolver:'.$name;
                }
            }
            if (isset($def['validator']) || isset($def['callback']) || isset($def['sql'])) {
                $errors[] = $label.'.executable_validator:'.$name;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function looksLikeFieldMap(array $schema): bool
    {
        foreach ($schema as $k => $v) {
            if (! is_string($k) || ! is_array($v)) {
                return false;
            }
            if (! isset($v['type'])) {
                return false;
            }
        }

        return $schema !== [];
    }
}
