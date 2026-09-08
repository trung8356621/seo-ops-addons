<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;

/**
 * Deterministic assemble — NEVER calls an LLM.
 * Keys only by section_id / section_order — never by heading or artifact key.
 */
final class SectionedFreeAssembleArticle
{
    /**
     * @param  list<array{
     *   section_id?: string,
     *   section_order: int,
     *   output: string,
     *   status?: string,
     *   emit_parent_heading?: bool,
     *   parent_h2?: ?string
     * }>  $sections
     * @param  list<SectionedFreeSectionUnit>  $plannedUnits
     */
    public function assemble(array $sections, array $plannedUnits = []): string
    {
        $plannedCount = count($plannedUnits);
        if ($plannedCount > 0 && count($sections) !== $plannedCount) {
            throw new PromptRunException(
                'SECTIONED_FREE_SECTION_FAILED: assemble refused — section row count mismatch.',
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                    'planned_sections' => $plannedCount,
                    'completed_sections' => count($sections),
                    'retryable' => false,
                ],
            );
        }

        foreach ($sections as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status !== SectionedFreeRunState::STATUS_COMPLETED || trim((string) ($row['output'] ?? '')) === '') {
                throw new PromptRunException(
                    'SECTIONED_FREE_SECTION_FAILED: assemble refused — incomplete section '
                    .(string) ($row['section_id'] ?? '?'),
                    0,
                    null,
                    [
                        'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                        'section_id' => $row['section_id'] ?? null,
                        'planned_sections' => $plannedCount > 0 ? $plannedCount : count($sections),
                        'retryable' => false,
                    ],
                );
            }
        }

        // Index by section_id when present; otherwise fall back to order index (legacy callers).
        $byId = [];
        foreach ($sections as $index => $row) {
            $id = trim((string) ($row['section_id'] ?? ''));
            if ($id === '') {
                $id = 'section_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $row['section_id'] = $id;
            }
            if (isset($byId[$id])) {
                throw new PromptRunException(
                    'SECTIONED_FREE_ASSEMBLY_MISMATCH: duplicate section_id '.$id,
                    0,
                    null,
                    [
                        'failure_code' => 'SECTIONED_FREE_ASSEMBLY_MISMATCH',
                        'section_id' => $id,
                        'retryable' => false,
                    ],
                );
            }
            $byId[$id] = $row;
        }

        $ordered = [];
        if ($plannedUnits !== []) {
            foreach ($plannedUnits as $unit) {
                if (! isset($byId[$unit->sectionId])) {
                    throw new PromptRunException(
                        'SECTIONED_FREE_SECTION_FAILED: missing completed section '.$unit->sectionId,
                        0,
                        null,
                        [
                            'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                            'section_id' => $unit->sectionId,
                            'planned_sections' => $plannedCount,
                            'completed_sections' => count($byId),
                            'retryable' => false,
                        ],
                    );
                }
                $row = $byId[$unit->sectionId];
                $row['emit_parent_heading'] = $unit->emitParentHeading;
                $row['parent_h2'] = $unit->parentH2;
                $ordered[] = $row;
            }
        } else {
            $ordered = array_values($byId);
            usort(
                $ordered,
                static fn (array $a, array $b): int => ((int) $a['section_order']) <=> ((int) $b['section_order']),
            );
        }

        $bodies = [];
        foreach ($ordered as $row) {
            $content = trim((string) $row['output']);
            if (($row['emit_parent_heading'] ?? true) === false) {
                $content = $this->stripLeadingParentH2(
                    $content,
                    isset($row['parent_h2']) ? (string) $row['parent_h2'] : null,
                );
            }
            $bodies[] = $content;
        }

        return trim(implode("\n\n", $bodies));
    }

    /**
     * @param  list<array{word_count?: int, output?: string}>  $sections
     * @return array{sum_section_words: int, assembled_words: int, delta: int}
     */
    public function assertWordParity(array $sections, string $assembled, int $tolerance = 40): array
    {
        $sum = 0;
        foreach ($sections as $row) {
            if (isset($row['word_count']) && is_numeric($row['word_count'])) {
                $sum += (int) $row['word_count'];
            } else {
                $sum += PromptTextMetrics::wordCount((string) ($row['output'] ?? ''));
            }
        }
        $assembledWords = PromptTextMetrics::wordCount($assembled);
        $delta = abs($sum - $assembledWords);
        $allowed = max($tolerance, (int) floor($sum * 0.08));

        if ($sum > 0 && $delta > $allowed) {
            $perSection = [];
            foreach ($sections as $row) {
                $perSection[] = [
                    'section_id' => $row['section_id'] ?? null,
                    'words' => (int) ($row['word_count']
                        ?? PromptTextMetrics::wordCount((string) ($row['output'] ?? ''))),
                ];
            }
            throw new PromptRunException(
                'SECTIONED_FREE_ASSEMBLY_MISMATCH: sum_section_words='.$sum
                .' assembled_words='.$assembledWords,
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_ASSEMBLY_MISMATCH',
                    'planned_sections' => count($sections),
                    'completed_sections' => count($sections),
                    'section_ids' => array_values(array_filter(array_map(
                        static fn (array $r): string => (string) ($r['section_id'] ?? ''),
                        $sections,
                    ))),
                    'per_section_words' => $perSection,
                    'sum_section_words' => $sum,
                    'assembled_words' => $assembledWords,
                    'retryable' => false,
                ],
            );
        }

        return [
            'sum_section_words' => $sum,
            'assembled_words' => $assembledWords,
            'delta' => $delta,
        ];
    }

    private function stripLeadingParentH2(string $content, ?string $parentH2): string
    {
        $trimmed = ltrim($content);
        if ($parentH2 === null || $parentH2 === '') {
            // Strip any leading ## heading once for continuation chunks.
            if (preg_match('/^##\s+.+\R+/u', $trimmed) === 1) {
                return trim((string) preg_replace('/^##\s+.+\R+/u', '', $trimmed, 1));
            }

            return $trimmed;
        }

        $quoted = preg_quote($parentH2, '/');
        $pattern = '/^##\s*'.$quoted.'\s*(?:—\s*tiếp)?\s*\R+/iu';
        if (preg_match($pattern, $trimmed) === 1) {
            return trim((string) preg_replace($pattern, '', $trimmed, 1));
        }

        return $trimmed;
    }
}
