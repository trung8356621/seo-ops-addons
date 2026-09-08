<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Group outline nodes into generation units targeting ~250–350 words each.
 *
 * Enforces word-budget minimum unit count:
 *   minimumUnitsByBudget = ceil(articleTargetWords / PREFERRED_MAX)
 */
final class SectionedFreePrepareSections
{
    public const PREFERRED_MIN = 250;

    public const PREFERRED_MAX = 350;

    public const UNIT_SOFT_MAX = 400;

    public const UNIT_SOFT_MIN = 180;

    /**
     * @return list<SectionedFreeSectionUnit>
     */
    public function prepare(string $outlineMarkdown, int $articleTargetWords = 0): array
    {
        return $this->preparePlan($outlineMarkdown, $articleTargetWords)->units;
    }

    public function preparePlan(string $outlineMarkdown, int $articleTargetWords = 0): SectionedFreePlan
    {
        $target = max(0, $articleTargetWords);
        $minimumUnits = $this->minimumUnitsByBudget($target);

        $nodes = $this->parseNodes($outlineMarkdown);
        if ($nodes === []) {
            $unit = new SectionedFreeSectionUnit(
                sectionId: 'section_01',
                order: 0,
                label: 'Body',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [[
                    'kind' => 'body',
                    'heading' => 'Body',
                    'level' => 2,
                    'body' => trim($outlineMarkdown),
                    'emit_heading' => true,
                    'parent_h2' => null,
                ]],
                requiredPoints: [],
                targetMinWords: self::PREFERRED_MIN,
                targetMaxWords: self::PREFERRED_MAX,
                preferredTargetWords: 300,
            );

            return new SectionedFreePlan(
                units: [$unit],
                meta: $this->buildMeta($target, $minimumUnits, [$unit], true, 'outline_had_no_parseable_headings'),
            );
        }

        $clusters = $this->buildSemanticClusters($nodes);
        $clusters = $this->expandClustersToMeetBudget($clusters, $minimumUnits);

        $insufficient = count($clusters) < $minimumUnits;
        $reason = $insufficient
            ? 'outline_semantic_material_exhausted_before_budget_minimum'
            : null;

        $units = [];
        foreach ($clusters as $index => $cluster) {
            $units[] = $this->clusterToUnit($cluster, $index);
        }

        if (! $insufficient && count($units) < $minimumUnits) {
            $insufficient = true;
            $reason = 'planned_unit_count_below_budget_minimum';
        }

        return new SectionedFreePlan(
            units: $units,
            meta: $this->buildMeta($target, $minimumUnits, $units, $insufficient, $reason),
        );
    }

    public function minimumUnitsByBudget(int $articleTargetWords): int
    {
        $target = max(0, $articleTargetWords);
        if ($target <= 0) {
            return 1;
        }

        return max(1, (int) ceil($target / self::PREFERRED_MAX));
    }

    /**
     * @param  list<SectionedFreeSectionUnit>  $units
     * @return array<string, mixed>
     */
    private function buildMeta(
        int $target,
        int $minimumUnits,
        array $units,
        bool $insufficient,
        ?string $reason,
    ): array {
        $perSection = [];
        foreach ($units as $unit) {
            $perSection[] = [
                'section_id' => $unit->sectionId,
                'target_words' => $unit->preferredTargetWords,
                'label' => $unit->label,
            ];
        }

        return [
            'article_target_words' => $target,
            'planned_unit_count' => count($units),
            'minimum_units_by_budget' => $minimumUnits,
            'max_section_target_words' => self::PREFERRED_MAX,
            'insufficient_outline_material' => $insufficient,
            'insufficient_reason' => $reason,
            'per_section_targets' => $perSection,
        ];
    }

    /**
     * @param  array{
     *   nodes: list<array<string, mixed>>,
     *   parent_h2: ?string,
     *   emit_parent_heading: bool,
     *   role: string
     * }  $cluster
     */
    private function clusterToUnit(array $cluster, int $order): SectionedFreeSectionUnit
    {
        $nodes = $cluster['nodes'];
        $sectionId = 'section_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT);
        $h3s = [];
        foreach ($nodes as $node) {
            if ((int) ($node['level'] ?? 0) >= 3) {
                $h = trim((string) ($node['heading'] ?? ''));
                if ($h !== '') {
                    $h3s[] = $h;
                }
            }
        }

        return new SectionedFreeSectionUnit(
            sectionId: $sectionId,
            order: $order,
            label: $this->groupLabel($nodes, (bool) $cluster['emit_parent_heading'], $cluster['parent_h2']),
            role: (string) $cluster['role'],
            outlineNodes: $nodes,
            requiredPoints: $this->extractRequiredPoints($nodes),
            targetMinWords: self::PREFERRED_MIN,
            targetMaxWords: self::PREFERRED_MAX,
            preferredTargetWords: $this->estimateTargetWords($nodes),
            parentH2: $cluster['parent_h2'],
            emitParentHeading: (bool) $cluster['emit_parent_heading'],
            includedH3s: $h3s,
        );
    }

    /**
     * @return list<array{kind: string, heading: string, level: int, body: string, emit_heading: bool, parent_h2: ?string}>
     */
    private function parseNodes(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $nodes = [];
        $current = null;

        $flush = static function () use (&$nodes, &$current): void {
            if ($current === null) {
                return;
            }
            $current['body'] = trim((string) $current['body']);
            $nodes[] = $current;
            $current = null;
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $m) === 1) {
                $flush();
                $level = strlen($m[1]);
                $heading = trim($m[2]);
                $current = [
                    'kind' => $this->classifyHeading($heading, $level),
                    'heading' => $heading,
                    'level' => $level,
                    'body' => '',
                    'emit_heading' => true,
                    'parent_h2' => null,
                ];
                continue;
            }
            if ($current === null) {
                $current = [
                    'kind' => SectionedFreeSectionUnit::ROLE_INTRO,
                    'heading' => 'Introduction',
                    'level' => 2,
                    'body' => '',
                    'emit_heading' => true,
                    'parent_h2' => null,
                ];
            }
            $current['body'] .= ($current['body'] === '' ? '' : "\n").$line;
        }
        $flush();

        return $nodes;
    }

    private function classifyHeading(string $heading, int $level): string
    {
        $h = mb_strtolower($heading);
        if (
            str_contains($h, 'faq')
            || str_contains($h, 'câu hỏi thường gặp')
            || str_contains($h, 'hoi dap')
            || str_contains($h, 'hỏi đáp')
        ) {
            return SectionedFreeSectionUnit::ROLE_FAQ;
        }
        if (
            str_contains($h, 'intro')
            || str_contains($h, 'mở đầu')
            || str_contains($h, 'giới thiệu')
        ) {
            return SectionedFreeSectionUnit::ROLE_INTRO;
        }
        if (
            str_contains($h, 'conclusion')
            || str_contains($h, 'kết luận')
            || str_contains($h, 'tóm tắt')
        ) {
            return SectionedFreeSectionUnit::ROLE_CONCLUSION;
        }
        if ($level === 1) {
            return SectionedFreeSectionUnit::ROLE_INTRO;
        }

        return SectionedFreeSectionUnit::ROLE_BODY;
    }

    /**
     * Semantic clusters: Intro | H2(+H3s) | FAQ | Conclusion — one cluster each.
     * Does NOT merge across H2s (budget expansion handles further splits).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{nodes: list<array<string, mixed>>, parent_h2: ?string, emit_parent_heading: bool, role: string}>
     */
    private function buildSemanticClusters(array $nodes): array
    {
        $clusters = [];
        $i = 0;
        $count = count($nodes);

        while ($i < $count) {
            $node = $nodes[$i];
            $kind = (string) $node['kind'];
            $level = (int) $node['level'];

            if ($kind === SectionedFreeSectionUnit::ROLE_FAQ) {
                $clusters[] = $this->makeCluster([$node], null, true, SectionedFreeSectionUnit::ROLE_FAQ);
                $i++;
                continue;
            }

            if ($kind === SectionedFreeSectionUnit::ROLE_CONCLUSION) {
                $clusters[] = $this->makeCluster([$node], null, true, SectionedFreeSectionUnit::ROLE_CONCLUSION);
                $i++;
                continue;
            }

            if ($level === 2 && $kind === SectionedFreeSectionUnit::ROLE_BODY) {
                $group = [$node];
                $parentH2 = trim((string) $node['heading']);
                $j = $i + 1;
                while ($j < $count && (int) $nodes[$j]['level'] >= 3) {
                    $child = $nodes[$j];
                    $child['parent_h2'] = $parentH2;
                    $group[] = $child;
                    $j++;
                }
                $clusters[] = $this->makeCluster($group, $parentH2, true, SectionedFreeSectionUnit::ROLE_BODY);
                $i = $j;
                continue;
            }

            // Intro / H1 / loose body nodes.
            $group = [$node];
            $j = $i + 1;
            while (
                $j < $count
                && (int) $nodes[$j]['level'] !== 2
                && (string) $nodes[$j]['kind'] !== SectionedFreeSectionUnit::ROLE_FAQ
                && (string) $nodes[$j]['kind'] !== SectionedFreeSectionUnit::ROLE_CONCLUSION
            ) {
                $group[] = $nodes[$j];
                $j++;
            }
            $role = (string) ($group[0]['kind'] ?? SectionedFreeSectionUnit::ROLE_INTRO);
            $clusters[] = $this->makeCluster($group, null, true, $role);
            $i = $j;
        }

        return $clusters;
    }

    /**
     * @param  list<array{nodes: list<array<string, mixed>>, parent_h2: ?string, emit_parent_heading: bool, role: string}>  $clusters
     * @return list<array{nodes: list<array<string, mixed>>, parent_h2: ?string, emit_parent_heading: bool, role: string}>
     */
    private function expandClustersToMeetBudget(array $clusters, int $minimumUnits): array
    {
        if ($minimumUnits <= 1 || count($clusters) >= $minimumUnits) {
            return $clusters;
        }

        $guard = 0;
        while (count($clusters) < $minimumUnits && $guard < 64) {
            $guard++;
            $splitIndex = $this->findBestSplitIndex($clusters);
            if ($splitIndex === null) {
                break;
            }
            $parts = $this->splitCluster($clusters[$splitIndex]);
            if ($parts === null) {
                break;
            }
            array_splice($clusters, $splitIndex, 1, $parts);
        }

        return array_values($clusters);
    }

    /**
     * @param  list<array{nodes: list<array<string, mixed>>, parent_h2: ?string, emit_parent_heading: bool, role: string}>  $clusters
     */
    private function findBestSplitIndex(array $clusters): ?int
    {
        $bestIndex = null;
        $bestScore = -1;
        foreach ($clusters as $index => $cluster) {
            $h3Count = 0;
            foreach ($cluster['nodes'] as $node) {
                if ((int) ($node['level'] ?? 0) >= 3) {
                    $h3Count++;
                }
            }
            $weight = 0;
            foreach ($cluster['nodes'] as $node) {
                $weight += $this->nodeWeight($node);
            }
            // Prefer body H2 clusters with multiple H3s; otherwise heaviest cluster with ≥2 nodes.
            $score = ($h3Count * 1000) + $weight + (count($cluster['nodes']) * 10);
            if ($h3Count < 2 && count($cluster['nodes']) < 2) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, parent_h2: ?string, emit_parent_heading: bool, role: string}  $cluster
     * @return list<array{nodes: list<array<string, mixed>>, parent_h2: ?string, emit_parent_heading: bool, role: string}>|null
     */
    private function splitCluster(array $cluster): ?array
    {
        $nodes = $cluster['nodes'];
        $h3Indexes = [];
        foreach ($nodes as $idx => $node) {
            if ((int) ($node['level'] ?? 0) >= 3) {
                $h3Indexes[] = $idx;
            }
        }

        if (count($h3Indexes) >= 2) {
            $mid = (int) ceil(count($h3Indexes) / 2);
            $splitAtH3 = $h3Indexes[$mid];
            $leftNodes = array_slice($nodes, 0, $splitAtH3);
            $rightNodes = array_slice($nodes, $splitAtH3);
            if ($leftNodes === [] || $rightNodes === []) {
                return null;
            }

            $parentH2 = $cluster['parent_h2']
                ?? $this->firstH2Heading($nodes);

            // Left keeps parent H2 emission; right continues without re-emitting H2.
            $left = $this->makeCluster(
                $leftNodes,
                $parentH2,
                (bool) $cluster['emit_parent_heading'],
                (string) $cluster['role'],
            );
            $rightNodes = $this->markContinuationNodes($rightNodes, $parentH2);
            $right = $this->makeCluster(
                $rightNodes,
                $parentH2,
                false,
                (string) $cluster['role'],
            );

            return [$left, $right];
        }

        // Fallback: split flat node list in half.
        if (count($nodes) < 2) {
            return null;
        }
        $mid = (int) ceil(count($nodes) / 2);
        $leftNodes = array_slice($nodes, 0, $mid);
        $rightNodes = array_slice($nodes, $mid);
        $parentH2 = $cluster['parent_h2'] ?? $this->firstH2Heading($nodes);
        $rightNodes = $this->markContinuationNodes($rightNodes, $parentH2);

        return [
            $this->makeCluster($leftNodes, $parentH2, (bool) $cluster['emit_parent_heading'], (string) $cluster['role']),
            $this->makeCluster($rightNodes, $parentH2, false, (string) $cluster['role']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function markContinuationNodes(array $nodes, ?string $parentH2): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ((int) ($node['level'] ?? 0) === 2) {
                $node['emit_heading'] = false;
                $node['parent_h2'] = $parentH2 ?? trim((string) ($node['heading'] ?? ''));
            } else {
                $node['parent_h2'] = $parentH2 ?? ($node['parent_h2'] ?? null);
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function firstH2Heading(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if ((int) ($node['level'] ?? 0) === 2) {
                $h = trim((string) ($node['heading'] ?? ''));

                return $h !== '' ? $h : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array{nodes: list<array<string, mixed>>, parent_h2: ?string, emit_parent_heading: bool, role: string}
     */
    private function makeCluster(array $nodes, ?string $parentH2, bool $emitParentHeading, string $role): array
    {
        return [
            'nodes' => array_values($nodes),
            'parent_h2' => $parentH2,
            'emit_parent_heading' => $emitParentHeading,
            'role' => $role,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeWeight(array $node): int
    {
        $kind = (string) ($node['kind'] ?? '');
        $body = trim((string) ($node['body'] ?? ''));
        $bullets = preg_match_all('/^\s*[-*•]\s+/mu', $body) ?: 0;
        $base = match ($kind) {
            SectionedFreeSectionUnit::ROLE_INTRO => 220,
            SectionedFreeSectionUnit::ROLE_CONCLUSION => 200,
            SectionedFreeSectionUnit::ROLE_FAQ => 260,
            default => ((int) ($node['level'] ?? 2) >= 3 ? 140 : 280),
        };

        return $base + ($bullets * 40) + (int) min(80, (int) floor(mb_strlen($body) / 12));
    }

    /**
     * @param  list<array<string, mixed>>  $group
     */
    private function estimateTargetWords(array $group): int
    {
        $sum = 0;
        foreach ($group as $node) {
            $sum += $this->nodeWeight($node);
        }

        return max(self::PREFERRED_MIN, min(self::PREFERRED_MAX, (int) round($sum)));
    }

    /**
     * @param  list<array<string, mixed>>  $group
     */
    private function groupLabel(array $group, bool $emitParentHeading, ?string $parentH2): string
    {
        if (! $emitParentHeading && $parentH2 !== null && $parentH2 !== '') {
            $h3s = [];
            foreach ($group as $node) {
                if ((int) ($node['level'] ?? 0) >= 3) {
                    $h = trim((string) ($node['heading'] ?? ''));
                    if ($h !== '') {
                        $h3s[] = $h;
                    }
                }
            }

            return $parentH2.' — tiếp'.($h3s !== [] ? ' ('.implode(', ', array_slice($h3s, 0, 2)).')' : '');
        }

        $headings = [];
        foreach ($group as $node) {
            $emit = array_key_exists('emit_heading', $node) ? (bool) $node['emit_heading'] : true;
            if (! $emit && (int) ($node['level'] ?? 0) <= 2) {
                continue;
            }
            $h = trim((string) ($node['heading'] ?? ''));
            if ($h !== '') {
                $headings[] = $h;
            }
        }

        return $headings !== [] ? implode(' + ', array_slice($headings, 0, 3)) : 'Section';
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @return list<string>
     */
    private function extractRequiredPoints(array $group): array
    {
        $points = [];
        foreach ($group as $node) {
            $body = (string) ($node['body'] ?? '');
            if (preg_match_all('/^\s*[-*•]\s+(.+)$/mu', $body, $m) > 0) {
                foreach ($m[1] as $point) {
                    $point = trim((string) $point);
                    if ($point !== '') {
                        $points[] = $point;
                    }
                }
            }
        }

        return $points;
    }
}
