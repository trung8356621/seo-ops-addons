<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Widget = form/validation layer over a pinned Script release.
 * Does not copy Script steps into the Widget.
 */
final class WidgetFormSchema
{
    public const INPUT_TYPES = [
        'text', 'textarea', 'number', 'integer', 'file', 'files',
        'select', 'url', 'hidden', 'boolean',
    ];

    public const VISIBILITY = ['exposed', 'hidden'];

    public const AI_POLICIES = ['eligible', 'forbidden', 'manual_only'];

    public const DATA_SOURCE_TYPES = ['manual', 'static', 'ai', 'workflow_output', 'data_source'];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{ok: bool, errors: list<string>, fields: list<array<string, mixed>>, script_ref: ?array{package_key: string, version: string, content_digest: string}}
     */
    public function parse(array $manifest): array
    {
        $errors = [];
        $scriptKey = trim((string) ($manifest['script_key'] ?? ''));
        $scriptVersion = trim((string) ($manifest['script_version'] ?? ''));
        $scriptDigest = strtolower(trim((string) ($manifest['script_digest'] ?? '')));

        $scriptRef = null;
        if ($scriptKey !== '' || $scriptVersion !== '' || $scriptDigest !== '') {
            if ($scriptKey === '' || $scriptVersion === '' || ! preg_match('/^[a-f0-9]{64}$/', $scriptDigest)) {
                $errors[] = 'widget_script_ref_invalid';
            } else {
                $scriptRef = [
                    'package_key' => $scriptKey,
                    'version' => $scriptVersion,
                    'content_digest' => $scriptDigest,
                ];
            }
        }

        $rawFields = $manifest['form_fields'] ?? null;
        if ($rawFields === null) {
            // Legacy widgets may omit form_fields (steps-only).
            return ['ok' => $errors === [], 'errors' => $errors, 'fields' => [], 'script_ref' => $scriptRef];
        }

        if (! is_array($rawFields) || ! array_is_list($rawFields)) {
            return [
                'ok' => false,
                'errors' => array_merge($errors, ['widget_form_fields_invalid']),
                'fields' => [],
                'script_ref' => $scriptRef,
            ];
        }

        $fields = [];
        $keys = [];
        foreach ($rawFields as $i => $field) {
            if (! is_array($field)) {
                $errors[] = 'widget_form_field_invalid:'.$i;
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            $label = trim((string) ($field['label'] ?? ''));
            $inputType = strtolower(trim((string) ($field['input_type'] ?? $field['type'] ?? '')));
            $visibility = strtolower(trim((string) ($field['visibility'] ?? 'exposed')));
            $aiPolicy = strtolower(trim((string) ($field['ai_policy'] ?? 'eligible')));

            if ($key === '' || isset($keys[$key])) {
                $errors[] = 'widget_form_field_key_invalid:'.$i;
                continue;
            }
            if ($label === '') {
                $errors[] = 'widget_form_field_label_required:'.$i;
            }
            if (! in_array($inputType, self::INPUT_TYPES, true)) {
                $errors[] = 'widget_form_field_type_invalid:'.$i;
            }
            if (! in_array($visibility, self::VISIBILITY, true)) {
                $errors[] = 'widget_form_field_visibility_invalid:'.$i;
            }
            if (! in_array($aiPolicy, self::AI_POLICIES, true)) {
                $errors[] = 'widget_form_field_ai_policy_invalid:'.$i;
            }

            $binding = is_array($field['binding'] ?? null) ? $field['binding'] : [];
            $variableKey = trim((string) ($binding['variable_key'] ?? $binding['key'] ?? $key));
            if ($variableKey === '') {
                $errors[] = 'widget_form_field_binding_invalid:'.$i;
            }

            $dataSource = is_array($field['data_source'] ?? null) ? $field['data_source'] : ['type' => 'manual'];
            $dsType = strtolower(trim((string) ($dataSource['type'] ?? 'manual')));
            if (! in_array($dsType, self::DATA_SOURCE_TYPES, true)) {
                $errors[] = 'widget_form_field_data_source_invalid:'.$i;
            }

            $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];

            $keys[$key] = true;
            $fields[] = [
                'key' => $key,
                'label' => $label,
                'input_type' => $inputType,
                'required' => (bool) ($field['required'] ?? false),
                'default' => $field['default'] ?? null,
                'validation' => $validation,
                'visibility' => $visibility,
                'help' => isset($field['help']) ? trim((string) $field['help']) : null,
                'binding' => ['variable_key' => $variableKey],
                'data_source' => array_merge($dataSource, ['type' => $dsType]),
                'ai_policy' => $aiPolicy,
            ];
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'fields' => $fields,
            'script_ref' => $scriptRef,
        ];
    }
}
