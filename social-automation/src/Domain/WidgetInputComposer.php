<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Manual + AI Auto both produce the same validated Widget input payload.
 * AI never invents account identity / verified URLs — those stay manual/static.
 */
final class WidgetInputComposer
{
    public function __construct(
        private readonly WidgetFormSchema $formSchema = new WidgetFormSchema,
        private readonly AiAutoFieldPolicy $aiPolicy = new AiAutoFieldPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $widgetManifest
     * @param  array<string, mixed>  $manualValues
     * @param  array<string, mixed>  $aiValues
     * @param  array<string, mixed>  $staticValues
     * @return array{ok: bool, mode: string, inputs: array<string, mixed>, errors: list<string>, ai_plan: list<array<string, mixed>>, field_sources: array<string, string>}
     */
    public function compose(
        array $widgetManifest,
        string $mode,
        array $manualValues = [],
        array $aiValues = [],
        array $staticValues = [],
    ): array {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['manual', 'ai_auto'], true)) {
            return [
                'ok' => false,
                'mode' => $mode,
                'inputs' => [],
                'errors' => ['input_mode_invalid'],
                'ai_plan' => [],
                'field_sources' => [],
            ];
        }

        $parsed = $this->formSchema->parse($widgetManifest);
        if (! ($parsed['ok'] ?? false)) {
            return [
                'ok' => false,
                'mode' => $mode,
                'inputs' => [],
                'errors' => $parsed['errors'],
                'ai_plan' => [],
                'field_sources' => [],
            ];
        }

        /** @var list<array<string, mixed>> $fields */
        $fields = $parsed['fields'];
        if ($fields === []) {
            // Legacy widget without form_fields: pass through manual values only.
            return [
                'ok' => true,
                'mode' => $mode,
                'inputs' => $manualValues,
                'errors' => [],
                'ai_plan' => [],
                'field_sources' => [],
            ];
        }

        $aiPlan = $this->aiPolicy->plan($fields);
        $inputs = [];
        $sources = [];
        $errors = [];

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $value = null;
            $source = 'none';

            if (array_key_exists($key, $staticValues)) {
                $value = $staticValues[$key];
                $source = 'static';
            } elseif (array_key_exists($key, $manualValues)) {
                $value = $manualValues[$key];
                $source = 'manual';
            } elseif ($mode === 'ai_auto' && array_key_exists($key, $aiValues)) {
                if (! $this->aiPolicy->mayAcceptAiValue($field)) {
                    $errors[] = 'ai_forbidden_field:'.$key;
                    continue;
                }
                $value = $aiValues[$key];
                $source = 'ai';
            } elseif (array_key_exists('default', $field) && $field['default'] !== null) {
                $value = $field['default'];
                $source = 'default';
            }

            if ($value === null || $value === '') {
                if ((bool) ($field['required'] ?? false) && ($field['visibility'] ?? 'exposed') !== 'hidden') {
                    $errors[] = 'field_required:'.$key;
                }
                continue;
            }

            $fieldErrors = $this->validateField($field, $value);
            foreach ($fieldErrors as $err) {
                $errors[] = $err;
            }
            if ($fieldErrors === []) {
                $inputs[$key] = $value;
                $sources[$key] = $source;
            }
        }

        return [
            'ok' => $errors === [],
            'mode' => $mode,
            'inputs' => $inputs,
            'errors' => $errors,
            'ai_plan' => $aiPlan,
            'field_sources' => $sources,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function validateField(array $field, mixed $value): array
    {
        $key = (string) $field['key'];
        $type = (string) $field['input_type'];
        $rules = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $errors = [];

        if ($type === 'integer' && (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value)))) {
            $errors[] = 'field_type_mismatch:'.$key;
        }
        if ($type === 'number' && ! is_numeric($value)) {
            $errors[] = 'field_type_mismatch:'.$key;
        }
        if (in_array($type, ['text', 'textarea', 'url', 'select'], true) && ! is_string($value) && ! is_numeric($value)) {
            $errors[] = 'field_type_mismatch:'.$key;
        }
        if ($type === 'files' && ! is_array($value)) {
            $errors[] = 'field_type_mismatch:'.$key;
        }
        if ($type === 'file' && ! is_string($value)) {
            $errors[] = 'field_type_mismatch:'.$key;
        }
        if ($type === 'boolean' && ! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            $errors[] = 'field_type_mismatch:'.$key;
        }

        if (is_string($value)) {
            $minLength = isset($rules['minLength']) ? (int) $rules['minLength'] : null;
            $maxLength = isset($rules['maxLength']) ? (int) $rules['maxLength'] : null;
            $len = mb_strlen($value);
            if ($minLength !== null && $len < $minLength) {
                $errors[] = 'field_min_length:'.$key;
            }
            if ($maxLength !== null && $len > $maxLength) {
                $errors[] = 'field_max_length:'.$key;
            }
            $pattern = isset($rules['pattern']) ? (string) $rules['pattern'] : '';
            if ($pattern !== '' && @preg_match('/'.$pattern.'/', '') !== false && ! preg_match('/'.$pattern.'/', $value)) {
                $errors[] = 'field_pattern:'.$key;
            }
        }

        if (is_numeric($value)) {
            $num = (float) $value;
            if (isset($rules['min']) && $num < (float) $rules['min']) {
                $errors[] = 'field_min:'.$key;
            }
            if (isset($rules['max']) && $num > (float) $rules['max']) {
                $errors[] = 'field_max:'.$key;
            }
        }

        return $errors;
    }
}
