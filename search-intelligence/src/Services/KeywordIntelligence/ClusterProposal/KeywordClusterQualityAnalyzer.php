<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterQualityAnalyzer
{
    /**
     * @param  list<int>  $memberIds
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     */
    public function analyze(
        array $memberIds,
        array $similarity,
        array $profileMap,
        string $strategy,
    ): KeywordClusterQualityMetrics {
        sort($memberIds, SORT_NUMERIC);
        $memberCount = count($memberIds);

        if ($memberCount < 2) {
            return new KeywordClusterQualityMetrics(
                memberCount: $memberCount,
                averageSimilarity: 1.0,
                minimumSimilarity: 1.0,
                p25Similarity: 1.0,
                medianSimilarity: 1.0,
                representativeAverageSimilarity: 1.0,
                representativeMinSimilarity: 1.0,
                coreMemberCount: $memberCount,
                borderlineMemberCount: 0,
                qualityState: KeywordClusterQualityMetrics::STATE_COMPACT,
                representativeSimilarities: [],
            );
        }

        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);

        $medoidId = KeywordClusterSimilarityMatrix::medoid($memberIds, $similarity);
        $averageSimilarity = KeywordClusterSimilarityMatrix::cohesion($memberIds, $similarity);
        $minimumSimilarity = KeywordClusterSimilarityMatrix::minPairSimilarity($memberIds, $similarity);
        $representativeSimilarities = KeywordClusterSimilarityMatrix::representativeSimilarities(
            $medoidId,
            $memberIds,
            $similarity,
        );

        $repValues = array_values($representativeSimilarities);
        $p25 = KeywordClusterSimilarityMatrix::percentile($repValues, 25.0);
        $median = KeywordClusterSimilarityMatrix::percentile($repValues, 50.0);
        $repAverage = array_sum($repValues) / max(1, count($repValues));
        $repMin = min($repValues);

        $coreThreshold = max(
            $thresholds['member'],
            $median - $qualityThresholds['core_median_offset'],
        );
        $borderlineThreshold = $thresholds['member'] - $qualityThresholds['borderline_member_offset'];

        $coreCount = 0;
        $borderlineCount = 0;
        foreach ($memberIds as $memberId) {
            $repSim = $representativeSimilarities[$memberId] ?? 0.0;
            if ($repSim >= $coreThreshold) {
                $coreCount++;
            } elseif ($repSim >= $borderlineThreshold) {
                $borderlineCount++;
            } else {
                $borderlineCount++;
            }
        }

        $borderlineRatio = $borderlineCount / max(1, $memberCount);
        $qualityState = $this->resolveQualityState(
            memberCount: $memberCount,
            averageSimilarity: $averageSimilarity,
            p25: $p25,
            minimumSimilarity: $minimumSimilarity,
            borderlineRatio: $borderlineRatio,
            cohesionThreshold: $thresholds['cohesion'],
            qualityThresholds: $qualityThresholds,
        );

        return new KeywordClusterQualityMetrics(
            memberCount: $memberCount,
            averageSimilarity: $averageSimilarity,
            minimumSimilarity: $minimumSimilarity,
            p25Similarity: $p25,
            medianSimilarity: $median,
            representativeAverageSimilarity: round($repAverage, 6),
            representativeMinSimilarity: round($repMin, 6),
            coreMemberCount: $coreCount,
            borderlineMemberCount: $borderlineCount,
            qualityState: $qualityState,
            representativeSimilarities: $representativeSimilarities,
        );
    }

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function resolveQualityState(
        int $memberCount,
        float $averageSimilarity,
        float $p25,
        float $minimumSimilarity,
        float $borderlineRatio,
        float $cohesionThreshold,
        array $qualityThresholds,
    ): string {
        $isCompact = $averageSimilarity >= (float) $qualityThresholds['compact_avg']
            && $p25 >= (float) $qualityThresholds['compact_p25']
            && $borderlineRatio < (float) $qualityThresholds['compact_borderline_ratio'];

        if ($isCompact) {
            return KeywordClusterQualityMetrics::STATE_COMPACT;
        }

        $isLoose = $p25 < (float) $qualityThresholds['loose_p25']
            || $borderlineRatio > (float) $qualityThresholds['loose_borderline_ratio']
            || $minimumSimilarity < ($cohesionThreshold - (float) $qualityThresholds['loose_min_pair_gap']);

        $isMega = $memberCount >= (int) $qualityThresholds['mega_min_members']
            && (
                $isLoose
                || (
                    $p25 < ((float) $qualityThresholds['loose_p25'] + (float) $qualityThresholds['mega_p25_buffer'])
                    && $borderlineRatio > (float) $qualityThresholds['mega_borderline_ratio']
                )
            );

        if ($isMega) {
            return KeywordClusterQualityMetrics::STATE_MEGA;
        }

        if ($isLoose) {
            return KeywordClusterQualityMetrics::STATE_LOOSE;
        }

        return KeywordClusterQualityMetrics::STATE_ACCEPTABLE;
    }
}
