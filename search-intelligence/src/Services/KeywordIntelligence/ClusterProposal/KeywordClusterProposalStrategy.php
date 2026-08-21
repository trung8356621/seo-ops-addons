<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final class KeywordClusterProposalStrategy
{
    public const STRICT = 'strict';

    public const BALANCED = 'balanced';

    public const BROAD = 'broad';

    /**
     * @return array{member: float, cohesion: float, ambiguous_penalty: float}
     */
    public static function thresholds(string $strategy): array
    {
        return match (self::normalize($strategy)) {
            self::STRICT => [
                'member' => 0.52,
                'cohesion' => 0.46,
                'ambiguous_penalty' => 0.10,
            ],
            self::BROAD => [
                'member' => 0.34,
                'cohesion' => 0.30,
                'ambiguous_penalty' => 0.08,
            ],
            default => [
                'member' => 0.42,
                'cohesion' => 0.37,
                'ambiguous_penalty' => 0.08,
            ],
        };
    }

    public static function normalize(string $strategy): string
    {
        $value = mb_strtolower(trim($strategy), 'UTF-8');

        return in_array($value, [self::STRICT, self::BALANCED, self::BROAD], true)
            ? $value
            : self::BALANCED;
    }

    public static function label(string $strategy): string
    {
        return match (self::normalize($strategy)) {
            self::STRICT => __('seo-content-ai::filament.keyword.topic_proposal_strategy_strict'),
            self::BROAD => __('seo-content-ai::filament.keyword.topic_proposal_strategy_broad'),
            default => __('seo-content-ai::filament.keyword.topic_proposal_strategy_balanced'),
        };
    }

    /**
     * Post-cluster quality guard thresholds (does not alter Phase 1 member/cohesion gates).
     *
     * @return array<string, float|int>
     */
    public static function qualityThresholds(string $strategy): array
    {
        return match (self::normalize($strategy)) {
            self::STRICT => [
                'compact_avg' => 0.60,
                'compact_p25' => 0.50,
                'compact_borderline_ratio' => 0.28,
                'loose_p25' => 0.38,
                'loose_borderline_ratio' => 0.35,
                'loose_min_pair_gap' => 0.06,
                'mega_min_members' => 12,
                'mega_p25_buffer' => 0.03,
                'mega_borderline_ratio' => 0.32,
                'core_median_offset' => 0.06,
                'borderline_member_offset' => 0.04,
                'split_min_members' => 6,
                'split_improvement_min' => 0.12,
                'split_cross_gap_min' => 0.08,
                'mega_split_improvement_min' => 0.06,
                'mega_split_cross_gap_min' => 0.04,
                'mega_subgroup_max_ratio' => 0.40,
                'mega_subgroup_cohesion_floor' => 0.48,
                'mega_max_peel_subgroups' => 3,
                'mega_peel_cohesion_lift' => 0.10,
                'borderline_peel_ratio' => 0.45,
                'anchor_min_coverage' => 0.08,
                'anchor_max_coverage' => 0.72,
                'anchor_max_token_frequency' => 0.68,
                'subgroup_cohesion_relax' => 0.04,
                'duplicate_merge_cross_margin' => 0.04,
                'max_residual_split_depth' => 2,
                'residual_improvement_min' => 0.012,
                'release_max_ratio' => 0.10,
                'release_max_count' => 6,
                'rehome_medoid_min' => 0.54,
                'rehome_member_avg_min' => 0.46,
                'rehome_merged_cohesion_min' => 0.40,
                'rehome_max_merged_size' => 20,
                'duplicate_pair_medoid_min' => 0.58,
                'strong_duplicate_medoid_min' => 0.90,
                'strong_duplicate_cross_min' => 0.52,
                'strong_duplicate_merged_p25_min' => 0.42,
                'competitive_improvement_margin' => 0.09,
                'competitive_absolute_floor' => 0.46,
                'competitive_max_passes' => 2,
                'competitive_medoid_weight' => 0.30,
                'competitive_avg_weight' => 0.40,
                'competitive_anchor_weight' => 0.30,
                'broad_family_coverage' => 0.65,
            ],
            self::BROAD => [
                'compact_avg' => 0.54,
                'compact_p25' => 0.42,
                'compact_borderline_ratio' => 0.38,
                'loose_p25' => 0.28,
                'loose_borderline_ratio' => 0.52,
                'loose_min_pair_gap' => 0.10,
                'mega_min_members' => 24,
                'mega_p25_buffer' => 0.05,
                'mega_borderline_ratio' => 0.45,
                'core_median_offset' => 0.10,
                'borderline_member_offset' => 0.06,
                'split_min_members' => 8,
                'split_improvement_min' => 0.08,
                'split_cross_gap_min' => 0.05,
                'mega_split_improvement_min' => 0.05,
                'mega_split_cross_gap_min' => 0.03,
                'mega_subgroup_max_ratio' => 0.45,
                'mega_subgroup_cohesion_floor' => 0.40,
                'mega_max_peel_subgroups' => 5,
                'mega_peel_cohesion_lift' => 0.06,
                'borderline_peel_ratio' => 0.50,
                'anchor_min_coverage' => 0.06,
                'anchor_max_coverage' => 0.78,
                'anchor_max_token_frequency' => 0.72,
                'subgroup_cohesion_relax' => 0.06,
                'duplicate_merge_cross_margin' => 0.06,
                'max_residual_split_depth' => 2,
                'residual_improvement_min' => 0.010,
                'release_max_ratio' => 0.14,
                'release_max_count' => 8,
                'rehome_medoid_min' => 0.50,
                'rehome_member_avg_min' => 0.42,
                'rehome_merged_cohesion_min' => 0.36,
                'rehome_max_merged_size' => 24,
                'duplicate_pair_medoid_min' => 0.55,
                'strong_duplicate_medoid_min' => 0.86,
                'strong_duplicate_cross_min' => 0.48,
                'strong_duplicate_merged_p25_min' => 0.38,
                'competitive_improvement_margin' => 0.07,
                'competitive_absolute_floor' => 0.42,
                'competitive_max_passes' => 2,
                'competitive_medoid_weight' => 0.28,
                'competitive_avg_weight' => 0.42,
                'competitive_anchor_weight' => 0.30,
                'broad_family_coverage' => 0.68,
            ],
            default => [
                'compact_avg' => 0.58,
                'compact_p25' => 0.45,
                'compact_borderline_ratio' => 0.32,
                'loose_p25' => 0.32,
                'loose_borderline_ratio' => 0.42,
                'loose_min_pair_gap' => 0.08,
                'mega_min_members' => 18,
                'mega_p25_buffer' => 0.04,
                'mega_borderline_ratio' => 0.38,
                'core_median_offset' => 0.08,
                'borderline_member_offset' => 0.05,
                'split_min_members' => 6,
                'split_improvement_min' => 0.10,
                'split_cross_gap_min' => 0.06,
                'mega_split_improvement_min' => 0.05,
                'mega_split_cross_gap_min' => 0.035,
                'mega_subgroup_max_ratio' => 0.42,
                'mega_subgroup_cohesion_floor' => 0.44,
                'mega_max_peel_subgroups' => 4,
                'mega_peel_cohesion_lift' => 0.08,
                'borderline_peel_ratio' => 0.48,
                'anchor_min_coverage' => 0.03,
                'anchor_max_coverage' => 0.75,
                'anchor_max_token_frequency' => 0.70,
                'subgroup_cohesion_relax' => 0.05,
                'duplicate_merge_cross_margin' => 0.05,
                'max_residual_split_depth' => 2,
                'residual_improvement_min' => 0.010,
                'release_max_ratio' => 0.12,
                'release_max_count' => 8,
                'rehome_medoid_min' => 0.52,
                'rehome_member_avg_min' => 0.40,
                'rehome_merged_cohesion_min' => 0.38,
                'rehome_max_merged_size' => 22,
                'duplicate_pair_medoid_min' => 0.56,
                'strong_duplicate_medoid_min' => 0.88,
                'strong_duplicate_cross_min' => 0.50,
                'strong_duplicate_merged_p25_min' => 0.40,
                'competitive_improvement_margin' => 0.08,
                'competitive_absolute_floor' => 0.44,
                'competitive_max_passes' => 2,
                'competitive_medoid_weight' => 0.30,
                'competitive_avg_weight' => 0.40,
                'competitive_anchor_weight' => 0.30,
                'broad_family_coverage' => 0.65,
            ],
        };
    }
}
