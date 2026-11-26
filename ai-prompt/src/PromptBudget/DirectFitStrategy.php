<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;

final class DirectFitStrategy implements PromptSplitStrategy
{
    public function __construct(
        private readonly string $hook,
        private readonly PromptSplitClass $class = PromptSplitClass::DirectFit,
        private readonly int $defaultOutputReserve = 512,
        private readonly bool $capabilityAwareOutputReserve = false,
    ) {}

    public function hookKey(): string
    {
        return $this->hook;
    }

    public function splitClass(): PromptSplitClass
    {
        return $this->class;
    }

    public function supportsSplit(): bool
    {
        return false;
    }

    public function estimateOutputReserve(array $options, ModelContextCapability $capability): int
    {
        $requested = (int) ($options['requested_output_tokens'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }

        // Business-split Outline/Vocabulary, and opted-in DirectFit hooks (Meta):
        // capability-aware desired reserve. Cap by model max so minimum_required
        // (derived from desired) stays sendable. Preflight still applies
        // min(desired, modelMax) + context-fit as the outbound ceiling.
        if ($this->class === PromptSplitClass::BusinessSplit || $this->capabilityAwareOutputReserve) {
            $base = $capability->isReasoningModel ? 8192 : 4096;

            return max(256, min($base, max(256, $capability->maxOutputTokens)));
        }

        return $this->defaultOutputReserve;
    }

    public function maxChunks(): int
    {
        return 1;
    }

    public function maxReplans(): int
    {
        return 0;
    }
}
