<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use App\Models\ApiConnection;

/**
 * Central model context/output capability resolver for budget planning.
 */
final class ModelContextCapabilityResolver
{
    public const DEFAULT_CONTEXT_WINDOW = 8192;

    public const DEFAULT_MAX_OUTPUT = 2048;

    public const CONSERVATIVE_CONTEXT_WINDOW = 4096;

    public function __construct(
        private readonly ModelCapabilityRegistry $capabilities = new ModelCapabilityRegistry(),
    ) {}

    public function resolve(RoutedAiCandidate $candidate): ModelContextCapability
    {
        return $this->resolveFor(
            $candidate->connection,
            $candidate->model,
            $candidate->seoAiModelId,
            $candidate->capabilities,
        );
    }

    /**
     * @param  list<string>  $capabilityKeys
     */
    public function resolveFor(
        ApiConnection $connection,
        string $model,
        ?int $seoAiModelId = null,
        array $capabilityKeys = [],
    ): ModelContextCapability {
        $source = 'default';
        $capabilityConfidence = ModelContextCapability::CONFIDENCE_DEFAULT;
        $context = self::CONSERVATIVE_CONTEXT_WINDOW;
        $maxOut = self::DEFAULT_MAX_OUTPUT;

        $row = null;
        if ($seoAiModelId !== null && $seoAiModelId > 0) {
            $row = SeoAiModel::query()->find($seoAiModelId);
        }
        if (! $row instanceof SeoAiModel) {
            $row = SeoAiModel::query()
                ->where('api_connection_id', (int) $connection->id)
                ->where('raw_model_name', $model)
                ->first();
        }

        if ($row instanceof SeoAiModel) {
            $caps = is_array($row->capabilities) ? $row->capabilities : [];
            $meta = is_array($caps['provider_metadata'] ?? null) ? $caps['provider_metadata'] : [];
            $fromMeta = (int) ($meta['context_length'] ?? $meta['context_window'] ?? 0);
            if ($fromMeta > 0) {
                $context = $fromMeta;
                $source = 'provider_metadata';
                $capabilityConfidence = ModelContextCapability::CONFIDENCE_TRUSTED;
            }
            $arch = is_array($meta['architecture'] ?? null) ? $meta['architecture'] : [];
            $fromArch = (int) ($arch['context_length'] ?? 0);
            if ($fromArch > $context) {
                $context = $fromArch;
                $source = 'provider_metadata.architecture';
                $capabilityConfidence = ModelContextCapability::CONFIDENCE_TRUSTED;
            }
            $configuredOut = (int) ($caps['max_output_tokens'] ?? $meta['max_completion_tokens'] ?? 0);
            if ($configuredOut > 0) {
                $maxOut = $configuredOut;
            }
        }

        if ($source === 'default') {
            $guess = $this->heuristicContext($model);
            if ($guess !== null) {
                $context = $guess;
                $source = 'heuristic_model_id';
                $capabilityConfidence = ModelContextCapability::CONFIDENCE_DEFAULT;
            }
        }

        if ($capabilityKeys === []) {
            $capabilityKeys = $this->capabilities->capabilitiesFor($connection, $model);
        }

        $structured = in_array(AiModelCapability::StructuredOutput->value, $capabilityKeys, true);
        $reasoning = in_array(AiModelCapability::TextReasoning->value, $capabilityKeys, true)
            || $this->looksReasoning($model);

        if ($reasoning && $maxOut > 1024) {
            $maxOut = min($maxOut, (int) floor($maxOut * 0.55));
        }

        $estimatorConfidence = ModelContextCapability::CONFIDENCE_DEFAULT; // char heuristic
        [$margin, $marginReason] = $this->marginFor($capabilityConfidence, $estimatorConfidence, $context, $reasoning);

        return new ModelContextCapability(
            contextWindow: max(1024, $context),
            maxOutputTokens: max(256, $maxOut),
            capabilitySource: $source,
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            supportsStructuredOutput: $structured || $capabilityKeys === [],
            supportsJsonSchema: $structured || $capabilityKeys === [],
            isReasoningModel: $reasoning,
            safetyMarginTokens: $margin,
            providerMessageOverheadTokens: 96,
            capabilityConfidence: $capabilityConfidence,
            estimatorConfidence: $estimatorConfidence,
            safetyMarginReason: $marginReason,
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function marginFor(
        string $capabilityConfidence,
        string $estimatorConfidence,
        int $context,
        bool $reasoning,
    ): array {
        // Tiers: exact+trusted < heuristic+trusted < heuristic+default
        $pct = match (true) {
            $estimatorConfidence === ModelContextCapability::CONFIDENCE_EXACT
                && $capabilityConfidence === ModelContextCapability::CONFIDENCE_TRUSTED => 0.04,
            $capabilityConfidence === ModelContextCapability::CONFIDENCE_TRUSTED => 0.08,
            default => 0.14,
        };
        if ($reasoning) {
            $pct += 0.04;
        }
        $margin = max(256, (int) floor($context * $pct));
        $reason = sprintf(
            'pct=%.2f capability=%s estimator=%s reasoning=%s',
            $pct,
            $capabilityConfidence,
            $estimatorConfidence,
            $reasoning ? '1' : '0',
        );

        return [$margin, $reason];
    }

    private function heuristicContext(string $model): ?int
    {
        $lower = strtolower($model);
        if (str_contains($lower, 'reasoner')) {
            return 64_000;
        }
        if (preg_match('/\b(128k|131072)\b/', $lower)) {
            return 128_000;
        }
        if (preg_match('/\b(64k|65536)\b/', $lower)) {
            return 64_000;
        }
        if (preg_match('/\b(32k|32768)\b/', $lower)) {
            return 32_000;
        }
        if (str_contains($lower, 'gemini') || str_contains($lower, 'claude') || str_contains($lower, 'gpt-4')) {
            return 32_000;
        }
        if (str_contains($lower, 'deepseek')) {
            return 64_000;
        }

        return null;
    }

    private function looksReasoning(string $model): bool
    {
        $lower = strtolower($model);

        return str_contains($lower, 'reason')
            || str_contains($lower, 'o1')
            || str_contains($lower, 'o3')
            || str_contains($lower, 'thinking');
    }
}
