<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Provider;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderFailed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderRefused;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderTimeout;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\UnsupportedProviderCapability;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRenderedPromptCompiler;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\RenderedPromptRequest;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Thin production adapter — wraps PromptRunner (retry/failover owned by PromptRunner/AiModelRouter).
 * Credentials stay on SeoPrompt → ApiConnection; never logged.
 */
final class PromptRunnerProviderAdapter implements PromptProviderAdapter
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly PromptProviderUsageNormalizer $usageNormalizer = new PromptProviderUsageNormalizer,
        private readonly PromptHookRenderedPromptCompiler $renderedCompiler = new PromptHookRenderedPromptCompiler,
    ) {}

    public function capabilities(): PromptProviderCapabilities
    {
        return new PromptProviderCapabilities(
            textGeneration: true,
            jsonMode: true,
            nativeStructuredOutput: false,
            systemMessage: true,
            temperature: true,
            maxTokens: true,
        );
    }

    public function generate(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): PromptProviderResponse
    {
        if ($strategy === PromptStructuredStrategy::NativeSchema) {
            throw new UnsupportedProviderCapability(
                'Native structured output not available on current PromptRunner path; use json_mode or prompt_enforced_json.',
            );
        }

        $promptId = (int) ($request->metadata['prompt_id'] ?? 0);
        if ($promptId <= 0) {
            throw new ProviderFailed('RenderedPromptRequest.metadata.prompt_id is required for production adapter.');
        }

        $prompt = SeoPrompt::query()->with('aiConnection')->find($promptId);
        if (! $prompt instanceof SeoPrompt) {
            throw new ProviderFailed("SeoPrompt [{$promptId}] not found.");
        }

        $connection = $prompt->aiConnection;
        if ($connection === null || blank($connection->api_key)) {
            throw new ProviderFailed('AI connection missing or has no credential (resolved outside hook JSON).');
        }

        $compiled = $this->renderedCompiler->compile($request, $strategy);
        $variables = is_array($request->metadata['variables'] ?? null)
            ? $request->metadata['variables']
            : [];

        try {
            $result = $this->promptRunner->runWithCompiledPrompt(
                $prompt,
                $compiled,
                $variables,
            );
        } catch (PromptRunException $exception) {
            $message = $exception->getMessage();
            if ($this->looksLikeTimeout($message)) {
                throw new ProviderTimeout($message, $exception);
            }
            if ($this->looksLikeRefusal($message)) {
                throw new ProviderRefused($message);
            }
            throw new ProviderFailed($message, $exception);
        } catch (ConnectionException|RequestException $exception) {
            throw new ProviderTimeout($exception->getMessage(), $exception);
        } catch (\Throwable $exception) {
            throw new ProviderFailed('PromptRunner failed: '.$exception->getMessage(), $exception);
        }

        $text = trim((string) ($result->output_text ?? ''));
        $usage = is_array($result->token_usage ?? null) ? $result->token_usage : [];
        $provider = (string) ($connection->provider ?? 'unknown');
        $model = (string) ($result->model_used ?? $request->model->name);

        return $this->usageNormalizer->normalize(
            text: $text,
            usage: $usage,
            provider: $provider,
            model: $model,
            attempts: 1, // failover retries owned by AiModelRouter inside PromptRunner
            meta: [
                'prompt_result_id' => $result->id !== null ? (int) $result->id : null,
                'retry_owner' => 'PromptRunner/AiModelRouter',
            ],
        );
    }

    private function looksLikeTimeout(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'timeout') || str_contains($lower, 'timed out');
    }

    private function looksLikeRefusal(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'refus') || str_contains($lower, 'safety') || str_contains($lower, 'blocked');
    }

}
