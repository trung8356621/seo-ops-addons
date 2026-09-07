<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;

/**
 * Deterministic assemble — NEVER calls an LLM.
 */
final class SectionedFreeAssembleArticle
{
    /**
     * @param  list<array{section_order: int, output: string, status?: string}>  $sections
     */
    public function assemble(array $sections): string
    {
        $successful = array_values(array_filter(
            $sections,
            static fn (array $row): bool => ($row['status'] ?? 'completed') === 'completed'
                && trim((string) ($row['output'] ?? '')) !== '',
        ));

        if ($successful === []) {
            throw new PromptRunException(
                'Sectioned free assemble received no successful section outputs.',
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_ASSEMBLE_EMPTY',
                    'retryable' => false,
                ],
            );
        }

        usort(
            $successful,
            static fn (array $a, array $b): int => ((int) $a['section_order']) <=> ((int) $b['section_order']),
        );

        $bodies = [];
        foreach ($successful as $row) {
            $bodies[] = trim((string) $row['output']);
        }

        return trim(implode("\n\n", $bodies));
    }
}
