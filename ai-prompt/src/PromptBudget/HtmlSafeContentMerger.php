<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException;

final class HtmlSafeContentMerger
{
    /**
     * @param  list<array{chunk: SemanticContentChunk, output: string}>  $parts
     */
    public function merge(array $parts): string
    {
        if ($parts === []) {
            throw PromptBudgetException::unsplittable('HTML-safe merge received no blocks.');
        }

        usort(
            $parts,
            static fn (array $a, array $b): int => $a['chunk']->order <=> $b['chunk']->order,
        );

        $bodies = [];
        foreach ($parts as $part) {
            $output = trim($this->stripContract((string) $part['output']));
            if ($output === '') {
                continue;
            }
            if ($this->looksBroken($output)) {
                throw PromptBudgetException::unsplittable(
                    'HTML-safe merge blocked: broken tags in chunk '.$part['chunk']->logicalId,
                );
            }
            $bodies[] = $output;
        }

        $merged = trim(implode("\n\n", $bodies));
        if ($merged === '') {
            throw PromptBudgetException::unsplittable('HTML-safe merge produced empty content.');
        }

        return $merged;
    }

    private function stripContract(string $output): string
    {
        $output = preg_replace('/^REWRITE\/TRANSLATE CONTRACT \(immutable\):.*?(?=\n<|\n#|\n\n)/us', '', $output) ?? $output;
        $output = preg_replace('/^SOURCE BLOCK:\s*/u', '', $output) ?? $output;

        return trim($output);
    }

    private function looksBroken(string $html): bool
    {
        if (preg_match('/<[a-z]+[^>]*$/i', $html) === 1) {
            return true;
        }
        // Rough open/close balance for common tags.
        foreach (['p', 'div', 'ul', 'ol', 'table', 'blockquote'] as $tag) {
            $open = preg_match_all('/<'.$tag.'\b/i', $html) ?: 0;
            $close = preg_match_all('/<\/'.$tag.'>/i', $html) ?: 0;
            if ($open !== $close) {
                return true;
            }
        }

        return false;
    }
}
