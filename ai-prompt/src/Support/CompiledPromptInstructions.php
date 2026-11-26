<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Structured compile result for one SeoPrompt (Prompt Parts only).
 *
 * Hook locale templates / output contracts are applied by PromptHookPromptAssembler
 * on top of {@see toFlatString()} or by appending to {@see $userBlocks} — never here.
 */
final class CompiledPromptInstructions
{
    /**
     * @param  list<string>  $systemBlocks  Formatted ## blocks (global_constraints, role, …)
     * @param  list<string>  $userBlocks    Formatted ## blocks (task / sub_task)
     * @param  list<string>  $orderedBlocks All non-empty blocks in markdown part order (lossless flat)
     */
    public function __construct(
        public readonly array $systemBlocks,
        public readonly array $userBlocks,
        public readonly array $orderedBlocks,
    ) {}

    public function systemText(): string
    {
        return trim(implode("\n\n", $this->systemBlocks));
    }

    public function userText(): string
    {
        return trim(implode("\n\n", $this->userBlocks));
    }

    /**
     * Backward-compatible single string for adapters that still take one blob.
     * Preserves original part order; does not drop global_constraints.
     */
    public function toFlatString(): string
    {
        return trim(implode("\n\n", $this->orderedBlocks));
    }

    /**
     * Chain step payload: non-task blocks + --- + active step (Claude task-mode compatible).
     */
    public function toChainFlatString(string $stepBlock): string
    {
        $step = trim($stepBlock);
        if ($step === '') {
            return $this->systemText();
        }
        if ($this->systemBlocks === []) {
            return $step;
        }

        return trim(implode("\n\n", $this->systemBlocks)."\n\n---\n\n".$step);
    }

    public function isEmpty(): bool
    {
        return $this->orderedBlocks === [];
    }
}
