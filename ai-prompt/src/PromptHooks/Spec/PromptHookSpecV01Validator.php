<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Spec;

/**
 * Phase 5A — validate Prompt Hook Specification v0.1 (fixture / proposed schema).
 * Không thay production PromptHookManifestLoader (schema hiện tại vẫn schema_version 1 runtime).
 */
final class PromptHookSpecV01Validator
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const ALLOWED_OUTPUT_TYPES = [
        'text',
        'markdown',
        'markdown_sections',
        'html',
        'json',
        'structured_object',
        'list',
        'score',
        'classification',
    ];

    private const ALLOWED_MODEL_SETTING_KEYS = [
        'temperature',
        'max_tokens',
        'top_p',
        'timeout_ms',
    ];

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string> Empty = valid
     */
    public function validate(array $spec): array
    {
        $errors = [];

        if (($spec['spec_version'] ?? null) !== '0.1') {
            $errors[] = 'spec_version must be "0.1"';
        }

        $key = (string) ($spec['key'] ?? '');
        if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
            $errors[] = 'invalid key (expect module.resource.verb style)';
        }

        $version = $spec['version'] ?? null;
        if (! is_string($version) && ! is_int($version)) {
            $errors[] = 'version required (string or int)';
        }

        if (array_key_exists('enabled', $spec) && ! is_bool($spec['enabled'])) {
            $errors[] = 'enabled must be boolean when present';
        }

        if (($spec['enabled'] ?? true) === false) {
            // Disabled is valid; callers must refuse execute.
            return $errors;
        }

        $model = $spec['model'] ?? null;
        if (! is_array($model)) {
            $errors[] = 'model object required';
        } else {
            $settings = $model['settings'] ?? [];
            if (! is_array($settings)) {
                $errors[] = 'model.settings must be object';
            } else {
                foreach (array_keys($settings) as $settingKey) {
                    if (! in_array((string) $settingKey, self::ALLOWED_MODEL_SETTING_KEYS, true)) {
                        $errors[] = "unsupported model.settings key [{$settingKey}]";
                    }
                }
            }
            if (isset($model['api_key']) || isset($model['secret'])) {
                $errors[] = 'secrets must not appear in hook JSON';
            }
        }

        $locale = $spec['locale'] ?? ['mode' => 'site', 'fallback' => 'en'];
        if (! is_array($locale)) {
            $errors[] = 'locale must be object';
        } else {
            $mode = (string) ($locale['mode'] ?? 'site');
            if (! in_array($mode, ['site', 'article', 'fixed', 'caller'], true)) {
                $errors[] = 'locale.mode invalid';
            }
            if (! isset($locale['fallback']) || ! is_string($locale['fallback']) || trim($locale['fallback']) === '') {
                $errors[] = 'locale.fallback required string';
            }
        }

        $inputSchema = $spec['input_schema'] ?? null;
        if (! is_array($inputSchema)) {
            $errors[] = 'input_schema required';
        } else {
            foreach ($inputSchema as $field => $schema) {
                if (! is_array($schema)) {
                    $errors[] = "input_schema.{$field} must be object";

                    continue;
                }
                if (isset($schema['type']) && ! is_string($schema['type']) && ! is_array($schema['type'])) {
                    $errors[] = "input_schema.{$field}.type invalid";
                }
                if (($schema['pass_eloquent'] ?? false) === true) {
                    $errors[] = "input_schema.{$field} must not pass Eloquent";
                }
            }
        }

        $outputSchema = $spec['output_schema'] ?? null;
        if (! is_array($outputSchema)) {
            $errors[] = 'output_schema required';
        } else {
            $type = (string) ($outputSchema['type'] ?? '');
            if (! in_array($type, self::ALLOWED_OUTPUT_TYPES, true)) {
                $errors[] = 'output_schema.type invalid';
            }
            if ($type === 'markdown_sections') {
                $sections = $outputSchema['sections'] ?? null;
                if (! is_array($sections) || $sections === []) {
                    $errors[] = 'output_schema.sections required for markdown_sections';
                } else {
                    foreach ($sections as $index => $section) {
                        if (! is_array($section)) {
                            $errors[] = "output_schema.sections[{$index}] must be object";

                            continue;
                        }
                        foreach (['key', 'start_marker', 'end_marker', 'output_port'] as $requiredKey) {
                            if (trim((string) ($section[$requiredKey] ?? '')) === '') {
                                $errors[] = "output_schema.sections[{$index}].{$requiredKey} required";
                            }
                        }
                    }
                }
            }
        }

        $template = $spec['template'] ?? null;
        if (! is_array($template)) {
            $errors[] = 'template required';
        } else {
            if (isset($template['php']) || isset($template['eval'])) {
                $errors[] = 'template must not contain executable PHP';
            }
            $source = (string) ($template['source'] ?? '');
            if ($source !== '' && ! in_array($source, ['inline', 'legacy_prompt_content'], true)) {
                $errors[] = 'template.source invalid';
            }
            if ($source === 'legacy_prompt_content') {
                // Prompt DB content is template SoT — JSON must not duplicate full prompt body.
                if (trim((string) ($template['system'] ?? '')) !== '' || trim((string) ($template['user'] ?? '')) !== '') {
                    $errors[] = 'legacy_prompt_content template must not embed system/user bodies';
                }
            }
        }

        if (isset($spec['side_effects']) && is_array($spec['side_effects'])) {
            foreach ($spec['side_effects'] as $effect) {
                if (in_array((string) $effect, ['eloquent_save', 'wordpress_sync', 'domain_write'], true)) {
                    $errors[] = 'hook must not declare domain write side effects';
                }
            }
        }

        if (array_key_exists('output_contract', $spec)) {
            $contract = $spec['output_contract'];
            if ($contract !== null && (! is_string($contract) || preg_match(self::KEY_PATTERN, trim($contract)) !== 1)) {
                $errors[] = 'output_contract must be null or contract key (module.resource style)';
            }
        }

        return $errors;
    }

    public function isValid(array $spec): bool
    {
        return $this->validate($spec) === [];
    }
}
