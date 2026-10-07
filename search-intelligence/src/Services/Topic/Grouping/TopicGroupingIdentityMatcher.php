<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Laravel BUSINESS identity reconciliation via membership overlap.
 *
 * Composes AFTER seed identity. Does not use semantic vector scores.
 * Supports 1:1 continuity, split (one child inherits), merge (one parent survives).
 * Never assigns the same topic_id to two groups.
 *
 * Thresholds calibrated from site_id=4 overlap distribution (TASK 5.1).
 */
final class TopicGroupingIdentityMatcher
{
    /** Default minimum shared keywords for overlap candidates. */
    public const MIN_INTERSECTION = 2;

    /**
     * Tiny-topic exception: allow 1 shared member when existing_coverage is strong
     * (calibrated: many site_id=4 Topics have only 2–3 members).
     */
    public const MIN_INTERSECTION_STRONG = 1;

    /** Accept when Jaccard ≥ this. */
    public const MIN_JACCARD = 0.15;

    /**
     * Accept when both coverages clear this band.
     * Calibrated from site_id=4 unclaimed best-ec distribution (p50 jaccard≈0.14).
     */
    public const MIN_EXISTING_COVERAGE = 0.25;

    public const MIN_PROPOSED_COVERAGE = 0.15;

    /**
     * Accept when a proposed group captures this fraction of an existing Topic
     * even if the group is larger (split inheritance / majority continuity).
     */
    public const MIN_EXISTING_COVERAGE_STRONG = 0.35;

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  list<array{topic_id: int, name: string, member_keyword_ids: list<int>, member_count: int, is_locked: bool, has_focus?: bool}>  $inventory  eligible auto/unlocked Topics not yet claimed
     * @return array{
     *     clusters: list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>,
     *     diagnostics: array{
     *         reused: int,
     *         one_to_one: list<array<string, mixed>>,
     *         splits: list<array<string, mixed>>,
     *         merges: list<array<string, mixed>>,
     *         ambiguous: list<array<string, mixed>>,
     *         no_successor: list<array<string, mixed>>,
     *         matches: list<array<string, mixed>>,
     *         thresholds: array<string, float|int>
     *     }
     * }
     */
    public function apply(array $clusters, array $inventory): array
    {
        $diagnostics = [
            'reused' => 0,
            'one_to_one' => [],
            'splits' => [],
            'merges' => [],
            'ambiguous' => [],
            'no_successor' => [],
            'matches' => [],
            'thresholds' => [
                'min_intersection' => self::MIN_INTERSECTION,
                'min_intersection_strong' => self::MIN_INTERSECTION_STRONG,
                'min_jaccard' => self::MIN_JACCARD,
                'min_existing_coverage' => self::MIN_EXISTING_COVERAGE,
                'min_proposed_coverage' => self::MIN_PROPOSED_COVERAGE,
                'min_existing_coverage_strong' => self::MIN_EXISTING_COVERAGE_STRONG,
            ],
        ];

        /** @var array<int, true> $claimed */
        $claimed = [];
        foreach ($clusters as $cluster) {
            if ($cluster['topic_id'] !== null) {
                $claimed[(int) $cluster['topic_id']] = true;
            }
        }

        /** @var array<int, array{topic_id: int, name: string, member_keyword_ids: list<int>, member_count: int, is_locked: bool, has_focus: bool}> $byId */
        $byId = [];
        foreach ($inventory as $row) {
            $tid = (int) $row['topic_id'];
            if ($tid <= 0 || ($row['is_locked'] ?? false) || isset($claimed[$tid])) {
                continue;
            }
            $memberIds = array_values(array_unique(array_map('intval', $row['member_keyword_ids'])));
            if ($memberIds === []) {
                continue;
            }
            $byId[$tid] = [
                'topic_id' => $tid,
                'name' => (string) $row['name'],
                'member_keyword_ids' => $memberIds,
                'member_count' => count($memberIds),
                'is_locked' => false,
                'has_focus' => (bool) ($row['has_focus'] ?? false),
            ];
        }

        if ($byId === []) {
            return ['clusters' => $clusters, 'diagnostics' => $diagnostics];
        }

        /** @var list<int> $openGroups */
        $openGroups = [];
        /** @var array<int, array<int, true>> $groupSets */
        $groupSets = [];
        foreach ($clusters as $gi => $cluster) {
            if ($cluster['topic_id'] !== null) {
                continue;
            }
            $set = [];
            foreach ($cluster['members'] as $member) {
                $kid = (int) $member['keyword_id'];
                if ($kid > 0) {
                    $set[$kid] = true;
                }
            }
            if ($set === []) {
                continue;
            }
            $openGroups[] = $gi;
            $groupSets[$gi] = $set;
        }

        /** @var list<array{gi: int, tid: int, inter: int, jaccard: float, existing_coverage: float, proposed_coverage: float, has_focus: bool, score: float}> $pairs */
        $pairs = [];
        /** @var array<int, list<array{gi: int, inter: int, jaccard: float, existing_coverage: float, proposed_coverage: float}>> $candidatesByTopic */
        $candidatesByTopic = [];
        /** @var array<int, list<array{tid: int, inter: int, jaccard: float, existing_coverage: float, proposed_coverage: float}>> $candidatesByGroup */
        $candidatesByGroup = [];

        foreach ($openGroups as $gi) {
            $gSet = $groupSets[$gi];
            $gSize = count($gSet);
            foreach ($byId as $prior) {
                $tid = $prior['topic_id'];
                $inter = 0;
                foreach ($prior['member_keyword_ids'] as $kid) {
                    if (isset($gSet[$kid])) {
                        $inter++;
                    }
                }
                $tSize = $prior['member_count'];
                $union = $gSize + $tSize - $inter;
                $jaccard = $inter / max(1, $union);
                $ec = $inter / max(1, $tSize);
                $pc = $inter / max(1, $gSize);
                $minInter = $ec >= self::MIN_EXISTING_COVERAGE_STRONG
                    ? self::MIN_INTERSECTION_STRONG
                    : self::MIN_INTERSECTION;
                if ($inter < $minInter) {
                    continue;
                }
                if (! $this->accepts($jaccard, $ec, $pc)) {
                    continue;
                }
                $score = $this->score($jaccard, $ec, $pc, $inter, $prior['has_focus']);
                $pair = [
                    'gi' => $gi,
                    'tid' => $tid,
                    'inter' => $inter,
                    'jaccard' => $jaccard,
                    'existing_coverage' => $ec,
                    'proposed_coverage' => $pc,
                    'has_focus' => $prior['has_focus'],
                    'score' => $score,
                ];
                $pairs[] = $pair;
                $candidatesByTopic[$tid][] = [
                    'gi' => $gi,
                    'inter' => $inter,
                    'jaccard' => $jaccard,
                    'existing_coverage' => $ec,
                    'proposed_coverage' => $pc,
                ];
                $candidatesByGroup[$gi][] = [
                    'tid' => $tid,
                    'inter' => $inter,
                    'jaccard' => $jaccard,
                    'existing_coverage' => $ec,
                    'proposed_coverage' => $pc,
                ];
            }
        }

        usort($pairs, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['existing_coverage'] !== $b['existing_coverage']) {
                return $b['existing_coverage'] <=> $a['existing_coverage'];
            }
            if ($a['jaccard'] !== $b['jaccard']) {
                return $b['jaccard'] <=> $a['jaccard'];
            }
            if ($a['inter'] !== $b['inter']) {
                return $b['inter'] <=> $a['inter'];
            }
            if ($a['has_focus'] !== $b['has_focus']) {
                return $b['has_focus'] <=> $a['has_focus'];
            }

            return [$a['tid'], $a['gi']] <=> [$b['tid'], $b['gi']];
        });

        /** @var array<int, true> $assignedGroups */
        $assignedGroups = [];
        /** @var array<int, true> $assignedTopics */
        $assignedTopics = [];
        /** @var array<int, array{gi: int, tid: int, inter: int, jaccard: float, existing_coverage: float, proposed_coverage: float, has_focus: bool, score: float}> $chosen */
        $chosen = [];

        foreach ($pairs as $pair) {
            $gi = $pair['gi'];
            $tid = $pair['tid'];
            if (isset($assignedGroups[$gi]) || isset($assignedTopics[$tid]) || isset($claimed[$tid])) {
                continue;
            }
            // Ambiguous: second-best for this group within 5% score of winner remaining.
            $near = [];
            foreach ($candidatesByGroup[$gi] ?? [] as $cand) {
                if (isset($assignedTopics[$cand['tid']]) || isset($claimed[$cand['tid']])) {
                    continue;
                }
                if ($cand['tid'] === $tid) {
                    continue;
                }
                $candScore = $this->score(
                    $cand['jaccard'],
                    $cand['existing_coverage'],
                    $cand['proposed_coverage'],
                    $cand['inter'],
                    $byId[$cand['tid']]['has_focus'] ?? false,
                );
                if ($candScore >= $pair['score'] * 0.95) {
                    $near[] = $cand['tid'];
                }
            }
            if ($near !== []) {
                $diagnostics['ambiguous'][] = [
                    'group_index' => $gi,
                    'group_name' => $clusters[$gi]['name'],
                    'chosen_topic_id' => $tid,
                    'near_topic_ids' => $near,
                    'score' => round($pair['score'], 6),
                ];
            }

            $clusters[$gi]['topic_id'] = $tid;
            $assignedGroups[$gi] = true;
            $assignedTopics[$tid] = true;
            $claimed[$tid] = true;
            $chosen[$tid] = $pair;
            $diagnostics['reused']++;
            $diagnostics['matches'][] = [
                'topic_id' => $tid,
                'topic_name' => $byId[$tid]['name'],
                'group_index' => $gi,
                'group_name' => $clusters[$gi]['name'],
                'intersection' => $pair['inter'],
                'jaccard' => round($pair['jaccard'], 4),
                'existing_coverage' => round($pair['existing_coverage'], 4),
                'proposed_coverage' => round($pair['proposed_coverage'], 4),
                'has_focus' => $pair['has_focus'],
            ];
        }

        // Classify splits / merges / 1:1 / no-successor from candidate graph + chosen.
        foreach ($byId as $tid => $prior) {
            $cands = $candidatesByTopic[$tid] ?? [];
            usort($cands, static fn (array $a, array $b): int => $b['existing_coverage'] <=> $a['existing_coverage']);
            if ($cands === []) {
                if (! isset($assignedTopics[$tid])) {
                    $diagnostics['no_successor'][] = [
                        'topic_id' => $tid,
                        'topic_name' => $prior['name'],
                        'member_count' => $prior['member_count'],
                        'has_focus' => $prior['has_focus'],
                        'reason' => 'no_qualifying_overlap',
                    ];
                }
                continue;
            }
            if (! isset($assignedTopics[$tid])) {
                $diagnostics['no_successor'][] = [
                    'topic_id' => $tid,
                    'topic_name' => $prior['name'],
                    'member_count' => $prior['member_count'],
                    'has_focus' => $prior['has_focus'],
                    'reason' => 'lost_greedy_assignment',
                    'best_group_name' => $clusters[$cands[0]['gi']]['name'] ?? '',
                    'best_existing_coverage' => round($cands[0]['existing_coverage'], 4),
                ];
                continue;
            }
            $chosenGi = $chosen[$tid]['gi'];
            $otherGroups = [];
            foreach ($cands as $cand) {
                if ($cand['gi'] === $chosenGi) {
                    continue;
                }
                $otherGroups[] = [
                    'group_name' => $clusters[$cand['gi']]['name'],
                    'group_index' => $cand['gi'],
                    'existing_coverage' => round($cand['existing_coverage'], 4),
                    'identity' => 'new',
                ];
            }
            $mergeOthers = [];
            foreach ($candidatesByGroup[$chosenGi] ?? [] as $cand) {
                if ($cand['tid'] === $tid) {
                    continue;
                }
                $mergeOthers[] = [
                    'topic_id' => $cand['tid'],
                    'topic_name' => $byId[$cand['tid']]['name'] ?? '',
                    'existing_coverage' => round($cand['existing_coverage'], 4),
                    'fate' => isset($assignedTopics[$cand['tid']]) ? 'reused_elsewhere' : 'dissolve_candidate',
                ];
            }

            if (count($cands) >= 2 && $otherGroups !== []) {
                $diagnostics['splits'][] = [
                    'topic_id' => $tid,
                    'topic_name' => $prior['name'],
                    'retained_group' => $clusters[$chosenGi]['name'],
                    'retained_existing_coverage' => round($chosen[$tid]['existing_coverage'], 4),
                    'other_groups' => array_slice($otherGroups, 0, 6),
                ];
            } elseif (count($mergeOthers) >= 1) {
                $diagnostics['merges'][] = [
                    'surviving_topic_id' => $tid,
                    'surviving_topic_name' => $prior['name'],
                    'group_name' => $clusters[$chosenGi]['name'],
                    'merged_from' => array_slice($mergeOthers, 0, 6),
                ];
            } else {
                $diagnostics['one_to_one'][] = [
                    'topic_id' => $tid,
                    'topic_name' => $prior['name'],
                    'group_name' => $clusters[$chosenGi]['name'],
                    'jaccard' => round($chosen[$tid]['jaccard'], 4),
                    'existing_coverage' => round($chosen[$tid]['existing_coverage'], 4),
                ];
            }
        }

        return ['clusters' => $clusters, 'diagnostics' => $diagnostics];
    }

    public function accepts(float $jaccard, float $existingCoverage, float $proposedCoverage): bool
    {
        if ($jaccard >= self::MIN_JACCARD) {
            return true;
        }
        if ($existingCoverage >= self::MIN_EXISTING_COVERAGE && $proposedCoverage >= self::MIN_PROPOSED_COVERAGE) {
            return true;
        }

        return $existingCoverage >= self::MIN_EXISTING_COVERAGE_STRONG;
    }

    public function score(
        float $jaccard,
        float $existingCoverage,
        float $proposedCoverage,
        int $intersection,
        bool $hasFocus,
    ): float {
        // Continuity-first: existing coverage dominates; focus is a small tie-break.
        return ($existingCoverage * 1000.0)
            + ($jaccard * 100.0)
            + ($proposedCoverage * 10.0)
            + min(50, $intersection)
            + ($hasFocus ? 0.5 : 0.0);
    }
}
