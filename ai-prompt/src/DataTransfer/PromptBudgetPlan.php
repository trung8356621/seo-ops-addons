<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

/**
 * Budget decision with explicit output-capability fields (no silent min() masking).
 */
final readonly class PromptBudgetPlan
{
    public const CONFIDENCE_EXACT = 'exact';

    public const CONFIDENCE_HEURISTIC_TRUSTED = 'heuristic_trusted';

    public const CONFIDENCE_HEURISTIC_DEFAULT = 'heuristic_default';

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public bool $requestFits,
        public int $estimatedInputTokens,
        public int $desiredOutputTokens,
        public int $minimumRequiredOutputTokens,
        public int $requestedMaxOutputTokens,
        public int $modelMaxOutputTokens,
        public int $safeContextBudget,
        public bool $outputCapabilitySufficient,
        public string $splitClass,
        public ?string $splitStrategy = null,
        public string $planId = '',
        public string $estimatorConfidence = self::CONFIDENCE_HEURISTIC_DEFAULT,
        public string $capabilityConfidence = self::CONFIDENCE_HEURISTIC_DEFAULT,
        public array $diagnostics = [],
    ) {}

    public function reservedOutputTokens(): int
    {
        return $this->requestedMaxOutputTokens;
    }

    public function availableOutputBudget(): int
    {
        return $this->requestedMaxOutputTokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDiagnostics(): array
    {
        return array_merge([
            'plan_id' => $this->planId,
            'request_fits' => $this->requestFits,
            'estimated_input_tokens' => $this->estimatedInputTokens,
            'desired_output_tokens' => $this->desiredOutputTokens,
            'minimum_required_output_tokens' => $this->minimumRequiredOutputTokens,
            'requested_max_output_tokens' => $this->requestedMaxOutputTokens,
            'model_max_output_tokens' => $this->modelMaxOutputTokens,
            'reserved_output_tokens' => $this->requestedMaxOutputTokens,
            'available_output_budget' => $this->requestedMaxOutputTokens,
            'safe_context_budget' => $this->safeContextBudget,
            'output_capability_sufficient' => $this->outputCapabilitySufficient,
            'total_needed' => $this->estimatedInputTokens + $this->requestedMaxOutputTokens,
            'split_class' => $this->splitClass,
            'split_strategy' => $this->splitStrategy,
            'estimator_confidence' => $this->estimatorConfidence,
            'capability_confidence' => $this->capabilityConfidence,
        ], $this->diagnostics);
    }
}
