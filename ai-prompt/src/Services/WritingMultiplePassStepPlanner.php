<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePlan;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;

/** Build compact, contiguous writing units for the Free writing path. */
final class WritingMultiplePassStepPlanner
{
    public const ARTICLE_TARGET_WORDS = 2000;

    private const DESIRED_MAX_AI_UNITS = 6;

    /** @param list<array<string, mixed>> $rows */
    public function planFromRows(array $rows): SectionedFreePlan
    {
        if ($rows === []) {
            $unit = $this->makeUnit([['id' => 'body', 'kind' => 'body', 'title' => 'Body', 'level' => 2]], 0, 2000);

            return new SectionedFreePlan([$unit], $this->meta(1, true, 'empty_structured_rows'));
        }

        $segments = [[]];
        $faqGroups = [];
        foreach ($rows as $row) {
            if ((string) ($row['kind'] ?? '') === OutlineStructuredRowsNormalizer::KIND_FAQ) {
                $faqGroups[] = ['after_segment' => count($segments) - 1, 'rows' => [$row]];
                $segments[] = [];
            } else {
                $segments[array_key_last($segments)][] = $row;
            }
        }

        $writingCount = array_sum(array_map('count', $segments));
        $desired = min(self::DESIRED_MAX_AI_UNITS, max(1, (int) ceil($writingCount / 4.0)));
        $nonEmptySegments = count(array_filter($segments));
        $remainingDesired = max($desired, $nonEmptySegments);
        $remainingRows = $writingCount;
        $groupsBySegment = [];
        $groups = [];
        foreach ($segments as $segmentIndex => $segment) {
            if ($segment === []) {
                $groupsBySegment[$segmentIndex] = [];

                continue;
            }
            $futureNonEmpty = count(array_filter(array_slice($segments, $segmentIndex + 1)));
            $maxForCurrent = max(1, $remainingDesired - $futureNonEmpty);
            $segmentDesired = min(count($segment), $maxForCurrent, max(1, (int) round($remainingDesired * count($segment) / max(1, $remainingRows))));
            $groupsBySegment[$segmentIndex] = $this->groupContiguousRows($segment, $segmentDesired);
            array_push($groups, ...$groupsBySegment[$segmentIndex]);
            $remainingDesired -= count($groupsBySegment[$segmentIndex]);
            $remainingRows -= count($segment);
        }
        $budgets = $this->allocateWords(array_map(fn (array $group): int => $this->groupWeight($group), $groups));
        $units = [];
        $budgetIndex = 0;
        foreach ($segments as $segmentIndex => $_segment) {
            foreach ($groupsBySegment[$segmentIndex] as $group) {
                $units[] = $this->makeUnit($group, count($units), $budgets[$budgetIndex++]);
            }
            foreach ($faqGroups as $faqGroup) {
                if ($faqGroup['after_segment'] === $segmentIndex) {
                    $units[] = $this->makeFaqUnit($faqGroup['rows'], count($units));
                }
            }
        }

        return new SectionedFreePlan($units, $this->meta(count($units), false, null) + [
            'ai_unit_count' => count($groups),
            'desired_ai_unit_count' => $desired,
            'word_budget_min' => 1800,
            'word_budget_max' => 2200,
        ]);
    }

    /** @param list<array<string, mixed>> $rows @return list<list<array<string, mixed>>> */
    private function groupContiguousRows(array $rows, int $desired): array
    {
        if ($rows === []) {
            return [];
        }

        // Group into H2 trees (an H2 and all its H3 children stay together)
        $trees = [];
        $currentTree = [];
        foreach ($rows as $r) {
            $kind = (string) ($r['kind'] ?? '');
            $level = (int) ($r['level'] ?? 2);
            $isBoundary = ($level <= 2 && $kind !== OutlineStructuredRowsNormalizer::KIND_H3)
                || $kind === OutlineStructuredRowsNormalizer::KIND_INTRO
                || $kind === OutlineStructuredRowsNormalizer::KIND_CONCLUSION;

            if ($isBoundary && $currentTree !== []) {
                $trees[] = $currentTree;
                $currentTree = [];
            }
            $currentTree[] = $r;
        }
        if ($currentTree !== []) {
            $trees[] = $currentTree;
        }

        // Pack trees into units targeting ~$desired groups, max 7 rows per unit
        $groups = [];
        $currentGroup = [];
        $treesRemaining = count($trees);
        $groupsRemaining = max(1, min($desired, $treesRemaining));

        foreach ($trees as $tIdx => $tree) {
            $cCount = count($currentGroup);
            $tCount = count($tree);
            $treesLeft = count($trees) - $tIdx;

            // Must close if remaining trees equals remaining groups
            $mustClose = $groupsRemaining > 1 && $treesLeft === $groupsRemaining && $cCount > 0;
            $overCapacity = $cCount > 0 && ($cCount + $tCount > 7 || ($cCount >= 4 && $tCount >= 3) || ($tCount >= 5 && $cCount >= 2));

            if ($mustClose || ($groupsRemaining > 1 && $overCapacity)) {
                $groups[] = $currentGroup;
                $groupsRemaining--;
                $currentGroup = [];
            }
            array_push($currentGroup, ...$tree);
        }
        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /** @param list<array<string, mixed>> $rows */
    private function groupWeight(array $rows): int
    {
        return max(1, array_sum(array_map(fn (array $row): int => $this->rowWeight($row), $rows)));
    }

    /** @param array<string, mixed> $row */
    private function rowWeight(array $row): int
    {
        $kind = (string) ($row['kind'] ?? '');
        $noteWords = str_word_count(strip_tags((string) ($row['note'] ?? '')));

        return match ($kind) {
            OutlineStructuredRowsNormalizer::KIND_INTRO => 70 + min(40, $noteWords),
            OutlineStructuredRowsNormalizer::KIND_CONCLUSION => 45 + min(30, $noteWords),
            OutlineStructuredRowsNormalizer::KIND_H3 => 100 + min(50, $noteWords),
            default => 70 + min(50, $noteWords),
        };
    }

    /** @param list<int> $weights @return list<int> */
    private function allocateWords(array $weights): array
    {
        $sum = max(1, array_sum($weights));
        $floor = 80;
        $remaining = max(0, self::ARTICLE_TARGET_WORDS - ($floor * count($weights)));
        $allocated = [];
        $used = 0;
        foreach ($weights as $index => $weight) {
            $words = $index === array_key_last($weights)
                ? self::ARTICLE_TARGET_WORDS - $used
                : $floor + (int) round($remaining * $weight / $sum);
            $allocated[] = $words;
            $used += $words;
        }

        return $allocated;
    }

    /** @param list<array<string, mixed>> $rows */
    private function makeUnit(array $rows, int $order, int $preferred): SectionedFreeSectionUnit
    {
        $nodes = $required = $h3s = $rowIds = $roles = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? $row['heading'] ?? ''));
            $note = trim((string) ($row['note'] ?? $row['body'] ?? ''));
            $kind = (string) ($row['kind'] ?? 'body');
            $level = max(0, (int) ($row['level'] ?? 2));
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $rowIds[] = $id;
            }
            $roles[] = $kind;
            if ($level >= 3 && $title !== '') {
                $h3s[] = $title;
            }
            if ($note !== '') {
                $required[] = $note;
            }
            $nodes[] = [
                'kind' => $kind, 'heading' => $title !== '' ? $title : 'Section', 'level' => $level,
                'body' => $note, 'emit_heading' => $kind !== OutlineStructuredRowsNormalizer::KIND_INTRO,
                'parent_h2' => null, 'row_id' => $id,
            ];
        }
        $uniqueRoles = array_unique($roles);
        $onlyIntro = count($uniqueRoles) === 1 && reset($uniqueRoles) === OutlineStructuredRowsNormalizer::KIND_INTRO;
        $onlyConclusion = count($uniqueRoles) === 1 && reset($uniqueRoles) === OutlineStructuredRowsNormalizer::KIND_CONCLUSION;
        $role = $onlyIntro ? SectionedFreeSectionUnit::ROLE_INTRO
            : ($onlyConclusion ? SectionedFreeSectionUnit::ROLE_CONCLUSION : SectionedFreeSectionUnit::ROLE_BODY);
        $label = trim((string) ($rows[0]['title'] ?? 'Writing group '.($order + 1))) ?: 'Writing group '.($order + 1);
        $identity = $rowIds !== [] ? implode('|', $rowIds) : 'group-'.$order;

        return new SectionedFreeSectionUnit(
            sectionId: 'group_'.substr(hash('sha256', $identity), 0, 12), order: $order, label: $label, role: $role,
            outlineNodes: $nodes, requiredPoints: $required,
            targetMinWords: max(50, (int) floor($preferred * 0.90)),
            targetMaxWords: max(100, (int) ceil($preferred * 1.10)), preferredTargetWords: $preferred,
            includedH3s: $h3s, sourceRowIds: $rowIds,
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function makeFaqUnit(array $rows, int $order): SectionedFreeSectionUnit
    {
        $base = $this->makeUnit($rows, $order, 80);

        return new SectionedFreeSectionUnit(
            sectionId: 'faq_'.substr(hash('sha256', implode('|', $base->sourceRowIds)), 0, 12),
            order: $order, label: $base->label, role: SectionedFreeSectionUnit::ROLE_FAQ,
            outlineNodes: $base->outlineNodes, requiredPoints: $base->requiredPoints,
            targetMinWords: 0, targetMaxWords: 0, preferredTargetWords: 0,
            includedH3s: $base->includedH3s, sourceRowIds: $base->sourceRowIds,
        );
    }

    /** @param array<string, mixed> $row */
    private function isH2(array $row): bool
    {
        return (string) ($row['kind'] ?? '') === OutlineStructuredRowsNormalizer::KIND_H2
            || (int) ($row['level'] ?? 0) === 2;
    }

    /** @return array<string, mixed> */
    private function meta(int $count, bool $insufficient, ?string $reason): array
    {
        return ['article_target_words' => self::ARTICLE_TARGET_WORDS, 'planned_unit_count' => $count,
            'minimum_units_by_budget' => 1, 'source' => 'structured_outline_rows',
            'insufficient_outline_material' => $insufficient, 'reason' => $reason];
    }
}
