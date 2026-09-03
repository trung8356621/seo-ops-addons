<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException;

/**
 * Merge typed long-form section outputs by logical order — never raw concat of provider dumps blindly.
 */
final class LongFormArticleMerger
{
    /**
     * @param  list<array{chunk: SemanticContentChunk, output: string}>  $parts
     */
    public function merge(array $parts): string
    {
        if ($parts === []) {
            throw PromptBudgetException::unsplittable('Long-form merge received no section outputs.');
        }

        usort(
            $parts,
            static fn (array $a, array $b): int => $a['chunk']->order <=> $b['chunk']->order,
        );

        $seenHeadings = [];
        $introCount = 0;
        $conclusionCount = 0;
        $bodies = [];

        foreach ($parts as $part) {
            $chunk = $part['chunk'];
            $output = trim($this->stripImmutableEcho((string) $part['output']));
            if ($output === '') {
                continue;
            }

            if ($chunk->kind === 'introduction') {
                $introCount++;
                if ($introCount > 1) {
                    continue;
                }
            }
            if ($chunk->kind === 'conclusion') {
                $conclusionCount++;
                if ($conclusionCount > 1) {
                    continue;
                }
            }

            $heading = (string) ($chunk->meta['heading'] ?? '');
            if ($heading !== '') {
                $key = mb_strtolower($heading);
                if (isset($seenHeadings[$key])) {
                    // Drop duplicate heading block.
                    continue;
                }
                $seenHeadings[$key] = true;
            }

            $bodies[] = $output;
        }

        $merged = trim(implode("\n\n", $bodies));
        if ($merged === '') {
            throw PromptBudgetException::unsplittable('Long-form merge produced empty article.');
        }

        if (! $this->structureLooksValid($merged)) {
            throw PromptBudgetException::unsplittable('Long-form merge failed structure validation.');
        }

        return $merged;
    }

    private function stripImmutableEcho(string $output): string
    {
        $output = preg_replace('/^ARTICLE CONTEXT \(immutable\):.*?(?=\n#|\n\n[A-Z])/us', '', $output) ?? $output;

        return trim($output);
    }

    private function structureLooksValid(string $merged): bool
    {
        // Reject obvious broken HTML tag halves.
        if (preg_match('/<[a-z]+\s*$/i', $merged) === 1) {
            return false;
        }

        return true;
    }
}
