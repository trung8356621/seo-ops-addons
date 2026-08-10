<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Data;

/**
 * @phpstan-type FieldSchema array{
 *     type?: mixed,
 *     required?: bool,
 *     normalize?: list<string>,
 *     sources?: list<array<string, mixed>>,
 *     expose_to_prompt?: bool
 * }
 * @phpstan-type SettingSchema array{
 *     type: string,
 *     default?: mixed,
 *     min?: int|float,
 *     max?: int|float,
 *     label_key?: string
 * }
 */
final class PromptHookDefinition
{
    /**
     * @param  array<string, FieldSchema>  $inputFields
     * @param  list<string>  $promptPayload
     * @param  array<string, SettingSchema>  $settings
     * @param  array<string, mixed>|null  $template
     * @param  array<string, mixed>  $output
     * @param  array{capability: string, structured_output: bool}  $model
     * @param  array{path?: string}|null  $documentation
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $key,
        public readonly int $version,
        public readonly string $labelKey,
        public readonly string $descriptionKey,
        public readonly array $model,
        public readonly array $inputFields,
        public readonly array $promptPayload,
        public readonly array $settings,
        public readonly ?array $template,
        public readonly array $output,
        public readonly string $manifestPath,
        public readonly ?array $documentation = null,
    ) {}

    public function documentationPath(): ?string
    {
        $path = trim((string) ($this->documentation['path'] ?? ''));

        return $path !== '' ? $path : null;
    }

    public function capability(): string
    {
        return (string) ($this->model['capability'] ?? 'text');
    }

    public function requiresStructuredOutput(): bool
    {
        return (bool) ($this->model['structured_output'] ?? false);
    }

    public function outputFormat(): string
    {
        return (string) ($this->output['format'] ?? 'text');
    }

    /**
     * @return list<string>
     */
    public function outputNormalizeSteps(): array
    {
        $steps = $this->output['normalize'] ?? [];

        return is_array($steps) ? array_values(array_map('strval', $steps)) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputValidation(): array
    {
        $validation = $this->output['validation'] ?? [];

        return is_array($validation) ? $validation : [];
    }

    public function label(): string
    {
        return (string) __('seo-content-ai::'.$this->labelKey);
    }

    public function description(): string
    {
        return (string) __('seo-content-ai::'.$this->descriptionKey);
    }
}
