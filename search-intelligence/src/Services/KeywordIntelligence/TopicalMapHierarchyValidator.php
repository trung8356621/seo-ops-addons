<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

/**
 * Validate topical hierarchy before persist (array topic + assignment payloads).
 */
final class TopicalMapHierarchyValidator
{
    /**
     * @param  list<array<string, mixed>>  $topics
     * @param  list<array<string, mixed>>  $assignments
     * @return array{status: string, reasons: list<string>, valid: bool}
     */
    public function validate(array $topics, array $assignments = [], int $maxDepth = 4): array
    {
        $maxDepth = max(1, $maxDepth);
        $reasons = [];
        $hasInvalid = false;
        $hasWarning = false;
        $byRef = [];

        foreach ($topics as $topic) {
            $ref = (string) ($topic['ref'] ?? $topic['topic_ref'] ?? $topic['candidate_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $byRef[$ref] = $topic;
        }

        foreach ($byRef as $ref => $topic) {
            [$depth, $cycle, $brokenParent] = $this->walkToRoot($byRef, $ref);
            if ($cycle) {
                $reasons[] = 'cycle_detected:'.$ref;
                $hasInvalid = true;
                continue;
            }
            if ($brokenParent !== null) {
                $reasons[] = 'orphan_topic:'.$brokenParent;
                $hasWarning = true;
            }
            if ($depth > $maxDepth) {
                $reasons[] = 'max_depth_exceeded:'.$ref;
                $hasWarning = true;
            }
            $topicType = (string) ($topic['topic_type'] ?? $topic['type'] ?? '');
            $parentRef = $topic['parent_ref'] ?? null;
            if ($topicType !== 'root' && ($parentRef === null || $parentRef === '')) {
                $reasons[] = 'orphan_topic:'.$ref;
                $hasWarning = true;
            }
        }

        $primaryCounts = [];
        foreach ($assignments as $row) {
            if ((string) ($row['relationship'] ?? '') !== 'primary') {
                continue;
            }
            $clusterRef = (string) ($row['cluster_ref'] ?? '');
            if ($clusterRef === '') {
                continue;
            }
            $primaryCounts[$clusterRef] = ($primaryCounts[$clusterRef] ?? 0) + 1;
        }
        foreach ($primaryCounts as $clusterRef => $count) {
            if ($count > 1) {
                $reasons[] = 'cluster_multiple_primary:'.$clusterRef;
                $hasInvalid = true;
            }
        }

        $reasons = array_values(array_unique($reasons));
        $status = match (true) {
            $hasInvalid => 'invalid',
            $hasWarning => 'needs_review',
            default => 'valid',
        };

        return [
            'status' => $status,
            'reasons' => $reasons,
            'valid' => ! $hasInvalid,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $byRef
     * @return array{0: int, 1: bool, 2: string|null}
     */
    private function walkToRoot(array $byRef, string $start): array
    {
        $seen = [];
        $depth = 0;
        $current = $start;
        $broken = null;

        while ($current !== '' && isset($byRef[$current])) {
            if (isset($seen[$current])) {
                return [$depth, true, null];
            }
            $seen[$current] = true;
            $parent = $byRef[$current]['parent_ref'] ?? null;
            if ($parent === null || $parent === '') {
                break;
            }
            $parent = (string) $parent;
            if (! isset($byRef[$parent])) {
                $broken = $parent;
                break;
            }
            $depth++;
            $current = $parent;
            if ($depth > 64) {
                return [$depth, true, null];
            }
        }

        return [$depth, false, $broken];
    }
}
