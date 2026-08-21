<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterCompetitiveRefiner
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
     *     competitive_moves: int,
     *     move_log: list<array<string, mixed>>,
     * }
     */
    public function refine(
        array $drafts,
        array $similarity,
        array $profileMap,
        string $strategy,
        ?KeywordClusterLineageLedger $ledger = null,
    ): array {
        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);
        $maxPasses = (int) $qualityThresholds['competitive_max_passes'];

        /** @var list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool, rehome_note?: ?string}> $working */
        $working = array_map(static fn (array $draft): array => $draft, $drafts);
        $moveLog = [];
        $reviewLog = [];
        $totalMoves = 0;

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $candidates = $this->buildCandidateMoves($working, $similarity, $profileMap, $strategy, $qualityThresholds, $thresholds);
            if ($candidates === []) {
                break;
            }

            $accepted = 0;
            foreach ($candidates as $candidate) {
                if (! $this->applyMove($working, $candidate, $similarity, $profileMap, $strategy, $qualityThresholds, $thresholds)) {
                    $reviewLog[] = $candidate['log'];

                    continue;
                }

                $accepted++;
                $totalMoves++;
                $moveLog[] = $candidate['log'];
                $reviewLog[] = $candidate['log'];

                if ($ledger !== null) {
                    $ledger->record(
                        KeywordClusterLineageLedger::EVENT_COMPETITIVE_REASSIGN,
                        [$candidate['member_id']],
                        [
                            'from_label' => $candidate['log']['from_label'],
                            'to_label' => $candidate['log']['to_label'],
                            'margin' => $candidate['log']['margin'],
                        ],
                    );
                }
            }

            if ($accepted === 0) {
                break;
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
            'competitive_moves' => $totalMoves,
            'move_log' => $moveLog,
            'review_log' => $reviewLog,
        ];
    }

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool}>  $working
     * @param  array<string, float|int>  $qualityThresholds
     * @param  array{member: float, cohesion: float}  $thresholds
     * @return list<array{member_id: int, source_index: int, target_index: int, margin: float, log: array<string, mixed>}>
     */
    private function buildCandidateMoves(
        array $working,
        array $similarity,
        array $profileMap,
        string $strategy,
        array $qualityThresholds,
        array $thresholds,
    ): array {
        $candidates = [];

        foreach ($working as $sourceIndex => $source) {
            if (($source['absorbed'] ?? false) === true) {
                continue;
            }

            $sourceIds = $source['member_ids'];
            if (count($sourceIds) < 3) {
                continue;
            }

            $sourceQuality = $this->analyzer->analyze($sourceIds, $similarity, $profileMap, $strategy);
            $sourceStatus = KeywordClusterProposalFinalStatus::resolve($sourceQuality, $qualityThresholds);
            if (! $this->isSourceEligible($sourceQuality, $sourceStatus)) {
                continue;
            }

            $sourceMedoid = KeywordClusterSimilarityMatrix::medoid($sourceIds, $similarity);
            $sourceLabel = $profileMap[$sourceMedoid]->phrase ?? (string) $sourceMedoid;

            foreach ($sourceIds as $memberId) {
                $currentFit = $this->clusterFit($memberId, $sourceIds, $similarity, $profileMap, $qualityThresholds);

                $bestTargetIndex = null;
                $bestAltFit = -1.0;
                $bestMargin = -1.0;

                foreach ($working as $targetIndex => $target) {
                    if ($targetIndex === $sourceIndex) {
                        continue;
                    }
                    if (($target['absorbed'] ?? false) === true) {
                        continue;
                    }

                    $targetIds = $target['member_ids'];
                    if ($targetIds === []) {
                        continue;
                    }

                    $targetQuality = $this->analyzer->analyze($targetIds, $similarity, $profileMap, $strategy);
                    $targetStatus = KeywordClusterProposalFinalStatus::resolve($targetQuality, $qualityThresholds);
                    if (! $this->isTargetEligible($targetQuality, $targetStatus, $qualityThresholds)) {
                        continue;
                    }

                    $altFit = $this->clusterFit($memberId, $targetIds, $similarity, $profileMap, $qualityThresholds);
                    $sharedAnchors = KeywordClusterLocalAnchorSupport::sharedDiscriminativeAnchors(
                        [$memberId],
                        $targetIds,
                        $profileMap,
                        (float) $qualityThresholds['broad_family_coverage'],
                    );
                    if ($sharedAnchors !== []) {
                        $altFit += min(0.14, count($sharedAnchors) * 0.045);
                    }
                    $margin = $altFit - $currentFit;
                    if ($altFit > $bestAltFit || ($altFit === $bestAltFit && $margin > $bestMargin)) {
                        $bestAltFit = $altFit;
                        $bestMargin = $margin;
                        $bestTargetIndex = $targetIndex;
                    } elseif ($altFit === $bestAltFit && $margin === $bestMargin && $bestTargetIndex !== null && $targetIndex < $bestTargetIndex) {
                        $bestTargetIndex = $targetIndex;
                    }
                }

                if ($bestTargetIndex === null) {
                    continue;
                }

                $targetIds = $working[$bestTargetIndex]['member_ids'];
                $targetMedoid = KeywordClusterSimilarityMatrix::medoid($targetIds, $similarity);
                $targetLabel = $profileMap[$targetMedoid]->phrase ?? (string) $targetMedoid;

                $candidates[] = [
                    'member_id' => $memberId,
                    'source_index' => $sourceIndex,
                    'target_index' => $bestTargetIndex,
                    'current_fit' => $currentFit,
                    'alternative_fit' => $bestAltFit,
                    'margin' => $bestMargin,
                    'log' => [
                        'phrase' => $profileMap[$memberId]->phrase ?? (string) $memberId,
                        'from_label' => $sourceLabel,
                        'to_label' => $targetLabel,
                        'current_fit' => round($currentFit, 4),
                        'target_fit' => round($bestAltFit, 4),
                        'margin' => round($bestMargin, 4),
                        'decision' => 'PENDING',
                    ],
                ];
            }
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => ($a['current_fit'] <=> $b['current_fit'])
                ?: ($b['margin'] <=> $a['margin'])
                ?: ($a['member_id'] <=> $b['member_id']),
        );

        return $candidates;
    }

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, absorbed?: bool, rehome_note?: ?string}>  $working
     * @param  array{member_id: int, source_index: int, target_index: int, margin: float, log: array<string, mixed>}  $candidate
     * @param  array<string, float|int>  $qualityThresholds
     * @param  array{member: float, cohesion: float}  $thresholds
     */
    private function applyMove(
        array &$working,
        array $candidate,
        array $similarity,
        array $profileMap,
        string $strategy,
        array $qualityThresholds,
        array $thresholds,
    ): bool {
        $memberId = $candidate['member_id'];
        $sourceIndex = $candidate['source_index'];
        $targetIndex = $candidate['target_index'];

        $sourceIds = $working[$sourceIndex]['member_ids'] ?? [];
        $targetIds = $working[$targetIndex]['member_ids'] ?? [];

        if (! in_array($memberId, $sourceIds, true)) {
            $candidate['log']['decision'] = 'SKIPPED_STALE';

            return false;
        }

        $currentFit = $this->clusterFit($memberId, $sourceIds, $similarity, $profileMap, $qualityThresholds);
        $altFit = $this->clusterFit($memberId, $targetIds, $similarity, $profileMap, $qualityThresholds);
        $sharedAnchors = KeywordClusterLocalAnchorSupport::sharedDiscriminativeAnchors(
            [$memberId],
            $targetIds,
            $profileMap,
            (float) $qualityThresholds['broad_family_coverage'],
        );
        if ($sharedAnchors !== []) {
            $altFit += min(0.14, count($sharedAnchors) * 0.045);
        }
        $margin = $altFit - $currentFit;

        if ($margin < (float) $qualityThresholds['competitive_improvement_margin']) {
            $candidate['log']['decision'] = 'REJECTED_MARGIN';

            return false;
        }

        if (! $this->passesAbsoluteFloor($memberId, $targetIds, $similarity, $qualityThresholds)) {
            $candidate['log']['decision'] = 'REJECTED_FLOOR';

            return false;
        }

        if ($sharedAnchors === []) {
            $candidate['log']['decision'] = 'REJECTED_ANCHOR';

            return false;
        }
        $candidate['log']['anchor_evidence'] = $sharedAnchors;

        $targetQuality = $this->analyzer->analyze($targetIds, $similarity, $profileMap, $strategy);
        $mergedTarget = [...$targetIds, $memberId];
        sort($mergedTarget, SORT_NUMERIC);
        $mergedTargetQuality = $this->analyzer->analyze($mergedTarget, $similarity, $profileMap, $strategy);
        if (! $this->targetAcceptsMember($targetQuality, $mergedTargetQuality, $qualityThresholds)) {
            $candidate['log']['decision'] = 'REJECTED_TARGET_REGRESSION';
            $candidate['log']['target_quality_after'] = $mergedTargetQuality->qualityState;

            return false;
        }

        $sourceWithout = array_values(array_filter(
            $sourceIds,
            static fn (int $id): bool => $id !== $memberId,
        ));
        if (count($sourceWithout) < 2) {
            $candidate['log']['decision'] = 'REJECTED_SOURCE_SIZE';

            return false;
        }

        if (count($mergedTarget) > (int) $qualityThresholds['rehome_max_merged_size']) {
            $candidate['log']['decision'] = 'REJECTED_TARGET_SIZE';

            return false;
        }

        $working[$sourceIndex]['member_ids'] = $sourceWithout;
        $working[$targetIndex]['member_ids'] = $mergedTarget;
        $existingNote = trim((string) ($working[$targetIndex]['rehome_note'] ?? ''));
        $note = 'Competitive +1 keyword';
        $working[$targetIndex]['rehome_note'] = $existingNote !== '' ? $existingNote.'; '.$note : $note;

        $candidate['log']['decision'] = 'ACCEPTED';
        $candidate['log']['target_quality_after'] = $mergedTargetQuality->qualityState;

        return true;
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  array<string, float|int>  $qualityThresholds
     */
    public function clusterFit(
        int $memberId,
        array $clusterIds,
        array $similarity,
        array $profileMap,
        array $qualityThresholds,
    ): float {
        if ($clusterIds === []) {
            return 0.0;
        }

        $others = array_values(array_filter(
            $clusterIds,
            static fn (int $id): bool => $id !== $memberId,
        ));
        if ($others === []) {
            return 1.0;
        }

        $medoidId = KeywordClusterSimilarityMatrix::medoid($clusterIds, $similarity);
        $medoidSim = $similarity[$memberId][$medoidId] ?? 0.0;

        $sum = 0.0;
        foreach ($others as $otherId) {
            $sum += $similarity[$memberId][$otherId] ?? 0.0;
        }
        $avgSim = $sum / count($others);

        $anchorScore = $this->anchorFitScore($memberId, $clusterIds, $profileMap, $qualityThresholds);

        $wMedoid = (float) $qualityThresholds['competitive_medoid_weight'];
        $wAvg = (float) $qualityThresholds['competitive_avg_weight'];
        $wAnchor = (float) $qualityThresholds['competitive_anchor_weight'];

        return round(($medoidSim * $wMedoid) + ($avgSim * $wAvg) + ($anchorScore * $wAnchor), 6);
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function anchorFitScore(
        int $memberId,
        array $clusterIds,
        array $profileMap,
        array $qualityThresholds,
    ): float {
        $shared = KeywordClusterLocalAnchorSupport::sharedDiscriminativeAnchors(
            [$memberId],
            $clusterIds,
            $profileMap,
            (float) $qualityThresholds['broad_family_coverage'],
        );
        if ($shared === []) {
            return 0.0;
        }

        return min(1.0, 0.20 + (count($shared) * 0.18));
    }

    /**
     * @param  list<int>  $targetIds
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function passesAbsoluteFloor(
        int $memberId,
        array $targetIds,
        array $similarity,
        array $qualityThresholds,
    ): bool {
        $floor = (float) $qualityThresholds['competitive_absolute_floor'];
        $targetMedoid = KeywordClusterSimilarityMatrix::medoid($targetIds, $similarity);
        $medoidSim = $similarity[$memberId][$targetMedoid] ?? 0.0;

        $sum = 0.0;
        $pairs = 0;
        foreach ($targetIds as $targetId) {
            $sum += $similarity[$memberId][$targetId] ?? 0.0;
            $pairs++;
        }
        $avgSim = $pairs > 0 ? $sum / $pairs : 0.0;

        return $medoidSim >= $floor || $avgSim >= $floor;
    }

    private function isSourceEligible(KeywordClusterQualityMetrics $quality, string $finalStatus): bool
    {
        if ($finalStatus === KeywordClusterProposalFinalStatus::READY) {
            return false;
        }

        return in_array($quality->qualityState, [
            KeywordClusterQualityMetrics::STATE_LOOSE,
            KeywordClusterQualityMetrics::STATE_MEGA,
        ], true) || $finalStatus === KeywordClusterProposalFinalStatus::NEEDS_REVIEW;
    }

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function isTargetEligible(
        KeywordClusterQualityMetrics $quality,
        string $finalStatus,
        array $qualityThresholds,
    ): bool {
        if (in_array($quality->qualityState, [
            KeywordClusterQualityMetrics::STATE_COMPACT,
            KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
        ], true)) {
            return true;
        }

        if ($finalStatus === KeywordClusterProposalFinalStatus::READY) {
            return true;
        }

        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_LOOSE) {
            return $quality->averageSimilarity >= ((float) $qualityThresholds['compact_avg'] - 0.10)
                && $quality->memberCount <= (int) $qualityThresholds['rehome_max_merged_size'];
        }

        if ($quality->qualityState === KeywordClusterQualityMetrics::STATE_MEGA) {
            return false;
        }

        return $finalStatus === KeywordClusterProposalFinalStatus::NEEDS_REVIEW
            && $quality->averageSimilarity >= ((float) $qualityThresholds['compact_avg'] - 0.08);
    }

    /**
     * @param  array<string, float|int>  $qualityThresholds
     */
    private function targetAcceptsMember(
        KeywordClusterQualityMetrics $before,
        KeywordClusterQualityMetrics $after,
        array $qualityThresholds,
    ): bool {
        $readyBefore = in_array($before->qualityState, [
            KeywordClusterQualityMetrics::STATE_COMPACT,
            KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
        ], true);

        if ($readyBefore && in_array($after->qualityState, [
            KeywordClusterQualityMetrics::STATE_LOOSE,
            KeywordClusterQualityMetrics::STATE_MEGA,
        ], true)) {
            return false;
        }

        if ($before->qualityState !== KeywordClusterQualityMetrics::STATE_MEGA
            && $after->qualityState === KeywordClusterQualityMetrics::STATE_MEGA
        ) {
            return false;
        }

        if ($after->p25Similarity + 0.02 < $before->p25Similarity
            && $after->averageSimilarity + 0.01 < $before->averageSimilarity
        ) {
            return false;
        }

        return $after->averageSimilarity >= ((float) $qualityThresholds['rehome_merged_cohesion_min'] - 0.04);
    }
}
