<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterDuplicateResolver
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
     *     strong_merges: int,
     *     merge_log: list<array<string, mixed>>,
     *     potential_pairs: list<array<string, mixed>>,
     * }
     */
    public function resolve(
        array $drafts,
        array $similarity,
        array $profileMap,
        string $strategy,
        ?KeywordClusterLineageLedger $ledger = null,
    ): array {
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);

        /** @var list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool, rehome_note?: ?string}> $working */
        $working = array_map(static fn (array $draft): array => $draft, $drafts);
        $mergeLog = [];
        $potentialPairs = [];
        $strongMerges = 0;

        $pairs = $this->rankPairs($working, $similarity, $profileMap, $strategy, $qualityThresholds, $thresholds);

        foreach ($pairs as $pair) {
            $leftIndex = $pair['left_index'];
            $rightIndex = $pair['right_index'];

            if (($working[$leftIndex]['absorbed'] ?? false) === true
                || ($working[$rightIndex]['absorbed'] ?? false) === true
            ) {
                continue;
            }

            $leftIds = $working[$leftIndex]['member_ids'];
            $rightIds = $working[$rightIndex]['member_ids'];
            if ($leftIds === [] || $rightIds === []) {
                continue;
            }

            if ($pair['classification'] === 'STRONG_DUPLICATE' && $pair['merge_decision'] === 'MERGE') {
                $targetIndex = count($leftIds) >= count($rightIds) ? $leftIndex : $rightIndex;
                $sourceIndex = $targetIndex === $leftIndex ? $rightIndex : $leftIndex;

                $merged = array_values(array_unique([
                    ...$working[$targetIndex]['member_ids'],
                    ...$working[$sourceIndex]['member_ids'],
                ]));
                sort($merged, SORT_NUMERIC);

                $absorbedIds = $working[$sourceIndex]['member_ids'];
                $working[$targetIndex]['member_ids'] = $merged;
                $existingNote = trim((string) ($working[$targetIndex]['rehome_note'] ?? ''));
                $note = 'Strong duplicate merge +'.count($absorbedIds);
                $working[$targetIndex]['rehome_note'] = $existingNote !== '' ? $existingNote.'; '.$note : $note;
                $working[$sourceIndex]['absorbed'] = true;
                $strongMerges++;

                $mergeLog[] = $pair;
                if ($ledger !== null) {
                    $ledger->record(
                        KeywordClusterLineageLedger::EVENT_DUPLICATE_MERGE,
                        $absorbedIds,
                        [
                            'into_label' => $pair['left_label'],
                            'pair' => $pair['left_label'].' <> '.$pair['right_label'],
                        ],
                    );
                }

                continue;
            }

            if ($pair['classification'] === 'POTENTIAL_DUPLICATE') {
                $potentialPairs[] = $pair;
            }
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
            'strong_merges' => $strongMerges,
            'merge_log' => $mergeLog,
            'potential_pairs' => $potentialPairs,
        ];
    }

    /**
     * @param  list<array{member_ids: list<int>}>  $working
     * @param  array<string, float|int>  $qualityThresholds
     * @param  array{cohesion: float}  $thresholds
     * @return list<array<string, mixed>>
     */
    public function rankPairs(
        array $working,
        array $similarity,
        array $profileMap,
        string $strategy,
        array $qualityThresholds,
        array $thresholds,
    ): array {
        $pairs = [];
        $count = count($working);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (($working[$i]['absorbed'] ?? false) === true || ($working[$j]['absorbed'] ?? false) === true) {
                    continue;
                }

                $left = $working[$i]['member_ids'];
                $right = $working[$j]['member_ids'];
                if ($left === [] || $right === []) {
                    continue;
                }

                $analysis = $this->analyzePair(
                    leftIds: $left,
                    rightIds: $right,
                    leftIndex: $i,
                    rightIndex: $j,
                    similarity: $similarity,
                    profileMap: $profileMap,
                    strategy: $strategy,
                    qualityThresholds: $qualityThresholds,
                    cohesionThreshold: $thresholds['cohesion'],
                );

                if ($analysis !== null) {
                    $pairs[] = $analysis;
                }
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
     * @param  list<int>  $leftIds
     * @param  list<int>  $rightIds
     * @param  array<string, float|int>  $qualityThresholds
     * @return array<string, mixed>|null
     */
    public function analyzePair(
        array $leftIds,
        array $rightIds,
        int $leftIndex,
        int $rightIndex,
        array $similarity,
        array $profileMap,
        string $strategy,
        array $qualityThresholds,
        float $cohesionThreshold,
    ): ?array {
        $leftMedoid = KeywordClusterSimilarityMatrix::medoid($leftIds, $similarity);
        $rightMedoid = KeywordClusterSimilarityMatrix::medoid($rightIds, $similarity);
        $medoidSim = $similarity[$leftMedoid][$rightMedoid] ?? 0.0;
        $cross = KeywordClusterSimilarityMatrix::averageCrossSimilarity($leftIds, $rightIds, $similarity);
        $merged = array_values(array_unique([...$leftIds, ...$rightIds]));
        $mergedCohesion = KeywordClusterSimilarityMatrix::cohesion($merged, $similarity);
        $mergedQuality = $this->analyzer->analyze($merged, $similarity, $profileMap, $strategy);
        $leftQuality = $this->analyzer->analyze($leftIds, $similarity, $profileMap, $strategy);
        $rightQuality = $this->analyzer->analyze($rightIds, $similarity, $profileMap, $strategy);

        if ($medoidSim < (float) $qualityThresholds['duplicate_pair_medoid_min']) {
            return null;
        }

        $sharedAnchors = KeywordClusterLocalAnchorSupport::sharedDiscriminativeAnchors(
            $leftIds,
            $rightIds,
            $profileMap,
            (float) $qualityThresholds['broad_family_coverage'],
        );

        $classification = 'POTENTIAL_DUPLICATE';
        $mergeDecision = 'KEEP_SEPARATE';
        $reason = 'Moderate similarity';

        $veryStrongMedoid = $medoidSim >= 0.95;
        $strongMedoid = $medoidSim >= (float) $qualityThresholds['strong_duplicate_medoid_min'];
        $strongCross = $cross >= (float) $qualityThresholds['strong_duplicate_cross_min']
            || ($veryStrongMedoid && $cross >= 0.42);
        $strongP25 = $mergedQuality->p25Similarity >= (float) $qualityThresholds['strong_duplicate_merged_p25_min']
            || ($veryStrongMedoid && $mergedQuality->p25Similarity >= 0.34);
        $strongCohesion = $mergedCohesion >= max(
            $cohesionThreshold - 0.02,
            (float) $qualityThresholds['rehome_merged_cohesion_min'],
        );
        $sizeOk = count($merged) <= (int) $qualityThresholds['rehome_max_merged_size'];
        $notIntoWeakMega = ! $this->wouldMergeIntoWeakMega($leftQuality, $rightQuality, $mergedQuality);
        $anchorOk = $sharedAnchors !== []
            || ($veryStrongMedoid && $strongCohesion);

        if ($strongMedoid && $strongCross && $strongP25 && $strongCohesion && $anchorOk && $sizeOk && $notIntoWeakMega) {
            $classification = 'STRONG_DUPLICATE';
            $mergeDecision = 'MERGE';
            $reason = 'Strong medoid/cross/cohesion evidence';
        } elseif ($mergedCohesion < $cohesionThreshold) {
            $reason = 'Merged cohesion too weak';
        } elseif ($sharedAnchors === []) {
            $reason = 'No shared discriminative anchors';
        } elseif (! $notIntoWeakMega) {
            $reason = 'Would merge into weak mega cluster';
        }

        return [
            'left_index' => $leftIndex,
            'right_index' => $rightIndex,
            'left_label' => $profileMap[$leftMedoid]->phrase ?? (string) $leftMedoid,
            'right_label' => $profileMap[$rightMedoid]->phrase ?? (string) $rightMedoid,
            'medoid_similarity' => round($medoidSim, 3),
            'cross_average' => round($cross, 3),
            'merged_cohesion' => round($mergedCohesion, 3),
            'merged_p25' => round($mergedQuality->p25Similarity, 3),
            'merged_quality' => $mergedQuality->qualityState,
            'classification' => $classification,
            'merge_decision' => $mergeDecision,
            'decision' => $classification === 'STRONG_DUPLICATE' ? 'STRONG_DUPLICATE' : 'POTENTIAL_DUPLICATE',
            'reason' => $reason,
        ];
    }

    private function wouldMergeIntoWeakMega(
        KeywordClusterQualityMetrics $left,
        KeywordClusterQualityMetrics $right,
        KeywordClusterQualityMetrics $merged,
    ): bool {
        $targetIsMega = $left->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
            || $right->qualityState === KeywordClusterQualityMetrics::STATE_MEGA;

        if (! $targetIsMega) {
            return false;
        }

        if ($merged->qualityState !== KeywordClusterQualityMetrics::STATE_MEGA) {
            return false;
        }

        $bothMega = $left->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
            && $right->qualityState === KeywordClusterQualityMetrics::STATE_MEGA;

        if ($bothMega && $merged->averageSimilarity >= max($left->averageSimilarity, $right->averageSimilarity)) {
            return false;
        }

        return $merged->averageSimilarity < max($left->averageSimilarity, $right->averageSimilarity) + 0.02;
    }
}
