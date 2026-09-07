<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Group outline nodes into generation units targeting ~250–350 words each.
 *
 * Does not split every H2/H3 into its own API call.
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
    public function prepare(string $outlineMarkdown): array
    {
        $nodes = $this->parseNodes($outlineMarkdown);
        if ($nodes === []) {
            return [
                new SectionedFreeSectionUnit(
                    sectionId: 'section_01',
                    order: 0,
                    label: 'Body',
                    role: SectionedFreeSectionUnit::ROLE_BODY,
                    outlineNodes: [[
                        'kind' => 'body',
                        'heading' => 'Body',
                        'level' => 2,
                        'body' => trim($outlineMarkdown),
                    ]],
                    requiredPoints: [],
                    targetMinWords: self::PREFERRED_MIN,
                    targetMaxWords: self::PREFERRED_MAX,
                    preferredTargetWords: 300,
                ),
            ];
        }

        $groups = $this->groupNodes($nodes);
        $units = [];
        foreach ($groups as $index => $group) {
            $order = $index;
            $sectionId = 'section_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT);
            $label = $this->groupLabel($group);
            $role = $this->groupRole($group);
            $estimate = $this->estimateTargetWords($group);
            $units[] = new SectionedFreeSectionUnit(
                sectionId: $sectionId,
                order: $order,
                label: $label,
                role: $role,
                outlineNodes: $group,
                requiredPoints: $this->extractRequiredPoints($group),
                targetMinWords: self::PREFERRED_MIN,
                targetMaxWords: self::PREFERRED_MAX,
                preferredTargetWords: $estimate,
            );
        }

        return $units;
    }

    /**
     * @return list<array{kind: string, heading: string, level: int, body: string}>
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
                ];
                continue;
            }
            if ($current === null) {
                $current = [
                    'kind' => SectionedFreeSectionUnit::ROLE_INTRO,
                    'heading' => 'Introduction',
                    'level' => 2,
                    'body' => '',
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
     * @param  list<array{kind: string, heading: string, level: int, body: string}>  $nodes
     * @return list<list<array{kind: string, heading: string, level: int, body: string}>>
     */
    private function groupNodes(array $nodes): array
    {
        $groups = [];
        $buffer = [];
        $bufferWeight = 0;

        $flush = static function () use (&$groups, &$buffer, &$bufferWeight): void {
            if ($buffer === []) {
                return;
            }
            $groups[] = $buffer;
            $buffer = [];
            $bufferWeight = 0;
        };

        $i = 0;
        $count = count($nodes);
        while ($i < $count) {
            $node = $nodes[$i];
            $kind = (string) $node['kind'];

            // FAQ always its own unit.
            if ($kind === SectionedFreeSectionUnit::ROLE_FAQ) {
                $flush();
                $groups[] = [$node];
                $i++;
                continue;
            }

            // H2 + following H3 children as one candidate cluster.
            if ((int) $node['level'] === 2 && $kind === SectionedFreeSectionUnit::ROLE_BODY) {
                $cluster = [$node];
                $weight = $this->nodeWeight($node);
                $j = $i + 1;
                while ($j < $count && (int) $nodes[$j]['level'] >= 3) {
                    $cluster[] = $nodes[$j];
                    $weight += $this->nodeWeight($nodes[$j]);
                    $j++;
                }

                if ($buffer !== [] && ($bufferWeight + $weight) > self::UNIT_SOFT_MAX) {
                    $flush();
                }
                if ($buffer === []) {
                    $buffer = $cluster;
                    $bufferWeight = $weight;
                } elseif (($bufferWeight + $weight) <= self::UNIT_SOFT_MAX) {
                    foreach ($cluster as $item) {
                        $buffer[] = $item;
                    }
                    $bufferWeight += $weight;
                } else {
                    $flush();
                    $buffer = $cluster;
                    $bufferWeight = $weight;
                }

                // Prefer flushing when we reached preferred band.
                if ($bufferWeight >= self::PREFERRED_MIN && $bufferWeight <= self::UNIT_SOFT_MAX) {
                    // Keep buffering only if next is a tiny trailing intro/conclusion-ish node.
                    $next = $nodes[$j] ?? null;
                    if ($next === null
                        || (string) $next['kind'] === SectionedFreeSectionUnit::ROLE_FAQ
                        || (string) $next['kind'] === SectionedFreeSectionUnit::ROLE_CONCLUSION
                        || $bufferWeight >= self::PREFERRED_MAX
                    ) {
                        $flush();
                    }
                }

                $i = $j;
                continue;
            }

            $weight = $this->nodeWeight($node);
            if ($buffer !== [] && ($bufferWeight + $weight) > self::UNIT_SOFT_MAX) {
                $flush();
            }
            $buffer[] = $node;
            $bufferWeight += $weight;

            if ($kind === SectionedFreeSectionUnit::ROLE_CONCLUSION
                || $bufferWeight >= self::PREFERRED_MAX
            ) {
                $flush();
            }

            $i++;
        }
        $flush();

        return $this->mergeUndersized($groups);
    }

    /**
     * @param  list<list<array{kind: string, heading: string, level: int, body: string}>>  $groups
     * @return list<list<array{kind: string, heading: string, level: int, body: string}>>
     */
    private function mergeUndersized(array $groups): array
    {
        if (count($groups) <= 1) {
            return $groups;
        }

        $merged = [];
        $pending = null;
        $pendingWeight = 0;

        foreach ($groups as $group) {
            $weight = 0;
            foreach ($group as $node) {
                $weight += $this->nodeWeight($node);
            }
            $isFaq = ((string) ($group[0]['kind'] ?? '')) === SectionedFreeSectionUnit::ROLE_FAQ;

            if ($isFaq) {
                if ($pending !== null) {
                    $merged[] = $pending;
                    $pending = null;
                    $pendingWeight = 0;
                }
                $merged[] = $group;
                continue;
            }

            if ($pending === null) {
                $pending = $group;
                $pendingWeight = $weight;
                continue;
            }

            if ($pendingWeight < self::UNIT_SOFT_MIN && ($pendingWeight + $weight) <= self::UNIT_SOFT_MAX) {
                foreach ($group as $node) {
                    $pending[] = $node;
                }
                $pendingWeight += $weight;
                continue;
            }

            $merged[] = $pending;
            $pending = $group;
            $pendingWeight = $weight;
        }

        if ($pending !== null) {
            // Attach tiny trailing group to previous if possible.
            if (
                $pendingWeight < self::UNIT_SOFT_MIN
                && $merged !== []
                && ((string) ($merged[array_key_last($merged)][0]['kind'] ?? '')) !== SectionedFreeSectionUnit::ROLE_FAQ
            ) {
                $lastIdx = array_key_last($merged);
                foreach ($pending as $node) {
                    $merged[$lastIdx][] = $node;
                }
            } else {
                $merged[] = $pending;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array{kind: string, heading: string, level: int, body: string}  $node
     */
    private function nodeWeight(array $node): int
    {
        $kind = (string) $node['kind'];
        $body = trim((string) $node['body']);
        $bullets = preg_match_all('/^\s*[-*•]\s+/mu', $body) ?: 0;
        $base = match ($kind) {
            SectionedFreeSectionUnit::ROLE_INTRO => 220,
            SectionedFreeSectionUnit::ROLE_CONCLUSION => 200,
            SectionedFreeSectionUnit::ROLE_FAQ => 260,
            default => ((int) $node['level'] >= 3 ? 140 : 280),
        };

        return $base + ($bullets * 40) + (int) min(80, (int) floor(mb_strlen($body) / 12));
    }

    /**
     * @param  list<array{kind: string, heading: string, level: int, body: string}>  $group
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
     * @param  list<array{kind: string, heading: string, level: int, body: string}>  $group
     */
    private function groupLabel(array $group): string
    {
        $headings = [];
        foreach ($group as $node) {
            $h = trim((string) $node['heading']);
            if ($h !== '') {
                $headings[] = $h;
            }
        }

        return $headings !== [] ? implode(' + ', array_slice($headings, 0, 3)) : 'Section';
    }

    /**
     * @param  list<array{kind: string, heading: string, level: int, body: string}>  $group
     */
    private function groupRole(array $group): string
    {
        foreach ($group as $node) {
            $kind = (string) $node['kind'];
            if ($kind === SectionedFreeSectionUnit::ROLE_FAQ) {
                return SectionedFreeSectionUnit::ROLE_FAQ;
            }
            if ($kind === SectionedFreeSectionUnit::ROLE_CONCLUSION) {
                return SectionedFreeSectionUnit::ROLE_CONCLUSION;
            }
        }
        $first = (string) ($group[0]['kind'] ?? SectionedFreeSectionUnit::ROLE_BODY);

        return $first !== '' ? $first : SectionedFreeSectionUnit::ROLE_BODY;
    }

    /**
     * @param  list<array{kind: string, heading: string, level: int, body: string}>  $group
     * @return list<string>
     */
    private function extractRequiredPoints(array $group): array
    {
        $points = [];
        foreach ($group as $node) {
            $body = (string) $node['body'];
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
