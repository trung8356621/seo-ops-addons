<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;

/**
 * Per-task semantic split / budget strategy.
 * Splitting must operate on structured input — never compiled prompt strings.
 */
interface PromptSplitStrategy
{
    public function hookKey(): string;

    public function splitClass(): PromptSplitClass;

    public function supportsSplit(): bool;

    /**
     * Output token reservation for this task given structured options.
     *
     * @param  array<string, mixed>  $options
     */
    public function estimateOutputReserve(array $options, ModelContextCapability $capability): int;

    public function maxChunks(): int;

    public function maxReplans(): int;
}
