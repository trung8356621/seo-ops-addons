<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class KeywordClusterProposalRefiner
{
    public function __construct(
        private readonly KeywordClusterQualityGuard $qualityGuard,
        private readonly KeywordClusterProposalReconciler $reconciler,
        private readonly KeywordClusterCompetitiveRefiner $competitiveRefiner,
        private readonly KeywordClusterDuplicateResolver $duplicateResolver,
        private readonly KeywordClusterBorderlineRelease $borderlineRelease,
        private readonly KeywordClusterQualityAnalyzer $analyzer,
    ) {}

    /**
     * @param  list<list<int>>  $initialClusters
     * @param  array<int, array<int, float>>  $similarity
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array{
     *     clusters: list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string, rehome_note: ?string, quality: KeywordClusterQualityMetrics, final_status: string}>,
     *     initial_cluster_count: int,
     *     final_cluster_count: int,
     *     loose_clusters_detected: int,
     *     clusters_split_count: int,
     *     split_passes: int,
     *     subgroups_peeled: int,
     *     subgroups_rehomed: int,
     *     competitive_moves: int,
     *     strong_duplicate_merges: int,
     *     members_released: int,
     *     potential_duplicate_pairs: list<array<string, mixed>>,
     *     competitive_move_log: list<array<string, mixed>>,
     *     competitive_review_log: list<array<string, mixed>>,
     *     strong_merge_log: list<array<string, mixed>>,
     *     lineage_disposition: array<string, mixed>,
     *     ready_proposal_count: int,
     *     needs_review_proposal_count: int,
     *     largest_initial_cluster_size: int,
     *     largest_final_cluster_size: int,
     *     released_member_ids: list<int>,
     * }
     */
    public function refine(
        array $initialClusters,
        array $similarity,
        array $profileMap,
        KeywordClusterCorpusStatistics $corpus,
        string $strategy,
    ): array {
        $ledger = KeywordClusterLineageLedger::fromInitialClusters($initialClusters, $profileMap);

        $passOne = $this->qualityGuard->refine(
            initialClusters: $initialClusters,
            similarity: $similarity,
            profileMap: $profileMap,
            corpus: $corpus,
            strategy: $strategy,
        );
        $this->recordPeelEvents($ledger, $passOne['clusters']);

        $reconcileOne = $this->reconciler->reconcile(
            drafts: $passOne['clusters'],
            similarity: $similarity,
            profileMap: $profileMap,
            strategy: $strategy,
        );
        $this->recordRehomeEvents($ledger, $passOne['clusters'], $reconcileOne['drafts'], $reconcileOne['subgroups_rehomed']);

        $residual = $this->qualityGuard->refineResiduals(
            drafts: $reconcileOne['drafts'],
            similarity: $similarity,
            profileMap: $profileMap,
            corpus: $corpus,
            strategy: $strategy,
        );
        $this->recordPeelEvents($ledger, $residual['drafts']);

        $reconcileTwo = $this->reconciler->reconcile(
            drafts: $residual['drafts'],
            similarity: $similarity,
            profileMap: $profileMap,
            strategy: $strategy,
        );
        $this->recordRehomeEvents($ledger, $residual['drafts'], $reconcileTwo['drafts'], $reconcileTwo['subgroups_rehomed']);

        $competitive = $this->competitiveRefiner->refine(
            drafts: $reconcileTwo['drafts'],
            similarity: $similarity,
            profileMap: $profileMap,
            strategy: $strategy,
            ledger: $ledger,
        );

        $duplicateResolution = $this->duplicateResolver->resolve(
            drafts: $competitive['drafts'],
            similarity: $similarity,
            profileMap: $profileMap,
            strategy: $strategy,
            ledger: $ledger,
        );

        $release = $this->borderlineRelease->release(
            drafts: $duplicateResolution['drafts'],
            similarity: $similarity,
            profileMap: $profileMap,
            strategy: $strategy,
        );
        if ($release['released_member_ids'] !== []) {
            $ledger->record(
                KeywordClusterLineageLedger::EVENT_RELEASE,
                $release['released_member_ids'],
                ['count' => count($release['released_member_ids'])],
            );
        }

        $qualityThresholds = KeywordClusterProposalStrategy::qualityThresholds($strategy);
        $clusters = [];
        $readyCount = 0;
        $needsReviewCount = 0;
        $largestFinal = 0;

        foreach ($release['drafts'] as $draft) {
            $memberIds = $draft['member_ids'];
            sort($memberIds, SORT_NUMERIC);
            $quality = $this->analyzer->analyze($memberIds, $similarity, $profileMap, $strategy);
            $finalStatus = KeywordClusterProposalFinalStatus::resolve($quality, $qualityThresholds);
            if ($finalStatus === KeywordClusterProposalFinalStatus::READY) {
                $readyCount++;
            } else {
                $needsReviewCount++;
            }

            $largestFinal = max($largestFinal, count($memberIds));
            $clusters[] = [
                'member_ids' => $memberIds,
                'split_from_label' => $draft['split_from_label'] ?? null,
                'split_reason' => $draft['split_reason'] ?? null,
                'rehome_note' => $draft['rehome_note'] ?? null,
                'quality' => $quality,
                'final_status' => $finalStatus,
            ];
        }

        $remainingDuplicates = $this->reconciler->detectDuplicatePairs(
            drafts: $release['drafts'],
            similarity: $similarity,
            profileMap: $profileMap,
            strategy: $strategy,
        );
        $potentialPairs = array_values(array_filter(
            [
                ...$duplicateResolution['potential_pairs'],
                ...array_map(
                    static fn (array $pair): array => [
                        ...$pair,
                        'classification' => 'POTENTIAL_DUPLICATE',
                        'decision' => $pair['decision'] ?? 'POTENTIAL_DUPLICATE',
                    ],
                    array_filter(
                        $remainingDuplicates,
                        static fn (array $pair): bool => in_array($pair['decision'], ['POTENTIAL_DUPLICATE', 'WOULD_MERGE', 'KEPT_SEPARATE'], true),
                    ),
                ),
            ],
            static fn (array $pair): bool => ($pair['classification'] ?? $pair['decision'] ?? '') !== 'STRONG_DUPLICATE',
        ));

        $lineageDisposition = $ledger->buildDisposition(
            finalDrafts: $release['drafts'],
            releasedMemberIds: $release['released_member_ids'],
            profileMap: $profileMap,
            similarity: $similarity,
        );

        $splitPasses = (int) $passOne['clusters_split_count'] + (int) $residual['residual_passes'];
        $subgroupsPeeled = (int) $passOne['subgroups_peeled'] + (int) $residual['subgroups_peeled'];

        return [
            'clusters' => $clusters,
            'initial_cluster_count' => (int) $passOne['initial_cluster_count'],
            'final_cluster_count' => count($clusters),
            'loose_clusters_detected' => (int) $passOne['loose_clusters_detected'] + (int) $residual['residual_targets'],
            'clusters_split_count' => (int) $passOne['clusters_split_count'] + (int) $residual['residual_splits'],
            'split_passes' => $splitPasses,
            'subgroups_peeled' => $subgroupsPeeled,
            'subgroups_rehomed' => (int) $reconcileOne['subgroups_rehomed'] + (int) $reconcileTwo['subgroups_rehomed'],
            'competitive_moves' => (int) $competitive['competitive_moves'],
            'strong_duplicate_merges' => (int) $duplicateResolution['strong_merges'],
            'members_released' => count($release['released_member_ids']),
            'potential_duplicate_pairs' => $potentialPairs,
            'competitive_move_log' => $competitive['move_log'],
            'competitive_review_log' => $competitive['review_log'],
            'strong_merge_log' => $duplicateResolution['merge_log'],
            'lineage_disposition' => $lineageDisposition,
            'ready_proposal_count' => $readyCount,
            'needs_review_proposal_count' => $needsReviewCount,
            'largest_initial_cluster_size' => (int) $passOne['largest_initial_cluster_size'],
            'largest_final_cluster_size' => $largestFinal,
            'released_member_ids' => $release['released_member_ids'],
        ];
    }

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string, split_reason: ?string}>  $clusters
     */
    private function recordPeelEvents(KeywordClusterLineageLedger $ledger, array $clusters): void
    {
        foreach ($clusters as $cluster) {
            $splitFrom = trim((string) ($cluster['split_from_label'] ?? ''));
            if ($splitFrom === '') {
                continue;
            }

            $ledger->record(
                KeywordClusterLineageLedger::EVENT_PEEL,
                $cluster['member_ids'],
                ['from_label' => $splitFrom, 'reason' => $cluster['split_reason'] ?? null],
            );
        }
    }

    /**
     * @param  list<array{member_ids: list<int>, split_from_label: ?string}>  $before
     * @param  list<array{member_ids: list<int>}>  $after
     */
    private function recordRehomeEvents(
        KeywordClusterLineageLedger $ledger,
        array $before,
        array $after,
        int $rehomedCount,
    ): void {
        if ($rehomedCount <= 0) {
            return;
        }

        $afterDrafts = array_map(
            static fn (array $draft): array => array_values(array_unique($draft['member_ids'])),
            $after,
        );

        foreach ($before as $peel) {
            if (trim((string) ($peel['split_from_label'] ?? '')) === '') {
                continue;
            }

            $peelIds = array_values(array_unique($peel['member_ids']));
            sort($peelIds, SORT_NUMERIC);
            if ($peelIds === []) {
                continue;
            }

            $stillStandalone = false;
            foreach ($afterDrafts as $afterIds) {
                if ($afterIds === $peelIds) {
                    $stillStandalone = true;
                    break;
                }
            }
            if ($stillStandalone) {
                continue;
            }

            foreach ($afterDrafts as $afterIds) {
                if (array_intersect($peelIds, $afterIds) === $peelIds) {
                    $ledger->record(
                        KeywordClusterLineageLedger::EVENT_REHOME,
                        $peelIds,
                        ['destination_count' => count($afterIds)],
                    );
                    break;
                }
            }
        }
    }
}
