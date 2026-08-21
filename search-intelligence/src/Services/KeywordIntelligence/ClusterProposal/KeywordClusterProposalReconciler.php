<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterProposalReconciler
{
    public function __construct(
        private readonly KeywordClusterQualityAnalyzer $analyzer,
    ) {}

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool, rehome_note?: ?string}>  $drafts
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array{
     *     drafts: list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool, rehome_note?: ?string}>,
     *     subgroups_rehomed: int,
     * }
     */
    public function reconcile(
        array $drafts,
        array $similarity,
        array $profileMap,
        string $strategy,
    ): array {
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);
        $rehomed = 0;

        /** @var list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool, rehome_note?: ?string}> $working */
        $working = array_map(static fn (array $draft): array => $draft, $drafts);

        foreach ($working as $peelIndex => $peel) {
            if (($peel['absorbed'] ?? false) === true) {
                continue;
            }
            if (($peel['split_from_label'] ?? null) === null) {
                continue;
            }
            if (count($peel['member_ids']) < 2) {
                continue;
            }

            $bestTargetIndex = null;
            $bestScore = -1.0;

            foreach ($working as $targetIndex => $target) {
                if ($targetIndex === $peelIndex) {
                    continue;
                }
                if (($target['absorbed'] ?? false) === true) {
                    continue;
                }
                if (($target['split_from_label'] ?? null) !== null) {
                    continue;
                }
                if ($this->isLikelyCoreRemainder($peel, $target, $similarity, $profileMap)) {
                    continue;
                }

                if (! $this->acceptRehome(
                    peelIds: $peel['member_ids'],
                    targetIds: $target['member_ids'],
                    similarity: $similarity,
                    profileMap: $profileMap,
                    strategy: $strategy,
                    qualityThresholds: $qualityThresholds,
                    cohesionThreshold: $thresholds['cohesion'],
                )) {
                    continue;
                }

                $score = $this->rehomeScore($peel['member_ids'], $target['member_ids'], $similarity);
                if ($score > $bestScore || ($score === $bestScore && $targetIndex < ($bestTargetIndex ?? PHP_INT_MAX))) {
                    $bestScore = $score;
                    $bestTargetIndex = $targetIndex;
                }
            }

            if ($bestTargetIndex === null) {
                continue;
            }

            $merged = array_values(array_unique([
                ...$working[$bestTargetIndex]['member_ids'],
                ...$peel['member_ids'],
            ]));
            sort($merged, SORT_NUMERIC);

            $working[$bestTargetIndex]['member_ids'] = $merged;
            $existingNote = trim((string) ($working[$bestTargetIndex]['rehome_note'] ?? ''));
            $note = 'Absorbed '.count($peel['member_ids']).' related keywords';
            $working[$bestTargetIndex]['rehome_note'] = $existingNote !== '' ? $existingNote.'; '.$note : $note;
            $working[$peelIndex]['absorbed'] = true;
            $rehomed++;
        }

        $final = [];
        foreach ($working as $draft) {
            if (($draft['absorbed'] ?? false) === true) {
                continue;
            }
            if (count($draft['member_ids']) >= 2) {
                $final[] = $draft;
            }
        }

        return [
            'drafts' => $final,
            'subgroups_rehomed' => $rehomed,
        ];
    }

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string}>  $drafts
     * @param  array<int, array<int, float>>  $similarity
     * @return list<array{
     *     left_label: string,
     *     right_label: string,
     *     medoid_similarity: float,
     *     cross_average: float,
     *     merged_cohesion: float,
     *     decision: string,
     *     reason: string,
     * }>
     */
    public function detectDuplicatePairs(
        array $drafts,
        array $similarity,
        array $profileMap,
        string $strategy,
    ): array {
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);
        $pairs = [];
        $count = count($drafts);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $drafts[$i]['member_ids'] ?? [];
                $right = $drafts[$j]['member_ids'] ?? [];
                if ($left === [] || $right === []) {
                    continue;
                }

                $leftMedoid = KeywordClusterSimilarityMatrix::medoid($left, $similarity);
                $rightMedoid = KeywordClusterSimilarityMatrix::medoid($right, $similarity);
                $medoidSim = $similarity[$leftMedoid][$rightMedoid] ?? 0.0;
                $cross = KeywordClusterSimilarityMatrix::averageCrossSimilarity($left, $right, $similarity);
                $merged = array_values(array_unique([...$left, ...$right]));
                $mergedCohesion = KeywordClusterSimilarityMatrix::cohesion($merged, $similarity);

                if ($medoidSim < (float) $qualityThresholds['duplicate_pair_medoid_min']) {
                    continue;
                }

                $decision = 'POTENTIAL_DUPLICATE';
                $reason = 'High medoid/cross similarity';
                if ($this->acceptRehome(
                    peelIds: $left,
                    targetIds: $right,
                    similarity: $similarity,
                    profileMap: $profileMap,
                    strategy: $strategy,
                    qualityThresholds: $qualityThresholds,
                    cohesionThreshold: $thresholds['cohesion'],
                )) {
                    $decision = 'WOULD_MERGE';
                    $reason = 'Direct cohesive merge evidence';
                } elseif ($mergedCohesion < $thresholds['cohesion']) {
                    $decision = 'KEPT_SEPARATE';
                    $reason = 'Merged cohesion too weak';
                }

                $pairs[] = [
                    'left_label' => $profileMap[$leftMedoid]->phrase ?? (string) $leftMedoid,
                    'right_label' => $profileMap[$rightMedoid]->phrase ?? (string) $rightMedoid,
                    'medoid_similarity' => round($medoidSim, 3),
                    'cross_average' => round($cross, 3),
                    'merged_cohesion' => round($mergedCohesion, 3),
                    'decision' => $decision,
                    'reason' => $reason,
                ];
            }
        }

        usort(
            $pairs,
            static fn (array $a, array $b): int => ($b['medoid_similarity'] <=> $a['medoid_similarity'])
                ?: strcmp((string) $a['left_label'], (string) $b['left_label']),
        );

        return $pairs;
    }

    /**
     * @param  list<int>  $peelIds
     * @param  list<int>  $targetIds
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function acceptRehome(
        array $peelIds,
        array $targetIds,
        array $similarity,
        array $profileMap,
        string $strategy,
        array $qualityThresholds,
        float $cohesionThreshold,
    ): bool {
        $merged = array_values(array_unique([...$targetIds, ...$peelIds]));
        if (count($merged) > (int) $qualityThresholds['rehome_max_merged_size']) {
            return false;
        }

        $leftMedoid = KeywordClusterSimilarityMatrix::medoid($peelIds, $similarity);
        $rightMedoid = KeywordClusterSimilarityMatrix::medoid($targetIds, $similarity);
        $medoidSim = $similarity[$leftMedoid][$rightMedoid] ?? 0.0;
        if ($medoidSim < (float) $qualityThresholds['rehome_medoid_min']) {
            return false;
        }

        $memberAvg = $this->averageMemberToClusterSimilarity($peelIds, $targetIds, $similarity);
        $memberMin = (float) $qualityThresholds['rehome_member_avg_min'];
        if ($memberAvg < $memberMin && $medoidSim < ((float) $qualityThresholds['rehome_medoid_min'] + 0.18)) {
            return false;
        }
        if ($memberAvg < ($memberMin - 0.06)) {
            return false;
        }

        $mergedCohesion = KeywordClusterSimilarityMatrix::cohesion($merged, $similarity);
        if ($mergedCohesion < max(
            $cohesionThreshold - 0.03,
            (float) $qualityThresholds['rehome_merged_cohesion_min'],
        )) {
            return false;
        }

        $sharedAnchors = KeywordClusterLocalAnchorSupport::sharedDiscriminativeAnchors(
            $peelIds,
            $targetIds,
            $profileMap,
            (float) $qualityThresholds['broad_family_coverage'],
        );
        if ($sharedAnchors === []) {
            return false;
        }

        $mergedQuality = $this->analyzer->analyze($merged, $similarity, $profileMap, $strategy);
        $targetQuality = $this->analyzer->analyze($targetIds, $similarity, $profileMap, $strategy);
        if ($mergedQuality->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
            && $targetQuality->qualityState !== KeywordClusterQualityMetrics::STATE_MEGA
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<int>  $peelIds
     * @param  list<int>  $targetIds
     * @param  array<int, array<int, float>>  $similarity
     */
    private function rehomeScore(array $peelIds, array $targetIds, array $similarity): float
    {
        $leftMedoid = KeywordClusterSimilarityMatrix::medoid($peelIds, $similarity);
        $rightMedoid = KeywordClusterSimilarityMatrix::medoid($targetIds, $similarity);
        $medoidSim = $similarity[$leftMedoid][$rightMedoid] ?? 0.0;
        $memberAvg = $this->averageMemberToClusterSimilarity($peelIds, $targetIds, $similarity);
        $mergedCohesion = KeywordClusterSimilarityMatrix::cohesion(
            array_values(array_unique([...$targetIds, ...$peelIds])),
            $similarity,
        );

        return ($medoidSim * 0.35) + ($memberAvg * 0.40) + ($mergedCohesion * 0.25);
    }

    /**
     * @param  list<int>  $peelIds
     * @param  list<int>  $targetIds
     * @param  array<int, array<int, float>>  $similarity
     */
    private function averageMemberToClusterSimilarity(array $peelIds, array $targetIds, array $similarity): float
    {
        if ($peelIds === [] || $targetIds === []) {
            return 0.0;
        }

        $sum = 0.0;
        $pairs = 0;
        foreach ($peelIds as $peelId) {
            foreach ($targetIds as $targetId) {
                $sum += $similarity[$peelId][$targetId] ?? 0.0;
                $pairs++;
            }
        }

        return $pairs > 0 ? $sum / $pairs : 0.0;
    }

    /**
     * @param  array{member_ids: list<int>, split_from_label: ?string}  $peel
     * @param  array{member_ids: list<int>, split_from_label: ?string}  $target
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     */
    private function isLikelyCoreRemainder(
        array $peel,
        array $target,
        array $similarity,
        array $profileMap,
    ): bool {
        $splitFrom = trim((string) ($peel['split_from_label'] ?? ''));
        if ($splitFrom === '') {
            return false;
        }

        if (count($target['member_ids']) <= count($peel['member_ids'])) {
            return false;
        }

        $targetMedoid = KeywordClusterSimilarityMatrix::medoid($target['member_ids'], $similarity);
        $targetLabel = mb_strtolower($profileMap[$targetMedoid]->phrase ?? '', 'UTF-8');
        $splitFolded = mb_strtolower($splitFrom, 'UTF-8');

        if ($targetLabel === $splitFolded || str_contains($targetLabel, $splitFolded)) {
            return true;
        }

        $peelMedoid = KeywordClusterSimilarityMatrix::medoid($peel['member_ids'], $similarity);
        $medoidSim = $similarity[$peelMedoid][$targetMedoid] ?? 0.0;

        return $medoidSim >= 0.62 && count($target['member_ids']) >= (count($peel['member_ids']) * 3);
    }
}
