<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class KeywordClusterQualityMetrics
{
    public const STATE_COMPACT = 'COMPACT';

    public const STATE_ACCEPTABLE = 'ACCEPTABLE';

    public const STATE_LOOSE = 'LOOSE';

    public const STATE_MEGA = 'MEGA';

    /**
     * @param  array<int, float>  $representativeSimilarities
     */
    public function __construct(
        public int $memberCount,
        public float $averageSimilarity,
        public float $minimumSimilarity,
        public float $p25Similarity,
        public float $medianSimilarity,
        public float $representativeAverageSimilarity,
        public float $representativeMinSimilarity,
        public int $coreMemberCount,
        public int $borderlineMemberCount,
        public string $qualityState,
        public array $representativeSimilarities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_count' => $this->memberCount,
            'average_similarity' => round($this->averageSimilarity, 3),
            'minimum_similarity' => round($this->minimumSimilarity, 3),
            'p25_similarity' => round($this->p25Similarity, 3),
            'median_similarity' => round($this->medianSimilarity, 3),
            'representative_average_similarity' => round($this->representativeAverageSimilarity, 3),
            'representative_min_similarity' => round($this->representativeMinSimilarity, 3),
            'core_member_count' => $this->coreMemberCount,
            'borderline_member_count' => $this->borderlineMemberCount,
            'quality_state' => $this->qualityState,
        ];
    }
}
