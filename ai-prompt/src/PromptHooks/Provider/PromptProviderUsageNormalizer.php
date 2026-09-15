<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Provider;

use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason;
use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReasonNormalizer;

/**
 * Normalize provider usage/token fields — never invent tokens when provider silent.
 */
final class PromptProviderUsageNormalizer
{
    public function __construct(
        private readonly PromptCostEstimator $costEstimator = new ConfigPromptCostEstimator,
        private readonly AiProviderTerminalReasonNormalizer $terminalNormalizer = new AiProviderTerminalReasonNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $meta
     */
    public function normalize(
        string $text,
        array $usage,
        string $provider,
        string $model,
        int $attempts = 1,
        array $meta = [],
        bool $refused = false,
        bool $truncated = false,
    ): PromptProviderResponse {
        $inputTokens = $this->intOrNull($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? $usage['tokens_in'] ?? null);
        $outputTokens = $this->intOrNull($usage['completion_tokens'] ?? $usage['output_tokens'] ?? $usage['tokens_out'] ?? null);
        $totalTokens = $this->intOrNull($usage['total_tokens'] ?? null);
        if ($totalTokens === null && ($inputTokens !== null || $outputTokens !== null)) {
            $totalTokens = (int) $inputTokens + (int) $outputTokens;
        }

        $usageSource = ($inputTokens !== null || $outputTokens !== null) ? 'provider' : 'unknown';

        $estimated = $this->costEstimator->estimate([
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);
        if ($estimated !== null && $usageSource === 'unknown') {
            $usageSource = 'estimated';
        }

        $finishReason = $this->terminalNormalizer->extractFinishReason($usage);
        $truncated = \Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator::isProviderLengthTruncation(
            $finishReason,
            $truncated,
        );

        $terminalReason = $this->terminalNormalizer->normalizeFromUsage(
            $usage,
            truncatedFlag: $truncated,
        );
        if ($terminalReason === null && $finishReason !== null && $this->terminalNormalizer->isCompletedStopReason($finishReason)) {
            $terminalReason = AiProviderTerminalReason::Completed;
        }
        if ($truncated) {
            $terminalReason = AiProviderTerminalReason::OutputTruncated;
        }

        if ($terminalReason !== null) {
            $meta['provider_terminal_reason'] = $terminalReason->value;
        }
        if ($finishReason !== null) {
            $meta['provider_finish_reason'] = $finishReason;
        }
        if (isset($usage['response_status'])) {
            $meta['provider_response_status'] = (string) $usage['response_status'];
        }
        if (isset($usage['incomplete_details']) && is_array($usage['incomplete_details'])) {
            $meta['incomplete_details'] = $usage['incomplete_details'];
        }

        return new PromptProviderResponse(
            text: $text,
            refused: $refused,
            truncated: $truncated,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cachedTokens: $this->intOrNull($usage['cached_tokens'] ?? null),
            estimatedCost: $estimated,
            usageSource: $usageSource,
            provider: $provider !== '' ? $provider : null,
            model: $model !== '' ? $model : null,
            finishReason: $finishReason,
            providerRequestId: isset($usage['request_id'])
                ? (string) $usage['request_id']
                : (isset($usage['provider_request_id']) ? (string) $usage['provider_request_id'] : null),
            attempts: max(1, $attempts),
            meta: $meta,
            terminalReason: $terminalReason?->value,
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
