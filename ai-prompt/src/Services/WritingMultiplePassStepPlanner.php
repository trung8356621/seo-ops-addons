<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePlan;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;

/**
 * MULTIPLE_PASS step plan from persisted structured Outline rows.
 * Special kinds (intro/faq/conclusion) BEFORE generic H2/H3 rules — no duplicates.
 * No word-budget expansion. No markdown re-parse.
 */
final class WritingMultiplePassStepPlanner
{
    /**
     * @param  list<array{
     *   id?: string,
     *   order?: int,
     *   level?: int,
     *   title?: string,
     *   note?: string,
     *   parent_id?: ?string,
     *   kind?: string
     * }>  $rows
     */
    public function planFromRows(array $rows): SectionedFreePlan
    {
        if ($rows === []) {
            $unit = new SectionedFreeSectionUnit(
                sectionId: 'section_01',
                order: 0,
                label: 'Body',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [[
                    'kind' => 'body',
                    'heading' => 'Body',
                    'level' => 2,
                    'body' => '',
                    'emit_heading' => true,
                    'parent_h2' => null,
                ]],
                requiredPoints: [],
                targetMinWords: 180,
                targetMaxWords: 400,
                preferredTargetWords: 300,
            );

            return new SectionedFreePlan(
                units: [$unit],
                meta: [
                    'article_target_words' => 0,
                    'planned_unit_count' => 1,
                    'minimum_units_by_budget' => 1,
                    'source' => 'structured_outline_rows',
                    'insufficient_outline_material' => true,
                    'reason' => 'empty_structured_rows',
                ],
            );
        }

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $byId[$id] = $row;
        }

        $claimed = [];
        $units = [];
        $order = 0;

        // 1) intro — every intro kind, once each
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($claimed[$id])) {
                continue;
            }
            if ((string) ($row['kind'] ?? '') !== OutlineStructuredRowsNormalizer::KIND_INTRO) {
                continue;
            }
            $claimed[$id] = true;
            $units[] = $this->unitFromRows(
                sectionId: 'intro_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT),
                order: $order++,
                label: 'Introduction',
                role: SectionedFreeSectionUnit::ROLE_INTRO,
                stepRows: [$row],
                parentH2: null,
                emitParentHeading: false,
            );
        }

        // 2) faq — group all FAQ rows into one step
        $faqRows = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($claimed[$id])) {
                continue;
            }
            if ((string) ($row['kind'] ?? '') !== OutlineStructuredRowsNormalizer::KIND_FAQ) {
                continue;
            }
            $claimed[$id] = true;
            $faqRows[] = $row;
            // Claim FAQ children if any
            foreach ($rows as $child) {
                $childId = (string) ($child['id'] ?? '');
                if ($childId === '' || isset($claimed[$childId])) {
                    continue;
                }
                if ((string) ($child['parent_id'] ?? '') === $id) {
                    $claimed[$childId] = true;
                    $faqRows[] = $child;
                }
            }
        }
        if ($faqRows !== []) {
            $units[] = $this->unitFromRows(
                sectionId: 'faq_01',
                order: $order++,
                label: 'FAQ',
                role: SectionedFreeSectionUnit::ROLE_FAQ,
                stepRows: $faqRows,
                parentH2: null,
                emitParentHeading: true,
            );
        }

        // 3) conclusion — once
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($claimed[$id])) {
                continue;
            }
            if ((string) ($row['kind'] ?? '') !== OutlineStructuredRowsNormalizer::KIND_CONCLUSION) {
                continue;
            }
            $claimed[$id] = true;
            $childRows = [$row];
            foreach ($rows as $child) {
                $childId = (string) ($child['id'] ?? '');
                if ($childId === '' || isset($claimed[$childId])) {
                    continue;
                }
                if ((string) ($child['parent_id'] ?? '') === $id) {
                    $claimed[$childId] = true;
                    $childRows[] = $child;
                }
            }
            $units[] = $this->unitFromRows(
                sectionId: 'conclusion_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT),
                order: $order++,
                label: 'Conclusion',
                role: SectionedFreeSectionUnit::ROLE_CONCLUSION,
                stepRows: $childRows,
                parentH2: null,
                emitParentHeading: true,
            );
        }

        // 4) remaining normal H2/H3 — H2 with H3s → one step per H3; bare H2 → one step
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($claimed[$id])) {
                continue;
            }

            $kind = (string) ($row['kind'] ?? '');
            $level = (int) ($row['level'] ?? 0);
            $isH2 = $kind === OutlineStructuredRowsNormalizer::KIND_H2 || $level === 2;

            if (! $isH2) {
                continue;
            }

            $children = [];
            foreach ($rows as $child) {
                $childId = (string) ($child['id'] ?? '');
                if ($childId === '' || isset($claimed[$childId])) {
                    continue;
                }
                if ((string) ($child['parent_id'] ?? '') !== $id) {
                    continue;
                }
                $childKind = (string) ($child['kind'] ?? '');
                if (in_array($childKind, [
                    OutlineStructuredRowsNormalizer::KIND_FAQ,
                    OutlineStructuredRowsNormalizer::KIND_CONCLUSION,
                    OutlineStructuredRowsNormalizer::KIND_INTRO,
                ], true)) {
                    continue;
                }
                $children[] = $child;
            }

            $claimed[$id] = true;

            if ($children !== []) {
                foreach ($children as $child) {
                    $childId = (string) ($child['id'] ?? '');
                    if ($childId !== '') {
                        $claimed[$childId] = true;
                    }
                    $units[] = $this->unitFromRows(
                        sectionId: 'h3_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT),
                        order: $order++,
                        label: trim((string) ($child['title'] ?? 'H3')) ?: 'H3',
                        role: SectionedFreeSectionUnit::ROLE_BODY,
                        stepRows: [
                            [
                                'kind' => 'h2_context',
                                'title' => (string) ($row['title'] ?? ''),
                                'note' => (string) ($row['note'] ?? ''),
                                'level' => 2,
                                'emit_heading' => false,
                            ],
                            $child,
                        ],
                        parentH2: trim((string) ($row['title'] ?? '')) ?: null,
                        emitParentHeading: true,
                    );
                }
                continue;
            }

            $units[] = $this->unitFromRows(
                sectionId: 'h2_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT),
                order: $order++,
                label: trim((string) ($row['title'] ?? 'H2')) ?: 'H2',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                stepRows: [$row],
                parentH2: null,
                emitParentHeading: true,
            );
        }

        // Orphan H3s (no parent H2 remaining)
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($claimed[$id])) {
                continue;
            }
            $kind = (string) ($row['kind'] ?? '');
            $level = (int) ($row['level'] ?? 0);
            if ($kind !== OutlineStructuredRowsNormalizer::KIND_H3 && $level < 3) {
                continue;
            }
            $claimed[$id] = true;
            $parentTitle = null;
            $parentNote = '';
            $parentId = trim((string) ($row['parent_id'] ?? ''));
            $stepRows = [];
            if ($parentId !== '' && isset($byId[$parentId])) {
                $parentTitle = trim((string) ($byId[$parentId]['title'] ?? ''));
                $parentNote = trim((string) ($byId[$parentId]['note'] ?? ''));
                $stepRows[] = [
                    'kind' => 'h2_context',
                    'title' => $parentTitle,
                    'note' => $parentNote,
                    'level' => 2,
                    'emit_heading' => false,
                ];
            }
            $stepRows[] = $row;
            $units[] = $this->unitFromRows(
                sectionId: 'h3_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT),
                order: $order++,
                label: trim((string) ($row['title'] ?? 'H3')) ?: 'H3',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                stepRows: $stepRows,
                parentH2: $parentTitle,
                emitParentHeading: $parentTitle !== null && $parentTitle !== '',
            );
        }

        // Leftover other kinds
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($claimed[$id])) {
                continue;
            }
            $claimed[$id] = true;
            $units[] = $this->unitFromRows(
                sectionId: 'other_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT),
                order: $order++,
                label: trim((string) ($row['title'] ?? 'Section')) ?: 'Section',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                stepRows: [$row],
                parentH2: null,
                emitParentHeading: true,
            );
        }

        // Stable order: intro first, then body by original row order, faq, conclusion last
        usort($units, static function (SectionedFreeSectionUnit $a, SectionedFreeSectionUnit $b): int {
            $rank = static function (SectionedFreeSectionUnit $u): int {
                return match ($u->role) {
                    SectionedFreeSectionUnit::ROLE_INTRO => 0,
                    SectionedFreeSectionUnit::ROLE_BODY => 1,
                    SectionedFreeSectionUnit::ROLE_FAQ => 2,
                    SectionedFreeSectionUnit::ROLE_CONCLUSION => 3,
                    default => 4,
                };
            };
            $ra = $rank($a);
            $rb = $rank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return $a->order <=> $b->order;
        });

        // Re-number order after sort
        $renumbered = [];
        foreach (array_values($units) as $i => $unit) {
            $renumbered[] = new SectionedFreeSectionUnit(
                sectionId: $unit->sectionId,
                order: $i,
                label: $unit->label,
                role: $unit->role,
                outlineNodes: $unit->outlineNodes,
                requiredPoints: $unit->requiredPoints,
                targetMinWords: $unit->targetMinWords,
                targetMaxWords: $unit->targetMaxWords,
                preferredTargetWords: $unit->preferredTargetWords,
                parentH2: $unit->parentH2,
                emitParentHeading: $unit->emitParentHeading,
                includedH3s: $unit->includedH3s,
            );
        }

        return new SectionedFreePlan(
            units: $renumbered,
            meta: [
                'article_target_words' => 0,
                'planned_unit_count' => count($renumbered),
                'minimum_units_by_budget' => 1,
                'source' => 'structured_outline_rows',
                'insufficient_outline_material' => false,
                'reason' => null,
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $stepRows
     */
    private function unitFromRows(
        string $sectionId,
        int $order,
        string $label,
        string $role,
        array $stepRows,
        ?string $parentH2,
        bool $emitParentHeading,
    ): SectionedFreeSectionUnit {
        $nodes = [];
        $includedH3s = [];
        $required = [];

        foreach ($stepRows as $row) {
            $title = trim((string) ($row['title'] ?? $row['heading'] ?? ''));
            $note = trim((string) ($row['note'] ?? $row['body'] ?? ''));
            $level = (int) ($row['level'] ?? 2);
            $kind = (string) ($row['kind'] ?? 'body');
            $emitHeading = array_key_exists('emit_heading', $row)
                ? (bool) $row['emit_heading']
                : ($kind !== 'h2_context');

            if ($level >= 3 && $title !== '') {
                $includedH3s[] = $title;
            }
            if ($note !== '') {
                $required[] = $note;
            }

            $nodes[] = [
                'kind' => $kind,
                'heading' => $title !== '' ? $title : $label,
                'level' => max(0, $level),
                'body' => $note,
                'emit_heading' => $emitHeading,
                'parent_h2' => $parentH2,
            ];
        }

        return new SectionedFreeSectionUnit(
            sectionId: $sectionId,
            order: $order,
            label: $label,
            role: $role,
            outlineNodes: $nodes,
            requiredPoints: $required,
            targetMinWords: 180,
            targetMaxWords: 400,
            preferredTargetWords: 300,
            parentH2: $parentH2,
            emitParentHeading: $emitParentHeading,
            includedH3s: $includedH3s,
        );
    }
}
