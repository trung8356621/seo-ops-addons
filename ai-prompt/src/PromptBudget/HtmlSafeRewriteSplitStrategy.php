<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;

/**
 * Rewrite / improve / translate — HTML-safe semantic blocks (supportsSplit=true only with real merger).
 */
final class HtmlSafeRewriteSplitStrategy implements PromptSplitStrategy
{
    public function __construct(
        private readonly string $hook,
        private readonly HtmlSafeContentSplitter $splitter = new HtmlSafeContentSplitter(),
        private readonly HtmlSafeContentMerger $merger = new HtmlSafeContentMerger(),
    ) {}

    public function hookKey(): string
    {
        return $this->hook;
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
        unset($capability);
        $blocks = max(1, (int) ($options['block_count'] ?? 1));
        $perBlock = (int) ($options['tokens_per_block'] ?? 700);

        // Desired only — never mask with modelMax here.
        return ($blocks * $perBlock) + 120;
    }

    /**
     * @param  array{source?: string, instructions?: string, glossary?: string, language?: string}  $structured
     * @return list<SemanticContentChunk>
     */
    public function buildChunks(array $structured): array
    {
        return $this->splitter->split($structured);
    }

    /**
     * @param  list<array{chunk: SemanticContentChunk, output: string}>  $parts
     */
    public function mergeResults(array $parts): string
    {
        return $this->merger->merge($parts);
    }

    public function maxChunks(): int
    {
        return 60;
    }

    public function maxReplans(): int
    {
        return 2;
    }
}
