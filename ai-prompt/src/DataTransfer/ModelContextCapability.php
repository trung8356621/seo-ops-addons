<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

/**
 * Resolved per-route model capacity for budget planning.
 */
final readonly class ModelContextCapability
{
    public const CONFIDENCE_EXACT = 'exact';

    public const CONFIDENCE_TRUSTED = 'trusted';

    public const CONFIDENCE_DEFAULT = 'default';

    public function __construct(
        public int $contextWindow,
        public int $maxOutputTokens,
        public string $capabilitySource,
        public string $estimatorFamily,
        public bool $supportsStructuredOutput = true,
        public bool $supportsJsonSchema = true,
        public bool $isReasoningModel = false,
        public int $safetyMarginTokens = 512,
        public int $providerMessageOverheadTokens = 64,
        public string $capabilityConfidence = self::CONFIDENCE_DEFAULT,
        public string $estimatorConfidence = self::CONFIDENCE_DEFAULT,
        public string $safetyMarginReason = 'default',
    ) {}

    public function safeContextBudget(): int
    {
        return max(256, $this->contextWindow - $this->safetyMarginTokens);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDiagnostics(): array
    {
        return [
            'context_window' => $this->contextWindow,
            'max_output_tokens' => $this->maxOutputTokens,
            'capability_source' => $this->capabilitySource,
            'capability_confidence' => $this->capabilityConfidence,
            'estimator' => $this->estimatorFamily,
            'estimator_confidence' => $this->estimatorConfidence,
            'safety_margin' => $this->safetyMarginTokens,
            'safety_margin_reason' => $this->safetyMarginReason,
            'provider_overhead' => $this->providerMessageOverheadTokens,
            'structured_output' => $this->supportsStructuredOutput,
            'json_schema' => $this->supportsJsonSchema,
            'reasoning_model' => $this->isReasoningModel,
            'safe_context_budget' => $this->safeContextBudget(),
        ];
    }
}
