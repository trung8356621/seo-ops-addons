<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptStructuredStrategy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBinding;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRenderedPromptCompiler;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;

/**
 * Mode 2 Prompt Hook path: Settings binding → Runtime Registry (v01) →
 * DeterministicTemplateRenderer → RenderedPromptCompiler.
 * Parent/Child: compile text only (Gemini + inlineData elsewhere).
 * Planner: full text execute via ExplicitBindingExecutor.
 */
final class ProductGalleryPromptHookRuntime
{
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly PromptBindingResolver $bindings,
        private readonly PromptHookRuntimeRegistry $registry,
        private readonly PromptRunnerService $promptRunner,
        private readonly PromptHookDeterministicTemplateRenderer $templateRenderer,
        private readonly PromptHookRenderedPromptCompiler $compiler,
        private readonly PromptHookRuntimeSettingsResolver $settingsResolver,
        private readonly PromptHookExplicitBindingExecutor $executor,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{final_prompt: string, prompt_id: int, hook_key: string, hook_version: string}
     */
    public function compile(string $hookKey, array $variables): array
    {
        [$prompt, $definition] = $this->resolveBound($hookKey);

        $stringVars = [];
        foreach ($variables as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $stringVars[(string) $key] = $value === null ? '' : (string) $value;
            }
        }

        try {
            $this->assertRequiredInputs($definition->inputSchema->fields, $stringVars);

            $settings = $this->settingsResolver->resolve(
                $definition,
                is_array($prompt->hook_settings) ? $prompt->hook_settings : [],
                [],
            );

            $legacy = trim($this->promptRunner->compilePrompt($prompt, $stringVars));
            if ($legacy === '') {
                throw new \RuntimeException('prompt_variable_missing:compiled_empty');
            }

            $locale = [
                'locale_code' => (string) ($stringVars['language'] ?? 'vi'),
                'language_name' => (string) ($stringVars['language'] ?? 'Vietnamese'),
            ];

            $request = $this->templateRenderer->render(
                $definition,
                array_merge($stringVars, $settings['hook']),
                $locale,
                $settings['model'],
                [
                    'legacy_compiled_prompt' => $legacy,
                    'variables' => $stringVars,
                    'prompt_id' => (int) $prompt->id,
                ],
            );

            $strategy = $definition->model->structuredOutput
                ? PromptStructuredStrategy::PromptEnforcedJson
                : PromptStructuredStrategy::PlainText;

            $final = trim($this->compiler->compile($request, $strategy));
        } catch (InvalidInput $exception) {
            throw new \RuntimeException('prompt_variable_missing', 0, $exception);
        } catch (PromptHookFailure $exception) {
            throw new \RuntimeException($this->mapFailure($exception), 0, $exception);
        }

        if ($final === '') {
            throw new \RuntimeException('prompt_variable_missing:compiled_empty');
        }

        return [
            'final_prompt' => $final,
            'prompt_id' => (int) $prompt->id,
            'hook_key' => $hookKey,
            'hook_version' => $definition->version->toString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $contextExtras
     */
    public function executeText(string $hookKey, array $variables, array $contextExtras = []): string
    {
        [$prompt] = $this->resolveBound($hookKey);

        try {
            $result = $this->executor->execute($prompt, $variables, $contextExtras);
        } catch (PromptHookFailure $exception) {
            throw new \RuntimeException($this->mapFailure($exception), 0, $exception);
        } catch (PromptHookException $exception) {
            throw new \RuntimeException($this->mapLegacyException($exception), 0, $exception);
        }

        $raw = trim((string) ($result['raw'] ?? $result['output'] ?? $result['value'] ?? ''));
        if ($raw === '') {
            throw new \RuntimeException('planner_invalid_output');
        }

        return $raw;
    }

    /**
     * @return array{0: SeoPrompt, 1: \Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition}
     */
    private function resolveBound(string $hookKey): array
    {
        if (! $this->registry->has($hookKey, self::VERSION)) {
            throw new \RuntimeException('prompt_hook_binding_missing:hook_definition');
        }

        try {
            $prompt = $this->bindings->resolveSettingsHook($hookKey);
        } catch (PromptHookException $exception) {
            throw new \RuntimeException($this->mapLegacyException($exception), 0, $exception);
        }

        $binding = PromptHookBinding::tryFromPrompt($prompt);
        if ($binding === null) {
            throw new \RuntimeException('prompt_hook_binding_missing:prompt_hook_key');
        }
        if ($binding->hookKey !== $hookKey) {
            throw new \RuntimeException('prompt_hook_binding_missing:hook_mismatch');
        }

        $definition = $this->registry->get($binding->hookKey, $binding->hookVersion);

        return [$prompt, $definition];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, string>  $variables
     */
    private function assertRequiredInputs(array $fields, array $variables): void
    {
        foreach ($fields as $field => $schema) {
            if (! is_array($schema) || ! (bool) ($schema['required'] ?? false)) {
                continue;
            }
            $value = trim((string) ($variables[$field] ?? ''));
            if ($value === '') {
                throw new InvalidInput("Required input [{$field}] missing.");
            }
        }
    }

    private function mapFailure(PromptHookFailure $exception): string
    {
        if ($exception instanceof InvalidInput) {
            return 'prompt_variable_missing';
        }

        $code = $exception->failureCode->value;

        return match ($code) {
            'INVALID_INPUT' => 'prompt_variable_missing',
            'DEFINITION_NOT_FOUND', 'VERSION_NOT_FOUND', 'HOOK_DISABLED' => 'prompt_hook_binding_missing',
            default => 'prompt_hook_binding_missing:'.$code,
        };
    }

    private function mapLegacyException(PromptHookException $exception): string
    {
        return match ($exception->errorCode) {
            PromptHookErrorCode::HookPromptNotConfigured => str_contains($exception->getMessage(), 'missing')
                ? 'prompt_not_found'
                : 'prompt_hook_binding_missing',
            PromptHookErrorCode::HookPromptMismatch => 'prompt_hook_binding_missing',
            PromptHookErrorCode::HookInputInvalid => 'prompt_variable_missing',
            default => 'prompt_hook_binding_missing',
        };
    }
}
