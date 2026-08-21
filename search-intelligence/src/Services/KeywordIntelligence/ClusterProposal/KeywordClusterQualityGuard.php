<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterQualityGuard
{
    public function __construct(
        private readonly KeywordClusterQualityAnalyzer $analyzer,
    ) {}

    /**
     * @param  list<list<int>>  $initialClusters
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array{
     *     clusters: list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, quality: KeywordClusterQualityMetrics}>,
     *     initial_cluster_count: int,
     *     final_cluster_count: int,
     *     loose_clusters_detected: int,
     *     clusters_split_count: int,
     *     largest_initial_cluster_size: int,
     *     largest_final_cluster_size: int,
     * }
     */
    public function refine(
        array $initialClusters,
        array $similarity,
        array $profileMap,
        KeywordClusterCorpusStatistics $corpus,
        string $strategy,
    ): array {
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);

        $output = [];
        $looseDetected = 0;
        $splitCount = 0;
        $subgroupsPeeled = 0;
        $largestInitial = 0;
        $largestFinal = 0;

        foreach ($initialClusters as $memberIds) {
            sort($memberIds, SORT_NUMERIC);
            $largestInitial = max($largestInitial, count($memberIds));

            $quality = $this->analyzer->analyze($memberIds, $similarity, $profileMap, $strategy);
            $splitFromLabel = null;
            $splitReason = null;

            if ($this->shouldAttemptSplit($quality, $qualityThresholds)) {
                $looseDetected++;
                $medoidId = KeywordClusterSimilarityMatrix::medoid($memberIds, $similarity);
                $parentLabel = $profileMap[$medoidId]->phrase ?? '';
                $splitResult = $this->attemptSplit(
                    memberIds: $memberIds,
                    similarity: $similarity,
                    profileMap: $profileMap,
                    corpus: $corpus,
                    strategy: $strategy,
                    quality: $quality,
                    qualityThresholds: $qualityThresholds,
                    cohesionThreshold: $thresholds['cohesion'],
                );

                if ($splitResult !== null) {
                    $splitCount++;
                    $splitFromLabel = $parentLabel;
                    $splitReason = 'loose cohesion + strong subgroups';
                    $largestSplitSize = max(array_map(static fn (array $ids): int => count($ids), $splitResult));
                    foreach ($splitResult as $subgroupIds) {
                        $isCoreRemainder = count($subgroupIds) === $largestSplitSize;
                        if (! $isCoreRemainder) {
                            $subgroupsPeeled++;
                        }
                        $subQuality = $this->analyzer->analyze($subgroupIds, $similarity, $profileMap, $strategy);
                        $output[] = [
                            'member_ids' => $subgroupIds,
                            'split_from_label' => $isCoreRemainder ? null : $splitFromLabel,
                            'split_reason' => $isCoreRemainder ? null : $splitReason,
                            'quality' => $subQuality,
                        ];
                        $largestFinal = max($largestFinal, count($subgroupIds));
                    }

                    continue;
                }
            }

            $output[] = [
                'member_ids' => $memberIds,
                'split_from_label' => $splitFromLabel,
                'split_reason' => $splitReason,
                'quality' => $quality,
            ];
            $largestFinal = max($largestFinal, count($memberIds));
        }

        return [
            'clusters' => $output,
            'initial_cluster_count' => count($initialClusters),
            'final_cluster_count' => count($output),
            'loose_clusters_detected' => $looseDetected,
            'clusters_split_count' => $splitCount,
            'subgroups_peeled' => $subgroupsPeeled,
            'largest_initial_cluster_size' => $largestInitial,
            'largest_final_cluster_size' => $largestFinal,
        ];
    }

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool}>  $drafts
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array{
     *     drafts: list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool}>,
     *     residual_passes: int,
     *     residual_splits: int,
     *     residual_targets: int,
     *     subgroups_peeled: int,
     * }
     */
    public function refineResiduals(
        array $drafts,
        array $similarity,
        array $profileMap,
        KeywordClusterCorpusStatistics $corpus,
        string $strategy,
    ): array {
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);
        $maxDepth = (int) $qualityThresholds['max_residual_split_depth'];
        $output = [];
        $residualPasses = 0;
        $residualSplits = 0;
        $residualTargets = 0;
        $subgroupsPeeled = 0;

        foreach ($drafts as $draft) {
            if (($draft['absorbed'] ?? false) === true) {
                continue;
            }

            $current = $draft;
            $depth = 0;

            while ($depth < $maxDepth) {
                $memberIds = $current['member_ids'];
                $quality = $this->analyzer->analyze($memberIds, $similarity, $profileMap, $strategy);
                if (! $this->shouldAttemptSplit($quality, $qualityThresholds)) {
                    break;
                }

                $residualTargets++;
                $before = $quality;
                $medoidId = KeywordClusterSimilarityMatrix::medoid($memberIds, $similarity);
                $parentLabel = $profileMap[$medoidId]->phrase ?? '';
                $splitResult = $this->attemptSplit(
                    memberIds: $memberIds,
                    similarity: $similarity,
                    profileMap: $profileMap,
                    corpus: $corpus,
                    strategy: $strategy,
                    quality: $quality,
                    qualityThresholds: $qualityThresholds,
                    cohesionThreshold: $thresholds['cohesion'],
                );

                if ($splitResult === null) {
                    break;
                }

                $largestSplitSize = max(array_map(static fn (array $ids): int => count($ids), $splitResult));
                $residualCore = null;
                foreach ($splitResult as $subgroupIds) {
                    if (count($subgroupIds) === $largestSplitSize) {
                        $residualCore = $subgroupIds;

                        continue;
                    }

                    $subgroupsPeeled++;
                    $output[] = [
                        'member_ids' => $subgroupIds,
                        'split_from_label' => $parentLabel,
                        'split_reason' => 'residual decomposition pass '.($depth + 2),
                    ];
                }

                if ($residualCore === null || count($residualCore) < 2) {
                    break;
                }

                $after = $this->analyzer->analyze($residualCore, $similarity, $profileMap, $strategy);
                if (! $this->residualImproved($before, $after, $qualityThresholds)) {
                    foreach ($output as $index => $peel) {
                        if (($peel['split_reason'] ?? '') === 'residual decomposition pass '.($depth + 2)) {
                            unset($output[$index]);
                            $subgroupsPeeled--;
                        }
                    }
                    break;
                }

                $current = [
                    'member_ids' => $residualCore,
                    'split_from_label' => $current['split_from_label'] ?? null,
                    'split_reason' => $current['split_reason'] ?? null,
                ];
                $depth++;
                $residualPasses++;
                $residualSplits++;
            }

            $output[] = $current;
        }

        return [
            'drafts' => array_values($output),
            'residual_passes' => $residualPasses,
            'residual_splits' => $residualSplits,
            'residual_targets' => $residualTargets,
            'subgroups_peeled' => $subgroupsPeeled,
        ];
    }

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function residualImproved(
        KeywordClusterQualityMetrics $before,
        KeywordClusterQualityMetrics $after,
        array $qualityThresholds,
    ): bool {
        if ($after->memberCount >= $before->memberCount) {
            return false;
        }

        if ($after->averageSimilarity < $before->averageSimilarity + (float) $qualityThresholds['residual_improvement_min']) {
            return false;
        }

        if ($after->p25Similarity < $before->p25Similarity + 0.004) {
            return false;
        }

        if ($before->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
            && $after->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
            && $after->memberCount > (int) ($before->memberCount * 0.72)
        ) {
            return $after->borderlineMemberCount < $before->borderlineMemberCount
                || $after->averageSimilarity >= $before->averageSimilarity + 0.02;
        }

        return true;
    }

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function shouldAttemptSplit(KeywordClusterQualityMetrics $quality, array $qualityThresholds): bool
    {
        if ($quality->memberCount < (int) $qualityThresholds['split_min_members']) {
            return false;
        }

        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_COMPACT) {
            return false;
        }

        return in_array($quality->qualityState, [
            KeywordClusterQualityMetrics::STATE_LOOSE,
            KeywordClusterQualityMetrics::STATE_MEGA,
        ], true);
    }

    /**
     * @param  list<int>  $memberIds
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @param  array<string, float|int>  $qualityThresholds
     * @return list<list<int>>|null
     */
    private function attemptSplit(
        array $memberIds,
        array $similarity,
        array $profileMap,
        KeywordClusterCorpusStatistics $corpus,
        string $strategy,
        KeywordClusterQualityMetrics $quality,
        array $qualityThresholds,
        float $cohesionThreshold,
    ): ?array {
        $originalCohesion = $quality->averageSimilarity;
        $isMega = $quality->qualityState === KeywordClusterQualityMetrics::STATE_MEGA;
        $improvementMin = $isMega
            ? (float) $qualityThresholds['mega_split_improvement_min']
            : (float) $qualityThresholds['split_improvement_min'];
        $crossGapMin = $isMega
            ? (float) $qualityThresholds['mega_split_cross_gap_min']
            : (float) $qualityThresholds['split_cross_gap_min'];

        $medoidId = KeywordClusterSimilarityMatrix::medoid($memberIds, $similarity);
        $repSimilarities = KeywordClusterSimilarityMatrix::representativeSimilarities(
            $medoidId,
            $memberIds,
            $similarity,
        );

        $sortedMembers = $memberIds;
        usort(
            $sortedMembers,
            static fn (int $a, int $b): int => ($repSimilarities[$a] ?? 0.0) <=> ($repSimilarities[$b] ?? 0.0),
        );

        $borderlineCut = (int) ceil(count($sortedMembers) * (float) $qualityThresholds['borderline_peel_ratio']);
        $borderlineCut = max(2, min($borderlineCut, count($sortedMembers) - 2));
        $borderlinePool = array_slice($sortedMembers, 0, $borderlineCut);
        $corePool = array_slice($sortedMembers, $borderlineCut);

        $anchors = $this->discoverAnchors($memberIds, $profileMap, $corpus, $qualityThresholds);
        if ($anchors === []) {
            return null;
        }

        $subgroups = $isMega
            ? $this->buildMegaSubgroups($memberIds, $anchors, $similarity, $cohesionThreshold, $qualityThresholds)
            : $this->buildBorderlineSubgroups(
                memberIds: $memberIds,
                borderlinePool: $borderlinePool,
                anchors: $anchors,
                similarity: $similarity,
                cohesionThreshold: $cohesionThreshold,
                qualityThresholds: $qualityThresholds,
            );

        $validSubgroups = array_values(array_filter(
            $subgroups,
            static fn (array $ids): bool => count($ids) >= 2,
        ));

        if (count($validSubgroups) < 2) {
            return null;
        }

        if (! $isMega) {
            $validSubgroups = $this->mergeDuplicateSubgroups($validSubgroups, $similarity, $qualityThresholds);
        }

        if (count($validSubgroups) < 2) {
            return null;
        }

        if (! $this->acceptSplit($validSubgroups, $originalCohesion, $similarity, $improvementMin, $crossGapMin, $isMega)) {
            return null;
        }

        foreach ($validSubgroups as &$subgroup) {
            sort($subgroup, SORT_NUMERIC);
        }
        unset($subgroup);

        return $validSubgroups;
    }

    /**
     * @param  list<int>  $memberIds
     * @param  list<int>  $borderlinePool
     * @param  list<array{anchor: string, score: float, member_ids: list<int>}>  $anchors
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<string, float|int>  $qualityThresholds
     * @return list<list<int>>
     */
    private function buildBorderlineSubgroups(
        array $memberIds,
        array $borderlinePool,
        array $anchors,
        array $similarity,
        float $cohesionThreshold,
        array $qualityThresholds,
    ): array {
        $assigned = [];
        $subgroups = [];

        foreach ($anchors as $anchor) {
            $candidates = [];
            foreach ($anchor['member_ids'] as $memberId) {
                if (isset($assigned[$memberId])) {
                    continue;
                }
                if (! in_array($memberId, $borderlinePool, true)) {
                    continue;
                }
                $candidates[] = $memberId;
            }

            if (count($candidates) < 2) {
                continue;
            }

            $subCohesion = KeywordClusterSimilarityMatrix::cohesion($candidates, $similarity);
            if ($subCohesion < $cohesionThreshold - (float) $qualityThresholds['subgroup_cohesion_relax']) {
                continue;
            }

            foreach ($candidates as $memberId) {
                $assigned[$memberId] = true;
            }
            $subgroups[] = $candidates;
        }

        $remaining = array_values(array_filter(
            $memberIds,
            static fn (int $id): bool => ! isset($assigned[$id]),
        ));

        if (count($remaining) >= 2) {
            $remainingCohesion = KeywordClusterSimilarityMatrix::cohesion($remaining, $similarity);
            if ($remainingCohesion >= $cohesionThreshold - (float) $qualityThresholds['subgroup_cohesion_relax']) {
                $subgroups[] = $remaining;
            }
        }

        return $subgroups;
    }

    /**
     * @param  list<int>  $memberIds
     * @param  list<array{anchor: string, score: float, member_ids: list<int>}>  $anchors
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<string, float|int>  $qualityThresholds
     * @return list<list<int>>
     */
    private function buildMegaSubgroups(
        array $memberIds,
        array $anchors,
        array $similarity,
        float $cohesionThreshold,
        array $qualityThresholds,
    ): array {
        $assigned = [];
        $subgroups = [];
        $maxSubgroupSize = (int) max(4, floor(count($memberIds) * (float) $qualityThresholds['mega_subgroup_max_ratio']));
        $maxPeels = (int) $qualityThresholds['mega_max_peel_subgroups'];

        foreach ($anchors as $anchor) {
            if (count($subgroups) >= $maxPeels) {
                break;
            }

            $candidates = [];
            foreach ($anchor['member_ids'] as $memberId) {
                if (isset($assigned[$memberId])) {
                    continue;
                }
                $candidates[] = $memberId;
            }

            if (count($candidates) < 2 || count($candidates) > $maxSubgroupSize) {
                continue;
            }

            $subCohesion = KeywordClusterSimilarityMatrix::cohesion($candidates, $similarity);
            $megaFloor = (float) $qualityThresholds['mega_subgroup_cohesion_floor'];
            if ($subCohesion < max($megaFloor, $cohesionThreshold + (float) $qualityThresholds['mega_peel_cohesion_lift'])) {
                continue;
            }

            foreach ($candidates as $memberId) {
                $assigned[$memberId] = true;
            }
            $subgroups[] = $candidates;
        }

        $remaining = array_values(array_filter(
            $memberIds,
            static fn (int $id): bool => ! isset($assigned[$id]),
        ));

        if (count($remaining) >= 2) {
            $subgroups[] = $remaining;
        }

        return $subgroups;
    }

    /**
     * @param  list<int>  $memberIds
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @param  array<string, float|int>  $qualityThresholds
     * @return list<array{anchor: string, score: float, member_ids: list<int>}>
     */
    private function discoverAnchors(
        array $memberIds,
        array $profileMap,
        KeywordClusterCorpusStatistics $corpus,
        array $qualityThresholds,
    ): array {
        $clusterSize = count($memberIds);
        $maxCoverage = (float) $qualityThresholds['anchor_max_coverage'];
        $minCoverage = (float) $qualityThresholds['anchor_min_coverage'];
        $maxTokenFrequency = (float) $qualityThresholds['anchor_max_token_frequency'];

        $tokenFrequency = [];
        foreach ($memberIds as $memberId) {
            $profile = $profileMap[$memberId] ?? null;
            if ($profile === null) {
                continue;
            }
            foreach (array_unique([...$profile->tokens, ...$profile->significantTokens]) as $token) {
                $tokenFrequency[(string) $token] = ($tokenFrequency[(string) $token] ?? 0) + 1;
            }
        }

        /** @var array<string, list<int>> $anchorMembers */
        $anchorMembers = [];

        foreach ($memberIds as $memberId) {
            $profile = $profileMap[$memberId] ?? null;
            if ($profile === null) {
                continue;
            }

            $anchors = array_unique(array_merge($profile->bigrams, $profile->significantTokens));
            foreach ($anchors as $anchor) {
                $anchor = trim((string) $anchor);
                if ($anchor === '') {
                    continue;
                }
                $isBigram = str_contains($anchor, ' ');
                if (! $isBigram && $this->anchorHasOverCommonToken($anchor, $tokenFrequency, $clusterSize, $maxTokenFrequency)) {
                    continue;
                }
                $anchorMembers[$anchor][] = $memberId;
            }
        }

        $candidates = [];
        foreach ($anchorMembers as $anchor => $ids) {
            $ids = array_values(array_unique($ids));
            $count = count($ids);
            $coverage = $count / max(1, $clusterSize);
            if ($count < 2 || $coverage > $maxCoverage || $coverage < $minCoverage) {
                continue;
            }

            $idfScore = $this->anchorIdfScore($anchor, $corpus);
            $coveragePenalty = max(0.0, $coverage - 0.5);
            $broadMultiplier = KeywordClusterLocalAnchorSupport::broadSuppressionMultiplier(
                $anchor,
                $tokenFrequency,
                $clusterSize,
                (float) $qualityThresholds['broad_family_coverage'],
            );
            $score = $idfScore * $coverage * (1.0 - $coveragePenalty) * $broadMultiplier;

            $candidates[] = [
                'anchor' => $anchor,
                'score' => round($score, 6),
                'member_ids' => $ids,
            ];
        }

        usort(
            $candidates,
            static function (array $a, array $b): int {
                $scoreCompare = $b['score'] <=> $a['score'];
                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }

                return strcmp((string) $a['anchor'], (string) $b['anchor']);
            },
        );

        return $candidates;
    }

    /**
     * @param  array<string, int>  $tokenFrequency
     */
    private function anchorHasOverCommonToken(
        string $anchor,
        array $tokenFrequency,
        int $clusterSize,
        float $maxTokenFrequency,
    ): bool {
        $tokens = str_contains($anchor, ' ') ? explode(' ', $anchor) : [$anchor];
        foreach ($tokens as $token) {
            $frequency = ($tokenFrequency[$token] ?? 0) / max(1, $clusterSize);
            if ($frequency > $maxTokenFrequency) {
                return true;
            }
        }

        return false;
    }

    private function anchorIdfScore(string $anchor, KeywordClusterCorpusStatistics $corpus): float
    {
        if (str_contains($anchor, ' ')) {
            $tokens = explode(' ', $anchor);
            $sum = 0.0;
            foreach ($tokens as $token) {
                $sum += $corpus->weight($token);
            }

            return $sum / max(1, count($tokens));
        }

        return $corpus->weight($anchor);
    }

    /**
     * @param  list<list<int>>  $subgroups
     * @param  array<int, array<int, float>>  $similarity
     */
    private function acceptSplit(
        array $subgroups,
        float $originalCohesion,
        array $similarity,
        float $improvementMin,
        float $crossGapMin,
        bool $isMega = false,
    ): bool {
        $totalMembers = 0;
        $weightedCohesion = 0.0;
        $internalCohesions = [];

        foreach ($subgroups as $subgroup) {
            $size = count($subgroup);
            $cohesion = KeywordClusterSimilarityMatrix::cohesion($subgroup, $similarity);
            $internalCohesions[] = $cohesion;
            $weightedCohesion += $cohesion * $size;
            $totalMembers += $size;
        }

        $weightedCohesion = $totalMembers > 0 ? $weightedCohesion / $totalMembers : 0.0;

        if ($weightedCohesion < $originalCohesion + $improvementMin) {
            return false;
        }

        $crossSum = 0.0;
        $crossPairs = 0;
        $groupCount = count($subgroups);
        for ($i = 0; $i < $groupCount; $i++) {
            for ($j = $i + 1; $j < $groupCount; $j++) {
                $crossSum += KeywordClusterSimilarityMatrix::averageCrossSimilarity(
                    $subgroups[$i],
                    $subgroups[$j],
                    $similarity,
                );
                $crossPairs++;
            }
        }

        $crossAverage = $crossPairs > 0 ? $crossSum / $crossPairs : 0.0;

        if (($weightedCohesion - $crossAverage) < $crossGapMin && ! $isMega) {
            return false;
        }

        if ($isMega && ($weightedCohesion - $crossAverage) < ($crossGapMin * 0.5)) {
            return false;
        }

        $strongSubgroups = 0;
        foreach ($internalCohesions as $cohesion) {
            if ($cohesion >= $originalCohesion + ($improvementMin * 0.5)) {
                $strongSubgroups++;
            }
        }

        if ($strongSubgroups >= 2) {
            return true;
        }

        if (! $isMega) {
            return false;
        }

        $sizes = array_map(static fn (array $ids): int => count($ids), $subgroups);
        $largestSubgroup = max($sizes);
        $totalMembers = array_sum($sizes);
        $peelSubgroups = count($subgroups) > 1 ? array_slice($subgroups, 0, -1) : [];
        $strongPeels = 0;

        foreach ($peelSubgroups as $subgroup) {
            $cohesion = KeywordClusterSimilarityMatrix::cohesion($subgroup, $similarity);
            if ($cohesion >= $originalCohesion + ($improvementMin * 0.8)) {
                $strongPeels++;
            }
        }

        $core = $subgroups[array_key_last($subgroups)] ?? [];
        $coreCohesion = KeywordClusterSimilarityMatrix::cohesion($core, $similarity);

        return $strongPeels >= 2
            && $largestSubgroup <= (int) floor($totalMembers * 0.58)
            && $coreCohesion >= $originalCohesion - 0.02
            && $weightedCohesion >= $originalCohesion + ($improvementMin * 0.5);
    }

    /**
     * @param  list<list<int>>  $subgroups
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<string, float|int>  $qualityThresholds
     * @return list<list<int>>
     */
    private function mergeDuplicateSubgroups(array $subgroups, array $similarity, array $qualityThresholds): array
    {
        $clusters = $subgroups;
        $merged = true;
        while ($merged) {
            $merged = false;
            $count = count($clusters);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (! isset($clusters[$i], $clusters[$j])) {
                        continue;
                    }

                    $left = $clusters[$i];
                    $right = $clusters[$j];
                    $cross = KeywordClusterSimilarityMatrix::averageCrossSimilarity($left, $right, $similarity);
                    $leftCohesion = KeywordClusterSimilarityMatrix::cohesion($left, $similarity);
                    $rightCohesion = KeywordClusterSimilarityMatrix::cohesion($right, $similarity);
                    $mergeThreshold = min($leftCohesion, $rightCohesion)
                        - (float) $qualityThresholds['duplicate_merge_cross_margin'];

                    if ($cross >= $mergeThreshold) {
                        $combined = array_values(array_unique([...$left, ...$right]));
                        sort($combined, SORT_NUMERIC);
                        $clusters[$i] = $combined;
                        unset($clusters[$j]);
                        $clusters = array_values($clusters);
                        $merged = true;
                        break 2;
                    }
                }
            }
        }

        return $clusters;
    }
}
