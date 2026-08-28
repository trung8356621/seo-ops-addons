<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

/**
 * Build compact prompt section from selected Audit Note items.
 * DNA weight DESC only — MCP share is planning priority for suggestions, not DNA ordering.
 */
final class AuditNotePromptSectionBuilder
{
    /**
     * @param  list<array<string, mixed>>  $noteItems
     * @return list<string>
     */
    public function lines(array $noteItems, int $maxDnaPerNote = AuditNoteDnaNormalizer::MAX_PROMPT_DNA_LINES): array
    {
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        if ($items === []) {
            return [];
        }

        $lines = ['Selected SEO Audit Notes (DNA weight DESC — semantic priority):'];
        foreach ($items as $item) {
            $isManual = AuditNoteDnaNormalizer::isManualRef((string) $item['cluster_ref']);
            if ($isManual) {
                $lines[] = '- '.$item['cluster_name_snapshot'].' · manual';
            } else {
                $share = number_format((float) $item['mcp_share_snapshot'], 1, '.', '');
                $lines[] = '- '.$item['cluster_name_snapshot'].' · MCP '.$share.'%';
            }
            foreach (AuditNoteDnaNormalizer::promptLines($item['dna'], $maxDnaPerNote) as $dnaLine) {
                $lines[] = '  · '.$dnaLine;
            }
        }

        return $lines;
    }

    /**
     * Compact notes string for {{notes}} prompt variable (hooks without brief DNA section).
     *
     * @param  list<array<string, mixed>>  $noteItems
     */
    public function compactNotesText(array $noteItems, string $extraNotes = ''): string
    {
        $parts = [];
        foreach ($this->lines($noteItems) as $line) {
            $parts[] = $line;
        }
        $extra = trim($extraNotes);
        if ($extra !== '') {
            if ($parts !== []) {
                $parts[] = 'Additional user instructions:';
            }
            $parts[] = $extra;
        }

        return implode("\n", $parts);
    }
}
