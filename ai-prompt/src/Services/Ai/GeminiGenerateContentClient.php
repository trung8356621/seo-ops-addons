<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Ai;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Seo\Support\GeminiModelCatalog;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver;

/**
 * Real Gemini `generateContent` HTTP client. Extracted from PromptRunnerService so it can
 * be shared, without a circular dependency, by both:
 * - PromptRunnerService::callGemini (internal task-execution flow), and
 * - GeminiAiTextProvider::generate (extension boundary used by AiProviderResolver).
 */
final class GeminiGenerateContentClient
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function generate(ApiConnection $connection, string $prompt, string $model, array $options = []): array
    {
        $requested = trim($model);
        if ($requested === '') {
            throw new PromptRunException(
                'Thiếu model Gemini từ routing. Đồng bộ catalog từ provider rồi chọn model trong AI Center.',
            );
        }

        // Alias resolve only for the requested model — do not cascade static "current" fallbacks.
        $primary = GeminiModelCatalog::resolve($requested);
        $modelsToTry = [$primary];
        if ($primary !== $requested) {
            $modelsToTry[] = $requested;
        }
        $modelsToTry = array_values(array_unique($modelsToTry));

        $lastError = null;

        foreach ($modelsToTry as $candidateModel) {
            foreach (['v1beta', 'v1'] as $apiVersion) {
                try {
                    return $this->requestGenerateContent($connection, $prompt, $candidateModel, $apiVersion, $options);
                } catch (PromptRunException $exception) {
                    $lastError = $exception;
                    if (! $this->isModelNotFoundError($exception->getMessage())
                        && ! $this->isRetryableError($exception->getMessage())) {
                        throw $exception;
                    }
                }
            }
        }

        throw $lastError ?? new PromptRunException('Không gọi được Gemini API.');
    }

    private function isModelNotFoundError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'not found')
            || str_contains($lower, 'not supported for generatecontent')
            || str_contains($lower, '404');
    }

    private function isRetryableError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'high demand')
            || str_contains($lower, 'overloaded')
            || str_contains($lower, 'resource exhausted')
            || str_contains($lower, '429')
            || str_contains($lower, '503');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function requestGenerateContent(
        ApiConnection $connection,
        string $prompt,
        string $model,
        string $apiVersion,
        array $options = [],
    ): array {
        $omitCeiling = \Omnichannel\Addons\AiPrompt\Support\ArticleOutboundCeilingPolicy::shouldOmitApplicationCeiling($options);

        if (! $omitCeiling && function_exists('app') && app()->bound(\Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService::class)) {
            $gate = new \Omnichannel\Addons\AiPrompt\Services\AiOutboundBudgetGate(
                app(\Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService::class),
            );
            $gate->verifyCompiled(
                null,
                $connection,
                $prompt,
                $model,
                'gemini',
                is_string($options['hook_key'] ?? null) ? (string) $options['hook_key'] : null,
                $options,
            );
        }

        $url = sprintf(
            '%s/%s/models/%s:generateContent',
            $this->httpBaseUrl($connection),
            $apiVersion,
            rawurlencode($model),
        );

        $response = Http::timeout(180)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key])
            ->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? $response->json('error.status')
                ?? $response->body();

            throw new PromptRunException(
                'Gemini API lỗi ('.$model.', '.$apiVersion.'): '.$this->truncateError((string) $message),
            );
        }

        $json = $response->json();
        $text = collect(data_get($json, 'candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode("\n");

        $finishReason = trim((string) data_get($json, 'candidates.0.finishReason', ''));

        if ($text === '') {
            $blockReason = $finishReason !== ''
                ? $finishReason
                : data_get($json, 'promptFeedback.blockReason');

            throw new PromptRunException(
                'Gemini không trả về nội dung'
                .($blockReason ? ' ('.$blockReason.')' : '').'.',
            );
        }

        $usage = data_get($json, 'usageMetadata');
        $usageBag = is_array($usage) ? $usage : [];
        if ($finishReason !== '') {
            // Canonical key for PromptProviderUsageNormalizer + length truncation detection.
            $usageBag['finish_reason'] = $finishReason;
            $usageBag['finishReason'] = $finishReason;
        }

        return [$text, $usageBag !== [] ? $usageBag : null];
    }

    private function httpBaseUrl(ApiConnection $connection): string
    {
        return app(ProviderConnectionResolver::class)->httpBaseUrl($connection);
    }

    private function truncateError(string $message): string
    {
        return mb_strlen($message) > 500 ? mb_substr($message, 0, 500).'…' : $message;
    }
}
