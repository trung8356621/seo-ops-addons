<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterProposalFinalStatus
{
    public const READY = 'READY';

    public const NEEDS_REVIEW = 'NEEDS_REVIEW';

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    public static function resolve(KeywordClusterQualityMetrics $quality, array $qualityThresholds): string
    {
        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_COMPACT) {
            return self::READY;
        }

        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_ACCEPTABLE) {
            return self::READY;
        }

        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_MEGA) {
            $cohesiveMega = $quality->averageSimilarity >= ((float) $qualityThresholds['compact_avg'] - 0.05)
                && $quality->p25Similarity >= ((float) $qualityThresholds['compact_p25'] - 0.03)
                && $quality->borderlineMemberCount / max(1, $quality->memberCount) < (float) $qualityThresholds['compact_borderline_ratio'];

            return $cohesiveMega ? self::READY : self::NEEDS_REVIEW;
        }

        return self::NEEDS_REVIEW;
    }
}
