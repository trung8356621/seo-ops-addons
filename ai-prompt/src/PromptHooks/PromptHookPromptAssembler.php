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
 * 1. Base = PromptRunnerService::compileInstructions (system/user buckets)
 * 2. Hook template from locale → user side only (before_prompt | after_prompt)
 * 3. Output contract → user side only (never into system)
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
        $instructions = $this->promptRunner->compileInstructions($prompt, $variables);
        $system = $instructions->systemText();
        $user = $instructions->userText();
        // Prompts with only system-side parts: keep content on user for APIs that require user.
        if ($user === '' && $system !== '') {
            $user = $system;
            $system = '';
        }

        $hookTemplate = $this->templateRenderer->render($definition, $exposedInput, $resolvedSettings);
        // Hook locale template stays on the user side — not merged into system.
        if ($hookTemplate !== null && trim($hookTemplate) !== '') {
            $tpl = trim($hookTemplate);
            $user = $this->templateRenderer->position($definition) === 'before_prompt'
                ? $tpl."\n\n".$user
                : trim($user."\n\n".$tpl);
        }

        $contractKey = null;
        if (isset($definition->output['contract'])) {
            $contractKey = trim((string) $definition->output['contract']);
        } elseif (isset($definition->output['output_contract'])) {
            $contractKey = trim((string) $definition->output['output_contract']);
        }

        // Output contracts append to user instructions only (never into system).
        $appended = $this->contracts()->appendToPrompt($user, $contractKey !== '' ? $contractKey : null);
        $user = $appended['prompt'];

        $finalPrompt = $system !== ''
            ? trim($system)."\n\n".trim($user)
            : trim($user);
        if (ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline()) {
            $config = PromptPostProcessing::fromPrompt($prompt);
            $finalPrompt = app(ImageOutputModePromptInjector::class)->inject($finalPrompt, $config);
            $user = $finalPrompt;
            $system = '';
        }

        $variables['_compiled_system'] = $system;
        $variables['_compiled_user'] = $user;

        return [
            'final_prompt' => $finalPrompt,
            'variables' => $variables,
            'output_contracts' => $appended['contracts'],
            'system' => $system,
            'user' => $user,
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
