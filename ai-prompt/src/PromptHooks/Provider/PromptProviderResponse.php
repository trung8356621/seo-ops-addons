<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Provider;

/**
 * Canonical provider response — no SDK objects.
 */
final class PromptProviderResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $refused = false,
        public readonly bool $truncated = false,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?int $cachedTokens = null,
        public readonly ?float $estimatedCost = null,
        public readonly string $usageSource = 'unknown',
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $finishReason = null,
        public readonly ?string $providerRequestId = null,
        public readonly int $attempts = 1,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array{
     *   text: string,
     *   refused: bool,
     *   truncated: bool,
     *   tokens_in: ?int,
     *   tokens_out: ?int,
     *   total_tokens: ?int,
     *   cached_tokens: ?int,
     *   estimated_cost: ?float,
     *   usage_source: string,
     *   provider: ?string,
     *   model: ?string,
     *   finish_reason: ?string,
     *   provider_request_id: ?string,
     *   attempts: int
     * }
     */
    public function toPipelineArray(): array
    {
        return [
            'text' => $this->text,
            'refused' => $this->refused,
            'truncated' => $this->truncated,
            'tokens_in' => $this->inputTokens,
            'tokens_out' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cached_tokens' => $this->cachedTokens,
            'estimated_cost' => $this->estimatedCost,
            'usage_source' => $this->usageSource,
            'provider' => $this->provider,
            'model' => $this->model,
            'finish_reason' => $this->finishReason,
            'provider_request_id' => $this->providerRequestId,
            'attempts' => $this->attempts,
        ];
    }
}
