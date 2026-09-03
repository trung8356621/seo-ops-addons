<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;

/**
 * Keyword Discovery adaptive multi-idea batches — size from model budget, not fixed 10/20.
 */
final class KeywordDiscoveryBudgetStrategy implements PromptSplitStrategy
{
    public const HOOK = 'keyword.discovery.structured';

    public const MIN_BATCH = 3;

    public const MAX_BATCH = 25;

    public const TOKENS_PER_IDEA = 180;

    public function hookKey(): string
    {
        return self::HOOK;
    }

    public function splitClass(): PromptSplitClass
    {
        return PromptSplitClass::SemanticSplit;
    }

    public function supportsSplit(): bool
    {
        return true;
    }

    public function estimateOutputReserve(array $options, ModelContextCapability $capability): int
    {
        $batch = max(1, (int) ($options['batch_target'] ?? $options['quantity'] ?? $options['count'] ?? 10));
        $perIdea = (int) ($options['tokens_per_idea'] ?? self::TOKENS_PER_IDEA);
        if ($capability->isReasoningModel) {
            $perIdea = (int) ceil($perIdea * 1.6);
        }
        $jsonOverhead = 120 + ($batch * 24);

        // Desired only — do not mask insufficient modelMax with min().
        return ($batch * $perIdea) + $jsonOverhead;
    }

    /**
     * Adaptive idea count that fits remaining input budget.
     */
    public function resolveBatchTarget(
        int $remaining,
        int $immutableInputTokens,
        int $continuationTokens,
        ModelContextCapability $capability,
    ): int {
        $remaining = max(0, $remaining);
        if ($remaining === 0) {
            return 0;
        }

        $safe = $capability->safeContextBudget();
        $overhead = $capability->providerMessageOverheadTokens;
        $availableForIo = max(0, $safe - $immutableInputTokens - $continuationTokens - $overhead);

        $perIdea = self::TOKENS_PER_IDEA;
        if ($capability->isReasoningModel) {
            $perIdea = (int) ceil($perIdea * 1.6);
        }

        // Reserve output + keep some input headroom for schema/wrapper already in immutable.
        $maxByBudget = (int) floor($availableForIo / max(1, $perIdea + 40));
        $maxByOutput = (int) floor(($capability->maxOutputTokens - 120) / max(1, $perIdea));

        $target = min($remaining, self::MAX_BATCH, max(self::MIN_BATCH, min($maxByBudget, $maxByOutput)));
        if ($target < 1 && $remaining > 0 && $availableForIo > ($perIdea + 80)) {
            $target = 1;
        }

        return max(0, min($remaining, $target));
    }

    public function maxChunks(): int
    {
        return 40;
    }

    public function maxReplans(): int
    {
        return 2;
    }
}
