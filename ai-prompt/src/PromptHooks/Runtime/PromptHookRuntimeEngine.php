<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderFailed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\TemplateRenderFailed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Illuminate\Support\Str;

/**
 * Single-hook runtime — no domain write, no PromptResult domain attachment.
 * Retry ownership: PromptRunner/AiModelRouter (via production adapter).
 */
final class PromptHookRuntimeEngine
{
    public function __construct(
        private readonly PromptHookRuntimeRegistry $registry,
        private readonly PromptHookEnvelopeValidator $envelopeValidator,
        private readonly PromptHookRuntimeLocaleResolver $localeResolver,
        private readonly PromptHookRuntimeSettingsResolver $settingsResolver,
        private readonly PromptHookDeterministicTemplateRenderer $templateRenderer,
        private readonly PromptProviderCapabilityResolver $capabilityResolver,
        private readonly PromptProviderAdapter $provider,
        private readonly PromptHookRuntimeOutputPipeline $outputPipeline,
        private readonly PromptHookBudgetGuard $budgetGuard,
        private readonly PromptHookAuditRecorder $auditRecorder,
        private readonly PromptHookMigrationFlags $flags,
        private readonly PromptHookShadowParityRecorder $parityRecorder = new PromptHookShadowParityRecorder,
    ) {}

    public function execute(
        string $hookKey,
        string $version,
        PromptHookExecutionInput $envelope,
        ?string $correlationId = null,
    ): PromptHookRuntimeResult {
        $definition = $this->registry->get($hookKey, $version);
        $this->registry->assertExecutable(
            $definition,
            $this->flags->experimentalAllowed(),
            $this->flags->experimentalAllowlist(),
        );

        $validated = $this->envelopeValidator->validate($definition, $envelope);
        $locale = $this->localeResolver->resolve($definition->locale, $validated['context']);
        $settings = $this->settingsResolver->resolve(
            $definition,
            [],
            $validated['settings'],
        );

        $variables = array_merge(
            $validated['input'],
            $validated['previous_outputs'],
            $settings['hook'],
        );
        $articleId = isset($validated['context']['article_id']) ? (int) $validated['context']['article_id'] : 0;
        if ($articleId > 0 && ! isset($variables['article_id'])) {
            $variables['article_id'] = $articleId;
        }

        $metadata = [
            'prompt_id' => isset($validated['context']['prompt_id'])
                ? (int) $validated['context']['prompt_id']
                : (isset($validated['settings']['prompt_id']) ? (int) $validated['settings']['prompt_id'] : 0),
            'variables' => $variables,
        ];
        if (($definition->template['source'] ?? '') === 'legacy_prompt_content') {
            $legacy = trim((string) ($validated['context']['legacy_compiled_prompt'] ?? ''));
            if ($legacy === '') {
                throw new TemplateRenderFailed(
                    'template.source=legacy_prompt_content requires context.legacy_compiled_prompt.',
                );
            }
            $metadata['legacy_compiled_prompt'] = $legacy;
        }

        $request = $this->templateRenderer->render(
            $definition,
            $variables,
            $locale,
            $settings['model'],
            $metadata,
        );

        $siteId = isset($validated['context']['site_id']) ? (int) $validated['context']['site_id'] : null;
        $this->budgetGuard->assertWithinBudget($hookKey, $siteId, (int) ($settings['model']['max_tokens'] ?? 0));

        $strategy = $this->capabilityResolver->resolveStrategy($definition, $this->provider->capabilities());

        try {
            $providerResponse = $this->provider->generate($request, $strategy);
        } catch (PromptHookFailure $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProviderFailed('Provider call failed: '.$exception->getMessage(), $exception);
        }

        $pipelinePayload = $providerResponse->toPipelineArray();
        $this->budgetGuard->record(
            $hookKey,
            $siteId,
            (int) ($pipelinePayload['tokens_in'] ?? 0),
            (int) ($pipelinePayload['tokens_out'] ?? 0),
        );

        $correlationId ??= (string) ($validated['context']['correlation_id'] ?? Str::uuid()->toString());
        try {
            $output = $this->outputPipeline->process(
                $definition,
                $pipelinePayload,
                $correlationId,
                is_array($validated['input'] ?? null) ? $validated['input'] : [],
            );
        } catch (PromptHookFailure $failure) {
            // AI đã chạy (PromptResult tồn tại) — gắn id để workflow link /prompts dù validator fail.
            $promptResultId = (int) ($providerResponse->meta['prompt_result_id'] ?? 0);
            if ($promptResultId > 0) {
                $failure->bindPromptResultId($promptResultId);
                $this->persistFailedLengthValidation(
                    $promptResultId,
                    (string) ($pipelinePayload['text'] ?? ''),
                    is_array($validated['input'] ?? null) ? $validated['input'] : [],
                    $failure,
                );
            }
            throw $failure;
        }

        $fingerprint = $this->auditRecorder->record([
            'hook_key' => $definition->key->value,
            'hook_version' => $definition->version->toString(),
            'status' => $definition->status->value,
            'mode' => PromptHookRuntimeMode::Hook->value,
            'provider' => $providerResponse->provider ?? $definition->model->provider,
            'model' => $providerResponse->model ?? $definition->model->name,
            'correlation_id' => $correlationId,
            'token_usage' => [
                'in' => $providerResponse->inputTokens,
                'out' => $providerResponse->outputTokens,
                'total' => $providerResponse->totalTokens,
                'source' => $providerResponse->usageSource,
                'estimated_cost' => $providerResponse->estimatedCost,
            ],
            'request_fingerprint' => $request->fingerprint(),
            'validation_status' => 'ok',
            'output_contracts' => $request->metadata['output_contracts'] ?? [],
            'api_key' => 'should-redact',
            'retry_owner' => $providerResponse->meta['retry_owner'] ?? 'PromptRunner/AiModelRouter',
        ]);

        return new PromptHookRuntimeResult(
            hookKey: $definition->key->value,
            hookVersion: $definition->version->toString(),
            mode: PromptHookRuntimeMode::Hook->value,
            output: $output,
            correlationId: $correlationId,
            auditFingerprint: $fingerprint,
            meta: [
                'strategy' => $strategy->value,
                'locale_code' => $locale['locale_code'],
                'prompt_result_id' => $providerResponse->meta['prompt_result_id'] ?? null,
                'usage_source' => $providerResponse->usageSource,
                'output_contracts' => $request->metadata['output_contracts'] ?? [],
            ],
        );
    }

    /**
     * @param  callable(): mixed  $legacyWrite
     * @param  callable(mixed): array{type: string, raw: string, value: mixed, warnings?: list<string>}|null  $mapLegacyOutput
     */
    public function shadowWithoutProvider(
        string $hookKey,
        string $version,
        PromptHookExecutionInput $envelope,
        callable $legacyWrite,
        ?callable $mapLegacyOutput = null,
        ?string $correlationId = null,
    ): mixed {
        $definition = $this->registry->get($hookKey, $version);
        $this->registry->assertExecutable(
            $definition,
            $this->flags->experimentalAllowed(),
            $this->flags->experimentalAllowlist(),
        );

        $validated = $this->envelopeValidator->validate($definition, $envelope);
        $locale = $this->localeResolver->resolve($definition->locale, $validated['context']);
        $settings = $this->settingsResolver->resolve($definition, [], $validated['settings']);
        $variables = array_merge($validated['input'], $validated['previous_outputs'], $settings['hook']);
        $shadowMeta = [
            'prompt_id' => (int) ($validated['context']['prompt_id'] ?? 0),
        ];
        if (($definition->template['source'] ?? '') === 'legacy_prompt_content') {
            $legacy = trim((string) ($validated['context']['legacy_compiled_prompt'] ?? ''));
            $shadowMeta['legacy_compiled_prompt'] = $legacy !== ''
                ? $legacy
                : '[legacy_prompt_content:shadow_stub]';
        }
        $request = $this->templateRenderer->render($definition, $variables, $locale, $settings['model'], $shadowMeta);

        $legacyResult = $legacyWrite();
        $schemaOk = true;
        $failure = null;
        $markerLeak = false;
        $rawForMarkers = '';

        if ($mapLegacyOutput !== null) {
            try {
                $mapped = $mapLegacyOutput($legacyResult);
                $rawForMarkers = is_string($mapped['raw'] ?? null)
                    ? (string) $mapped['raw']
                    : (is_string($mapped['value'] ?? null) ? (string) $mapped['value'] : (string) json_encode($mapped['value'] ?? ''));
                $markerLeak = str_contains($rawForMarkers, '{{') || str_contains($rawForMarkers, '[[PREV');
                $this->outputPipeline->process($definition, [
                    'text' => $rawForMarkers,
                ]);
            } catch (\Throwable $exception) {
                $schemaOk = false;
                $failure = $exception->getMessage();
                if (str_contains(strtolower($failure), 'marker')) {
                    $markerLeak = true;
                }
            }
        }

        $localeCode = (string) ($locale['locale_code'] ?? '');
        $localeFailure = $localeCode === '';
        $providerMappingFailure = trim((string) ($definition->model->provider ?? '')) === ''
            || trim((string) ($definition->model->name ?? '')) === '';

        $this->parityRecorder->record([
            'hook_key' => $hookKey,
            'hook_version' => $version,
            'mode' => PromptHookRuntimeMode::Shadow->value,
            'request_fingerprint' => $request->fingerprint(),
            'locale' => $localeCode,
            'schema_ok' => $schemaOk && ! $markerLeak,
            'marker_leak' => $markerLeak,
            'locale_failure' => $localeFailure,
            'provider_mapping_failure' => $providerMappingFailure,
            'exception' => false,
            'cost_anomaly' => false,
            'duplicate_ai_call' => false,
            'domain_side_effect_mismatch' => false,
            'prompt_result_linkage_mismatch' => false,
            'failure' => $failure,
            'correlation_id' => $correlationId,
            'provider' => $definition->model->provider,
            'model' => $definition->model->name,
        ]);

        $this->auditRecorder->record([
            'hook_key' => $hookKey,
            'hook_version' => $version,
            'mode' => PromptHookRuntimeMode::Shadow->value,
            'validation_status' => ($schemaOk && ! $markerLeak) ? 'shadow_no_provider' : 'parity_output_invalid',
            'correlation_id' => $correlationId,
            'request_fingerprint' => $request->fingerprint(),
        ]);

        return $legacyResult;
    }

    public function definition(string $hookKey, string $version): PromptHookDefinition
    {
        return $this->registry->get($hookKey, $version);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persistFailedLengthValidation(
        int $promptResultId,
        string $text,
        array $input,
        PromptHookFailure $failure,
    ): void {
        if (! $failure instanceof OutputTruncated) {
            return;
        }
        if (! array_key_exists('article_length', $input)
            || $input['article_length'] === null
            || $input['article_length'] === '') {
            return;
        }

        $raw = $input['article_length'];
        $target = is_numeric($raw) ? (int) $raw : 0;
        if (is_string($raw) && $target <= 0 && preg_match('/(\d+)/', $raw, $matches) === 1) {
            $target = (int) $matches[1];
        }
        if ($target <= 0) {
            return;
        }

        $meta = (new ArticleGenerationLengthValidator)->evaluate($text, $target);
        $meta['length_validation_result'] = 'truncated';

        $result = PromptResult::query()->find($promptResultId);
        if ($result === null) {
            return;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
        foreach ($meta as $key => $value) {
            $variables[$key] = $value;
            $snapshot[$key] = $value;
        }
        $snapshot['variables'] = $variables;
        $result->input_snapshot = $snapshot;
        $result->save();
    }
}
