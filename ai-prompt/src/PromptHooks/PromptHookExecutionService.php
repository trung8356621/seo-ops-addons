<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookExecutionResult;
use Omnichannel\Addons\AiPrompt\PromptHooks\Entities\ArticlePromptHookEntityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\AiPrompt\Services\PromptResultAttachService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Media\Support\ImageToolType;

final class PromptHookExecutionService
{
    public function __construct(
        private readonly PromptHookRegistry $registry,
        private readonly ArticlePromptHookEntityResolver $articleResolver,
        private readonly PromptHookInputResolver $inputResolver,
        private readonly PromptHookSettingsResolver $settingsResolver,
        private readonly PromptHookPromptAssembler $promptAssembler,
        private readonly PromptHookOutputNormalizer $outputNormalizer,
        private readonly PromptRunnerService $promptRunner,
        private readonly PromptResultAttachService $promptResultAttach,
    ) {}

    /**
     * @param  array<string, mixed>  $runtimeInput
     */
    public function execute(
        string $hookKey,
        int $articleId,
        array $runtimeInput = [],
        ?SeoPrompt $prompt = null,
    ): PromptHookExecutionResult {
        $definition = $this->registry->get($hookKey);
        $article = $this->articleResolver->loadAuthorized($articleId);
        $entityContext = $this->articleResolver->buildContext($article);

        $prompt ??= $this->resolveConfiguredPrompt($definition);
        $this->assertPromptMatchesHook($prompt, $definition);
        $this->assertPromptModelSupported($prompt, $definition);

        $resolvedInput = $this->inputResolver->resolve($definition, $runtimeInput, $entityContext);
        $exposedInput = $this->inputResolver->exposeToPrompt($definition, $resolvedInput);
        $resolvedSettings = $this->settingsResolver->resolve(
            $definition,
            is_array($prompt->hook_settings) ? $prompt->hook_settings : null,
        );

        $assembled = $this->promptAssembler->assemble(
            $definition,
            $prompt,
            $exposedInput,
            $resolvedSettings,
        );

        try {
            $result = $this->promptRunner->runWithCompiledPrompt(
                $prompt,
                $assembled['final_prompt'],
                $assembled['variables'],
            );
        } catch (PromptRunException $exception) {
            throw new PromptHookException(
                PromptHookErrorCode::HookExecutionFailed,
                $exception->getMessage(),
                $exception,
            );
        } catch (\Throwable $exception) {
            throw new PromptHookException(
                PromptHookErrorCode::HookExecutionFailed,
                'Prompt hook execution failed.',
                $exception,
            );
        }

        // Orchestrator attach via domain service (same boundary as Action prompt_result.attach).
        // Hook Runtime Engine must never call this path.
        $this->attachPromptResultAfterExecution($article, $definition, $prompt, $result);

        $raw = trim((string) ($result->output_text ?? ''));
        $output = $this->outputNormalizer->normalize($definition, $raw, $resolvedInput);

        $lengthValidation = is_array($output['length_validation'] ?? null)
            ? $output['length_validation']
            : null;
        if ($lengthValidation !== null && $result->id !== null) {
            $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
            $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
            foreach ($lengthValidation as $key => $value) {
                $variables[$key] = $value;
                $snapshot[$key] = $value;
            }
            $snapshot['variables'] = $variables;
            $result->input_snapshot = $snapshot;
            $result->save();
        }

        return new PromptHookExecutionResult(
            hook: $definition->key,
            output: $output,
            promptResultId: $result->id !== null ? (int) $result->id : null,
        );
    }

    private function attachPromptResultAfterExecution(
        \Omnichannel\Addons\Content\Models\SeoArticle $article,
        PromptHookDefinition $definition,
        SeoPrompt $prompt,
        PromptResult $result,
    ): void {
        $resultId = (int) $result->getKey();
        $articleId = (int) $article->getKey();
        if ($resultId <= 0 || $articleId <= 0) {
            return;
        }

        $stepTitle = trim((string) ($prompt->name ?? ''));
        if ($stepTitle === '') {
            $stepTitle = 'Prompt Hook: '.$definition->key;
        }

        $this->promptResultAttach->attach(
            promptResultId: $resultId,
            targetType: PromptResultAttachService::TARGET_ARTICLE,
            targetId: $articleId,
            siteId: (int) ($article->site_id ?? 0),
            purpose: 'prompt_hook',
            meta: [
                'hook_key' => $definition->key,
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'status' => (string) ($result->status ?? ''),
                'workflow_step_title' => $stepTitle,
            ],
        );
    }

    /**
     * Assemble final prompt text without calling the AI provider.
     * Used by Mode 2 image stages (parent/child) so Gemini receives Hook-compiled text + inlineData.
     *
     * @param  array<string, mixed>  $runtimeInput
     * @return array{
     *     final_prompt: string,
     *     variables: array<string, mixed>,
     *     prompt_id: int,
     *     hook_key: string
     * }
     */
    public function compilePromptOnly(
        string $hookKey,
        int $articleId,
        array $runtimeInput = [],
        ?SeoPrompt $prompt = null,
    ): array {
        $definition = $this->registry->get($hookKey);
        $article = $this->articleResolver->loadAuthorized($articleId);
        $entityContext = $this->articleResolver->buildContext($article);

        $prompt ??= $this->resolveConfiguredPrompt($definition);
        $this->assertPromptMatchesHook($prompt, $definition);
        $this->assertPromptModelSupported($prompt, $definition);

        $resolvedInput = $this->inputResolver->resolve($definition, $runtimeInput, $entityContext);
        $exposedInput = $this->inputResolver->exposeToPrompt($definition, $resolvedInput);
        $resolvedSettings = $this->settingsResolver->resolve(
            $definition,
            is_array($prompt->hook_settings) ? $prompt->hook_settings : null,
        );

        $assembled = $this->promptAssembler->assemble(
            $definition,
            $prompt,
            $exposedInput,
            $resolvedSettings,
        );

        $final = trim((string) ($assembled['final_prompt'] ?? ''));
        if ($final === '') {
            throw new PromptHookException(
                PromptHookErrorCode::HookExecutionFailed,
                'prompt_variable_missing: compiled prompt empty for ['.$definition->key.']',
            );
        }

        return [
            'final_prompt' => $final,
            'variables' => is_array($assembled['variables'] ?? null) ? $assembled['variables'] : [],
            'prompt_id' => (int) $prompt->id,
            'hook_key' => $definition->key,
        ];
    }

    /**
     * Resolve + validate input without calling AI (tests / dry-run).
     *
     * @param  array<string, mixed>  $runtimeInput
     * @return array{definition: PromptHookDefinition, resolved_input: array<string, mixed>, exposed_input: array<string, mixed>, entity_context: array<string, mixed>}
     */
    public function resolveOnly(
        string $hookKey,
        int $articleId,
        array $runtimeInput = [],
    ): array {
        $definition = $this->registry->get($hookKey);
        $article = $this->articleResolver->loadAuthorized($articleId);
        $entityContext = $this->articleResolver->buildContext($article);
        $resolvedInput = $this->inputResolver->resolve($definition, $runtimeInput, $entityContext);
        $exposedInput = $this->inputResolver->exposeToPrompt($definition, $resolvedInput);

        return [
            'definition' => $definition,
            'resolved_input' => $resolvedInput,
            'exposed_input' => $exposedInput,
            'entity_context' => $entityContext,
            'article' => $article,
        ];
    }

    public function resolveConfiguredPrompt(PromptHookDefinition $definition): SeoPrompt
    {
        return app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver::class)
            ->resolveSettingsHook($definition->key);
    }

    private function assertPromptMatchesHook(SeoPrompt $prompt, PromptHookDefinition $definition): void
    {
        $hookKey = trim((string) ($prompt->hook_key ?? ''));
        if ($hookKey === '' || $hookKey !== $definition->key) {
            throw new PromptHookException(
                PromptHookErrorCode::HookPromptMismatch,
                "Prompt #{$prompt->id} hook_key does not match [{$definition->key}].",
            );
        }
    }

    private function assertPromptModelSupported(SeoPrompt $prompt, PromptHookDefinition $definition): void
    {
        if ($definition->capability() !== 'text') {
            return;
        }

        if (ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline()) {
            throw new PromptHookException(
                PromptHookErrorCode::HookModelUnsupported,
                "Hook [{$definition->key}] requires a text capability prompt.",
            );
        }
    }
}
