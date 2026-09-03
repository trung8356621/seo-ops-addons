<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

/**
 * Compact DATA serializer for selected Audit Note items.
 * Auto-fill algorithm rules live in NewContentAutoDnaPolicy (system-owned).
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

        $lines = [
            'Selected SEO Audit Notes:',
            'DNA placement contract:',
            '  · placement=before → phrase is guided BEFORE the base topic/keyword.',
            '  · placement=after → phrase is guided AFTER the base topic/keyword.',
            'Respect each DNA placement; do not arbitrarily invert user-specified positions.',
        ];
        foreach ($items as $item) {
            $isManualSeed = AuditNoteDnaNormalizer::isManualSeed($item);
            $target = (int) $item['target_dna_count'];
            $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
            $specified = AuditNoteDnaNormalizer::specifiedSlotCount($dna);
            $missing = AuditNoteDnaNormalizer::missingSlotCount($target, $dna);

            if ($isManualSeed) {
                $seed = trim((string) ($item['seed_text'] ?? $item['cluster_name_snapshot'] ?? ''));
                $head = '- Planning Seed "'.$seed.'" · source_type=manual_seed · no existing Topic/MCP required';
            } else {
                $share = number_format((float) ($item['mcp_share_snapshot'] ?? 0), 1, '.', '');
                $head = '- '.$item['cluster_name_snapshot'].' · MCP '.$share.'% · source_type=cluster';
            }
            $lines[] = $head;
            $lines[] = '  · Target DNA: '.$target;
            $lines[] = '  · Specified slots: '.$specified;
            $lines[] = '  · Missing slots: '.$missing;
            if ($isManualSeed) {
                $lines[] = '  · Goal: explore/create a NEW semantic area from the seed text; generate novel keywords/intents/titles (not limited to existing keyword inventory).';
            }
            if ($specified > 0) {
                $lines[] = '  · Specified:';
                foreach (AuditNoteDnaNormalizer::promptLines($dna, $maxDnaPerNote) as $dnaLine) {
                    $lines[] = '    - '.$dnaLine;
                }
            }
        }

        return $lines;
    }

    /**
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

    /**
     * @param  list<array<string, mixed>>  $noteItems
     * @return list<string>
     */
    public function historyLines(array $noteItems): array
    {
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        $lines = [];
        foreach ($items as $item) {
            $target = (int) $item['target_dna_count'];
            $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
            $specified = AuditNoteDnaNormalizer::specifiedSlotCount($dna);
            $missing = AuditNoteDnaNormalizer::missingSlotCount($target, $dna);
            if (AuditNoteDnaNormalizer::isManualSeed($item)) {
                $seed = trim((string) ($item['seed_text'] ?? $item['cluster_name_snapshot'] ?? ''));
                $lines[] = 'Seed: '.$seed;
                $lines[] = 'source_type=manual_seed · DNA mục tiêu '.$target.' · Manual DNA '.$specified.' · AI fill '.$missing;
            } else {
                $lines[] = (string) $item['cluster_name_snapshot'];
                $lines[] = 'DNA mục tiêu '.$target.' · Đã chỉ định '.$specified.' · AI bổ sung '.$missing;
            }
            foreach (AuditNoteDnaNormalizer::promptLines($dna, 12) as $dnaLine) {
                $lines[] = $dnaLine;
            }
        }

        return $lines;
    }
}
