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
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function generate(ApiConnection $connection, string $prompt, string $model): array
    {
        $modelsToTry = GeminiModelCatalog::modelsToTry($model);

        $lastError = null;

        foreach ($modelsToTry as $candidateModel) {
            foreach (['v1beta', 'v1'] as $apiVersion) {
                try {
                    return $this->requestGenerateContent($connection, $prompt, $candidateModel, $apiVersion);
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
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function requestGenerateContent(
        ApiConnection $connection,
        string $prompt,
        string $model,
        string $apiVersion,
    ): array {
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

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if ($text === '') {
            $blockReason = $response->json('candidates.0.finishReason')
                ?? $response->json('promptFeedback.blockReason');

            throw new PromptRunException(
                'Gemini không trả về nội dung'
                .($blockReason ? ' ('.$blockReason.')' : '').'.',
            );
        }

        $usage = $response->json('usageMetadata');

        return [$text, is_array($usage) ? $usage : null];
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
