<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\Services\ImageOutputModePromptInjector;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;

/**
 * Single place to assemble final prompt for hooks.
 *
 * Order:
 * 1. Base = PromptRunnerService::compilePrompt (markdown + {{variables}})
 * 2. Hook template from locale (before_prompt | after_prompt)
 * 3. Output contract (if definition.output_contract set)
 *
 * Variables include:
 * - Each expose_to_prompt field
 * - Serialized [HOOK_INPUT]…[/HOOK_INPUT] as {{input}} (and hook_input)
 * - Resolved settings as {{setting_key}}
 */
final class PromptHookPromptAssembler
{
    private ?PromptOutputContractResolver $contractResolver;

    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly PromptHookTemplateRenderer $templateRenderer,
        ?PromptOutputContractResolver $contractResolver = null,
    ) {
        $this->contractResolver = $contractResolver;
    }

    private function contracts(): PromptOutputContractResolver
    {
        return $this->contractResolver ??= new PromptOutputContractResolver(
            new PromptOutputContractCatalog(PromptOutputContractCatalog::defaultDirectory()),
        );
    }

    /**
     * @param  array<string, mixed>  $exposedInput
     * @param  array<string, mixed>  $resolvedSettings
     * @return array{
     *     final_prompt: string,
     *     variables: array<string, string>,
     *     output_contracts: list<array{key: string, version: string}>
     * }
     */
    public function assemble(
        PromptHookDefinition $definition,
        SeoPrompt $prompt,
        array $exposedInput,
        array $resolvedSettings,
    ): array {
        $variables = $this->buildVariables($exposedInput, $resolvedSettings);
        $base = $this->promptRunner->compilePrompt($prompt, $variables);
        $hookTemplate = $this->templateRenderer->render($definition, $exposedInput, $resolvedSettings);

        $final = $base;
        if ($hookTemplate !== null && trim($hookTemplate) !== '') {
            $final = $this->templateRenderer->position($definition) === 'before_prompt'
                ? trim($hookTemplate)."\n\n".trim($base)
                : trim($base)."\n\n".trim($hookTemplate);
        }

        $contractKey = null;
        if (isset($definition->output['contract'])) {
            $contractKey = trim((string) $definition->output['contract']);
        } elseif (isset($definition->output['output_contract'])) {
            $contractKey = trim((string) $definition->output['output_contract']);
        }

        $appended = $this->contracts()->appendToPrompt($final, $contractKey !== '' ? $contractKey : null);

        $finalPrompt = $appended['prompt'];
        if (ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline()) {
            $config = PromptPostProcessing::fromPrompt($prompt);
            $finalPrompt = app(ImageOutputModePromptInjector::class)->inject($finalPrompt, $config);
        }

        return [
            'final_prompt' => $finalPrompt,
            'variables' => $variables,
            'output_contracts' => $appended['contracts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $exposedInput
     * @param  array<string, mixed>  $resolvedSettings
     * @return array<string, string>
     */
    public function buildVariables(array $exposedInput, array $resolvedSettings): array
    {
        $variables = [];
        foreach ($exposedInput as $key => $value) {
            $variables[$key] = $this->stringify($value);
        }
        foreach ($resolvedSettings as $key => $value) {
            $variables[$key] = $this->stringify($value);
        }

        $inputBlock = $this->serializeHookInput($exposedInput);
        $variables['hook_input'] = $inputBlock;

        // Prefer explicit scalar {{input}} (Content Project canonical subject) over HOOK_INPUT dump.
        $scalarInput = isset($exposedInput['input']) && is_scalar($exposedInput['input'])
            ? trim((string) $exposedInput['input'])
            : '';
        $variables['input'] = $scalarInput !== '' ? $scalarInput : $inputBlock;

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $exposedInput
     */
    private function serializeHookInput(array $exposedInput): string
    {
        $lines = ['[HOOK_INPUT]'];
        foreach ($exposedInput as $key => $value) {
            $lines[] = $key.': '.$this->stringify($value);
        }
        $lines[] = '[/HOOK_INPUT]';

        return implode("\n", $lines);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
