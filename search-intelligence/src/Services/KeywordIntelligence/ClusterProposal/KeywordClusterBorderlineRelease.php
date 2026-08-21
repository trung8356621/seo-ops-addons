<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterBorderlineRelease
{
    public function __construct(
        private readonly KeywordClusterQualityAnalyzer $analyzer,
    ) {}

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool}>  $drafts
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array{drafts: list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool}>, released_member_ids: list<int>}
     */
    public function release(
        array $drafts,
        array $similarity,
        array $profileMap,
        string $strategy,
    ): array {
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);
        $released = [];
        $output = [];

        foreach ($drafts as $draft) {
            if (($draft['absorbed'] ?? false) === true) {
                continue;
            }

            $memberIds = $draft['member_ids'];
            if (count($memberIds) < 3) {
                $output[] = $draft;

                continue;
            }

            $quality = $this->analyzer->analyze($memberIds, $similarity, $profileMap, $strategy);
            if (! $this->shouldRelease($quality)) {
                $output[] = $draft;

                continue;
            }

            $maxRelease = (int) max(
                1,
                min(
                    (int) $qualityThresholds['release_max_count'],
                    (int) floor(count($memberIds) * (float) $qualityThresholds['release_max_ratio']),
                ),
            );

            $remaining = $memberIds;
            $releasedFromCluster = 0;
            $baseline = $quality;

            while ($releasedFromCluster < $maxRelease && count($remaining) >= 3) {
                $weakestId = $this->weakestMember($remaining, $similarity);
                if ($weakestId === null) {
                    break;
                }

                $candidate = array_values(array_filter(
                    $remaining,
                    static fn (int $id): bool => $id !== $weakestId,
                ));

                if (count($candidate) < 2) {
                    break;
                }

                $candidateQuality = $this->analyzer->analyze($candidate, $similarity, $profileMap, $strategy);
                if (! $this->qualityImproved($baseline, $candidateQuality, $thresholds['cohesion'])) {
                    break;
                }

                $released[] = $weakestId;
                $releasedFromCluster++;
                $remaining = $candidate;
                $baseline = $candidateQuality;

                if ($this->isHealthyEnough($candidateQuality, $qualityThresholds)) {
                    break;
                }
            }

            if (count($remaining) >= 2) {
                $draft['member_ids'] = array_values($remaining);
                $output[] = $draft;
            } elseif (count($remaining) === 1) {
                $released[] = $remaining[0];
            }
        }

        sort($released, SORT_NUMERIC);

        return [
            'drafts' => $output,
            'released_member_ids' => $released,
        ];
    }

    private function shouldRelease(KeywordClusterQualityMetrics $quality): bool
    {
        return in_array($quality->qualityState, [
            KeywordClusterQualityMetrics::STATE_LOOSE,
            KeywordClusterQualityMetrics::STATE_MEGA,
        ], true);
    }

    /**
     * @param  list<int>  $memberIds
     * @param  array<int, array<int, float>>  $similarity
     */
    private function weakestMember(array $memberIds, array $similarity): ?int
    {
        if ($memberIds === []) {
            return null;
        }

        $medoidId = KeywordClusterSimilarityMatrix::medoid($memberIds, $similarity);
        $repSimilarities = KeywordClusterSimilarityMatrix::representativeSimilarities(
            $medoidId,
            $memberIds,
            $similarity,
        );

        $weakestId = $memberIds[0];
        $weakestScore = $repSimilarities[$weakestId] ?? 0.0;
        foreach ($memberIds as $memberId) {
            $score = $repSimilarities[$memberId] ?? 0.0;
            if ($score < $weakestScore || ($score === $weakestScore && $memberId < $weakestId)) {
                $weakestScore = $score;
                $weakestId = $memberId;
            }
        }

        return $weakestId;
    }

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function qualityImproved(
        KeywordClusterQualityMetrics $before,
        KeywordClusterQualityMetrics $after,
        float $cohesionThreshold,
    ): bool {
        if ($after->averageSimilarity <= $before->averageSimilarity + 0.008) {
            return false;
        }

        if ($after->p25Similarity <= $before->p25Similarity + 0.006) {
            return false;
        }

        if ($after->borderlineMemberCount >= $before->borderlineMemberCount) {
            return false;
        }

        if ($after->minimumSimilarity <= $before->minimumSimilarity) {
            return false;
        }

        if ($before->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
            && $after->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
            && $after->memberCount >= (int) ($before->memberCount * 0.92)
        ) {
            return $after->averageSimilarity >= $before->averageSimilarity + 0.015;
        }

        return $after->averageSimilarity >= $cohesionThreshold - 0.04
            || $after->qualityState !== $before->qualityState;
    }

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function isHealthyEnough(KeywordClusterQualityMetrics $quality, array $qualityThresholds): bool
    {
        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_COMPACT) {
            return true;
        }

        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_ACCEPTABLE) {
            return true;
        }

        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_MEGA) {
            return $quality->averageSimilarity >= (float) $qualityThresholds['compact_avg'] - 0.04
                && $quality->p25Similarity >= (float) $qualityThresholds['compact_p25'] - 0.04;
        }

        return false;
    }
}
