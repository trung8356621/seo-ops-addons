<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use JsonException;
use Throwable;

final class PromptHookManifestLoader
{
    public function __construct(
        private readonly string $manifestDirectory,
        private readonly bool $failFast = false,
    ) {}

    public static function defaultDirectory(): string
    {
        // PromptHooks → src → ai-prompt
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'prompt-hooks';
    }

    /**
     * @return list<PromptHookDefinition>
     */
    public function loadAll(): array
    {
        if (! is_dir($this->manifestDirectory)) {
            if ($this->failFast) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookManifestInvalid,
                    "Prompt hook manifest directory missing: {$this->manifestDirectory}",
                );
            }

            return [];
        }

        $files = glob($this->manifestDirectory.DIRECTORY_SEPARATOR.'*.json') ?: [];
        $definitions = [];
        $seenKeys = [];

        foreach ($files as $file) {
            try {
                $definition = $this->loadFile($file);
            } catch (Throwable $exception) {
                if ($this->failFast) {
                    throw $exception;
                }

                logger()->error('Prompt hook manifest failed to load', [
                    'file' => $file,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if (isset($seenKeys[$definition->key])) {
                $message = "Duplicate prompt hook key [{$definition->key}] in {$file}";
                if ($this->failFast) {
                    throw new PromptHookException(PromptHookErrorCode::HookManifestInvalid, $message);
                }

                logger()->error($message, ['file' => $file]);

                continue;
            }

            $seenKeys[$definition->key] = true;
            $definitions[] = $definition;
        }

        return $definitions;
    }

    public function loadFile(string $path): PromptHookDefinition
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest not readable: {$path}",
            );
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest empty: {$path}",
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Invalid JSON in {$path}: ".$exception->getMessage(),
                $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest root must be object: {$path}",
            );
        }

        return $this->hydrate($decoded, $path);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hydrate(array $data, string $path = ''): PromptHookDefinition
    {
        foreach (['schema_version', 'key', 'version', 'label_key', 'description_key', 'model', 'input', 'output'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookManifestInvalid,
                    "Manifest missing [{$required}]".($path !== '' ? " in {$path}" : ''),
                );
            }
        }

        $key = trim((string) $data['key']);
        if ($key === '') {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                'Manifest key must be non-empty.',
            );
        }

        $model = $data['model'];
        if (! is_array($model) || ! isset($model['capability'])) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] model.capability is required.",
            );
        }

        $input = $data['input'];
        if (! is_array($input)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] input must be object.",
            );
        }

        $fields = $input['fields'] ?? [];
        if (! is_array($fields)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] input.fields must be object.",
            );
        }

        $promptPayload = $input['prompt_payload'] ?? array_keys($fields);
        if (! is_array($promptPayload)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] input.prompt_payload must be array.",
            );
        }

        $settings = $data['settings'] ?? [];
        if (! is_array($settings)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] settings must be object.",
            );
        }

        $template = $data['template'] ?? null;
        if ($template !== null && ! is_array($template)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] template must be object or null.",
            );
        }

        if (is_array($template) && ($template['nullable'] ?? true) === false) {
            $templateKey = trim((string) ($template['template_key'] ?? ''));
            if ($templateKey === '') {
                throw new PromptHookException(
                    PromptHookErrorCode::HookManifestInvalid,
                    "Manifest [{$key}] template.template_key is required.",
                );
            }
        }

        $output = $data['output'];
        if (! is_array($output) || ! isset($output['format'])) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] output.format is required.",
            );
        }

        $documentation = $data['documentation'] ?? null;
        if ($documentation !== null && ! is_array($documentation)) {
            throw new PromptHookException(
                PromptHookErrorCode::HookManifestInvalid,
                "Manifest [{$key}] documentation must be object or null.",
            );
        }

        return new PromptHookDefinition(
            schemaVersion: (int) $data['schema_version'],
            key: $key,
            version: (int) $data['version'],
            labelKey: (string) $data['label_key'],
            descriptionKey: (string) $data['description_key'],
            model: [
                'capability' => (string) $model['capability'],
                'structured_output' => (bool) ($model['structured_output'] ?? false),
            ],
            inputFields: $fields,
            promptPayload: array_values(array_map('strval', $promptPayload)),
            settings: $settings,
            template: $template,
            output: $output,
            manifestPath: $path,
            documentation: is_array($documentation) ? $documentation : null,
        );
    }
}
