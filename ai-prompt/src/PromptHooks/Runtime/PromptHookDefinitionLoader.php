<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookInputSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookKey;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookLimits;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookLocalePolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookLoggingPolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookModelConfig;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookOutputSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookRetryPolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookVersion;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;
use JsonException;

/**
 * Load Spec v0.1 JSON + dual-read Phase 1 manifests → canonical definitions only.
 */
final class PromptHookDefinitionLoader
{
    /** @var array<string, PromptHookDefinition>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $v01Directory,
        private readonly string $phase1Directory,
        private readonly PromptHookSpecV01Validator $specValidator = new PromptHookSpecV01Validator,
        private readonly PromptHookPhase1DualReadAdapter $phase1Adapter = new PromptHookPhase1DualReadAdapter,
    ) {}

    public static function defaultV01Directory(): string
    {
        // Runtime → PromptHooks → src → ai-prompt
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'prompt-hooks'.DIRECTORY_SEPARATOR.'v01';
    }

    public static function defaultPhase1Directory(): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'prompt-hooks';
    }

    /**
     * @return list<PromptHookDefinition>
     */
    public function loadAll(): array
    {
        return array_values($this->indexed());
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, PromptHookDefinition> keyed by key@version
     */
    public function indexed(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $byId = [];
        foreach ($this->loadJsonFiles($this->v01Directory) as $path => $decoded) {
            $definition = $this->hydrateSpecV01($decoded, $path);
            $id = $definition->cacheKey();
            if (isset($byId[$id])) {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Duplicate prompt hook key+version [{$id}] in {$path}",
                );
            }
            $byId[$id] = $definition;
        }

        foreach ($this->loadJsonFiles($this->phase1Directory, skipSubdirs: true) as $path => $decoded) {
            if (($decoded['spec_version'] ?? null) === '0.1') {
                continue;
            }
            $definition = $this->phase1Adapter->toCanonical($decoded, $path);
            $id = $definition->cacheKey();
            if (isset($byId[$id])) {
                // v01 wins over Phase 1 dual-read
                continue;
            }
            $byId[$id] = $definition;
        }

        return $this->cache = $byId;
    }

    /**
     * @return \Generator<string, array<string, mixed>>
     */
    private function loadJsonFiles(string $directory, bool $skipSubdirs = false): \Generator
    {
        if (! is_dir($directory)) {
            return;
        }

        $pattern = $skipSubdirs
            ? $directory.DIRECTORY_SEPARATOR.'*.json'
            : $directory.DIRECTORY_SEPARATOR.'*.json';

        foreach (glob($pattern) ?: [] as $file) {
            if ($skipSubdirs && is_dir(dirname($file).DIRECTORY_SEPARATOR.'v01') && str_contains($file, DIRECTORY_SEPARATOR.'v01'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $raw = file_get_contents($file);
            if ($raw === false || trim($raw) === '') {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Empty manifest: {$file}",
                );
            }
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Invalid JSON: {$file}",
                    $exception,
                );
            }
            if (! is_array($decoded)) {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Manifest root must be object: {$file}",
                );
            }
            yield $file => $decoded;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hydrateSpecV01(array $data, string $path = ''): PromptHookDefinition
    {
        $errors = $this->specValidator->validate($data);
        if ($errors !== []) {
            throw new PromptHookFailure(
                PromptHookFailureCode::DefinitionInvalid,
                'Spec v0.1 invalid'.($path !== '' ? " in {$path}" : '').': '.implode('; ', $errors),
            );
        }

        if (($data['enabled'] ?? true) === false) {
            $status = PromptHookStatus::Disabled;
        } else {
            $status = PromptHookStatus::fromMixed($data['status'] ?? $data['classification'] ?? 'experimental');
            if (($data['classification'] ?? '') === 'EXPERIMENTAL') {
                $status = PromptHookStatus::Experimental;
            }
        }

        $modelRaw = is_array($data['model'] ?? null) ? $data['model'] : [];
        $localeRaw = is_array($data['locale'] ?? null) ? $data['locale'] : [];
        $inputRaw = is_array($data['input_schema'] ?? null) ? $data['input_schema'] : [];
        $outputRaw = is_array($data['output_schema'] ?? null) ? $data['output_schema'] : [];
        $template = is_array($data['template'] ?? null) ? $data['template'] : [];
        $retryRaw = is_array($data['retry'] ?? null) ? $data['retry'] : [];
        $loggingRaw = is_array($data['logging'] ?? null) ? $data['logging'] : [];
        $limitsRaw = is_array($data['limits'] ?? null) ? $data['limits'] : [];
        $settingsSchema = is_array($data['settings'] ?? null) ? $data['settings'] : [];

        $sensitive = [];
        foreach ($inputRaw as $field => $schema) {
            if (is_array($schema) && ($schema['sensitive'] ?? false) === true) {
                $sensitive[] = (string) $field;
            }
        }

        return new PromptHookDefinition(
            key: new PromptHookKey((string) $data['key']),
            version: PromptHookVersion::parse($data['version'] ?? '0.1.0'),
            status: $status,
            name: (string) ($data['name'] ?? $data['key']),
            description: (string) ($data['description'] ?? ''),
            model: new PromptHookModelConfig(
                provider: (string) ($modelRaw['provider'] ?? 'prompt_connection'),
                name: (string) ($modelRaw['name'] ?? 'configured'),
                settings: is_array($modelRaw['settings'] ?? null) ? $modelRaw['settings'] : [],
                capability: (string) ($modelRaw['capability'] ?? 'text'),
                structuredOutput: (bool) ($modelRaw['structured_output'] ?? ($outputRaw['type'] ?? '') === 'json'),
            ),
            locale: new PromptHookLocalePolicy(
                mode: (string) ($localeRaw['mode'] ?? 'site'),
                fallback: (string) ($localeRaw['fallback'] ?? 'en'),
                fixed: isset($localeRaw['fixed']) ? (string) $localeRaw['fixed'] : null,
            ),
            inputSchema: new PromptHookInputSchema($inputRaw),
            outputSchema: $this->hydrateOutputSchema($outputRaw),
            template: $template,
            retry: new PromptHookRetryPolicy(
                max: (int) ($retryRaw['max'] ?? 0),
                on: array_values(array_map('strval', is_array($retryRaw['on'] ?? null) ? $retryRaw['on'] : [])),
            ),
            logging: new PromptHookLoggingPolicy(
                storeFullPrompt: (bool) ($loggingRaw['store_full_prompt'] ?? false),
                redactSensitive: (bool) ($loggingRaw['redact_sensitive'] ?? true),
            ),
            limits: new PromptHookLimits(
                maxPreviousOutputsTotalBytes: (int) ($limitsRaw['max_total_bytes'] ?? 200_000),
                maxPreviousOutputsItemBytes: (int) ($limitsRaw['max_item_bytes'] ?? 100_000),
                maxPreviousOutputsItems: (int) ($limitsRaw['max_items'] ?? 32),
                allowedPreviousOutputKeys: isset($limitsRaw['allowed_keys']) && is_array($limitsRaw['allowed_keys'])
                    ? array_values(array_map('strval', $limitsRaw['allowed_keys']))
                    : null,
            ),
            settingsSchema: $settingsSchema,
            sensitiveInputFields: $sensitive,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            manifestPath: $path,
            strictTemplateVariables: (bool) ($data['strict_template_variables'] ?? true),
            outputContract: isset($data['output_contract'])
                ? trim((string) $data['output_contract'])
                : null,
            settingsVisible: self::resolveSettingsVisible($data),
            category: self::resolveCategory($data),
            presentation: self::resolvePresentation($data),
        );
    }

    /**
     * @param  array<string, mixed>  $outputRaw
     */
    private function hydrateOutputSchema(array $outputRaw): PromptHookOutputSchema
    {
        $validation = is_array($outputRaw['validation'] ?? null) ? $outputRaw['validation'] : [];
        $combined = is_array($outputRaw['combined_output'] ?? null) ? $outputRaw['combined_output'] : [];
        $totalPort = trim((string) ($combined['output_port'] ?? 'total'));
        if ($totalPort === '') {
            $totalPort = 'total';
        }
        if (array_key_exists('preserve_markers', $combined)) {
            $validation['preserve_markers_in_total'] = (bool) $combined['preserve_markers'];
        }
        if (array_key_exists('reject_unknown_task_markers', $validation)
            && ! array_key_exists('strict_undeclared_markers', $validation)
        ) {
            $validation['strict_undeclared_markers'] = (bool) $validation['reject_unknown_task_markers'];
        }

        $sections = [];
        if (is_array($outputRaw['sections'] ?? null)) {
            foreach ($outputRaw['sections'] as $section) {
                if (is_array($section)) {
                    $sections[] = $section;
                }
            }
        }

        return new PromptHookOutputSchema(
            type: (string) ($outputRaw['type'] ?? 'text'),
            normalize: array_values(array_map('strval', is_array($outputRaw['normalize'] ?? null) ? $outputRaw['normalize'] : [])),
            validation: $validation,
            sections: $sections,
            totalPort: $totalPort,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveSettingsVisible(array $data): bool
    {
        if (array_key_exists('settings_visible', $data)) {
            return (bool) $data['settings_visible'];
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if (array_key_exists('settings_visible', $metadata)) {
            return (bool) $metadata['settings_visible'];
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveCategory(array $data): string
    {
        $category = trim((string) ($data['category'] ?? ''));
        if ($category !== '') {
            return $category;
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $fromMeta = trim((string) ($metadata['category'] ?? ''));

        return $fromMeta !== '' ? $fromMeta : 'general';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function resolvePresentation(array $data): array
    {
        $presentation = $data['presentation'] ?? null;
        if (! is_array($presentation)) {
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $presentation = is_array($metadata['presentation'] ?? null) ? $metadata['presentation'] : [];
        }

        return $presentation;
    }
}
