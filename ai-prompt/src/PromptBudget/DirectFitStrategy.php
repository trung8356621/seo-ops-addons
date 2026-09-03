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
        unset($capability);
        $requested = (int) ($options['requested_output_tokens'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }

        // Desired reserve only — capability check lives in PromptBudgetPreflightService.
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
