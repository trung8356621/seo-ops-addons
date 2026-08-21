<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;

final class KeywordClusterProposalEngine
{
    public function __construct(
        private readonly KeywordClusterProposalReader $reader,
        private readonly KeywordClusterSimilarityScorer $scorer,
        private readonly KeywordCanonicalizer $canonicalizer,
        private readonly KeywordClusterEligibility $eligibility,
        private readonly KeywordClusterProposalRefiner $refiner,
        private readonly TopicClusterProposalMemberStateLoader $memberStateLoader,
        private readonly TopicClusterProposalFingerprint $fingerprintService,
    ) {}

    public function previewForSite(int $siteId, string $strategy = KeywordClusterProposalStrategy::BALANCED): KeywordClusterProposalResult
    {
        $strategy = KeywordClusterProposalStrategy::normalize($strategy);
        $diagnostics = $this->eligibility->summaryMetrics($siteId);
        $diagnostics['phrase_kind_distribution'] = $this->eligibility->phraseKindDistribution($siteId);
        $context = $this->reader->loadContext($siteId);
        /** @var list<KeywordClusterTokenProfile> $profiles */
        $profiles = $context['profiles'];

        if ($profiles === []) {
            return new KeywordClusterProposalResult(
                siteId: $siteId,
                strategy: $strategy,
                protectedClusterCount: (int) $context['protected_cluster_count'],
                protectedClusteredKeywords: (int) $context['protected_clustered_keywords'],
                candidateCount: 0,
                proposedClusters: [],
                unclustered: [],
                proposedKeywordCount: 0,
                diagnostics: $this->diagnosticSlice($diagnostics),
                previewFingerprint: '',
                memberStates: [],
            );
        }

        $corpus = KeywordClusterCorpusStatistics::fromProfiles($profiles);
        $thresholds = KeywordClusterProposalStrategy::thresholds($strategy);

        /** @var array<int, KeywordClusterTokenProfile> $profileMap */
        $profileMap = [];
        foreach ($profiles as $profile) {
            $profileMap[$profile->keywordId] = $profile;
        }

        $ids = array_keys($profileMap);
        sort($ids, SORT_NUMERIC);

        $similarity = $this->buildSimilarityMatrix($ids, $profileMap, $corpus);
        $initialClusters = $this->buildInitialClusters($ids, $profileMap, $similarity, $thresholds);

        $refined = $this->refiner->refine(
            initialClusters: $initialClusters,
            similarity: $similarity,
            profileMap: $profileMap,
            corpus: $corpus,
            strategy: $strategy,
        );

        $clusters = [];
        foreach ($refined['clusters'] as $draft) {
            $clusters[] = $this->buildProposalCluster(
                clusterIds: $draft['member_ids'],
                profileMap: $profileMap,
                similarity: $similarity,
                quality: $draft['quality'],
                splitFromLabel: $draft['split_from_label'],
                splitReason: $draft['split_reason'],
                rehomeNote: $draft['rehome_note'],
                finalStatus: $draft['final_status'],
            );
        }

        $proposedKeywordIds = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $proposedKeywordIds[(int) $member['keyword_id']] = true;
            }
        }

        $unclustered = [];
        foreach ($profiles as $profile) {
            if (isset($proposedKeywordIds[$profile->keywordId])) {
                continue;
            }
            $unclustered[] = [
                'keyword_id' => $profile->keywordId,
                'phrase' => $profile->phrase,
            ];
        }

        usort(
            $clusters,
            static function (KeywordClusterProposalCluster $a, KeywordClusterProposalCluster $b): int {
                $countCompare = $b->memberCount <=> $a->memberCount;
                if ($countCompare !== 0) {
                    return $countCompare;
                }

                return $b->cohesion <=> $a->cohesion;
            },
        );

        $diagnostics = $this->diagnosticSlice($diagnostics);
        $diagnostics['initial_cluster_count'] = $refined['initial_cluster_count'];
        $diagnostics['final_cluster_count'] = $refined['final_cluster_count'];
        $diagnostics['loose_clusters_detected'] = $refined['loose_clusters_detected'];
        $diagnostics['clusters_split_count'] = $refined['clusters_split_count'];
        $diagnostics['split_passes'] = $refined['split_passes'];
        $diagnostics['subgroups_peeled'] = $refined['subgroups_peeled'];
        $diagnostics['subgroups_rehomed'] = $refined['subgroups_rehomed'];
        $diagnostics['competitive_moves'] = $refined['competitive_moves'];
        $diagnostics['strong_duplicate_merges'] = $refined['strong_duplicate_merges'];
        $diagnostics['members_released'] = $refined['members_released'];
        $diagnostics['potential_duplicate_count'] = count($refined['potential_duplicate_pairs']);
        $diagnostics['potential_duplicate_pairs'] = $refined['potential_duplicate_pairs'];
        $diagnostics['competitive_move_log'] = $refined['competitive_move_log'];
        $diagnostics['competitive_review_log'] = $refined['competitive_review_log'];
        $diagnostics['strong_merge_log'] = $refined['strong_merge_log'];
        $diagnostics['lineage_disposition'] = $refined['lineage_disposition'];
        $diagnostics['ready_proposal_count'] = $refined['ready_proposal_count'];
        $diagnostics['needs_review_proposal_count'] = $refined['needs_review_proposal_count'];
        $diagnostics['largest_initial_cluster_size'] = $refined['largest_initial_cluster_size'];
        $diagnostics['largest_final_cluster_size'] = $refined['largest_final_cluster_size'];

        $candidateIds = array_map(static fn (KeywordClusterTokenProfile $profile): int => $profile->keywordId, $profiles);
        $memberStates = $this->memberStateLoader->loadForSite($siteId, $candidateIds);
        $previewFingerprint = $this->fingerprintService->previewFingerprint($siteId, $strategy, $memberStates);

        $fingerprintedClusters = [];
        foreach ($clusters as $cluster) {
            $proposalFingerprint = $this->fingerprintService->proposalFingerprint(
                siteId: $siteId,
                strategy: $strategy,
                previewFingerprint: $previewFingerprint,
                cluster: $cluster,
                memberStates: $memberStates,
            );
            $fingerprintedClusters[] = $this->withProposalFingerprint($cluster, $proposalFingerprint);
        }

        return new KeywordClusterProposalResult(
            siteId: $siteId,
            strategy: $strategy,
            protectedClusterCount: (int) $context['protected_cluster_count'],
            protectedClusteredKeywords: (int) $context['protected_clustered_keywords'],
            candidateCount: count($profiles),
            proposedClusters: $fingerprintedClusters,
            unclustered: $unclustered,
            proposedKeywordCount: count($proposedKeywordIds),
            diagnostics: $diagnostics,
            previewFingerprint: $previewFingerprint,
            memberStates: $memberStates,
        );
    }

    private function withProposalFingerprint(
        KeywordClusterProposalCluster $cluster,
        string $proposalFingerprint,
    ): KeywordClusterProposalCluster {
        return new KeywordClusterProposalCluster(
            representativeLabel: $cluster->representativeLabel,
            representativeKeywordId: $cluster->representativeKeywordId,
            cohesion: $cluster->cohesion,
            minSimilarity: $cluster->minSimilarity,
            memberCount: $cluster->memberCount,
            members: $cluster->members,
            quality: $cluster->quality,
            splitFromLabel: $cluster->splitFromLabel,
            splitReason: $cluster->splitReason,
            rehomeNote: $cluster->rehomeNote,
            finalStatus: $cluster->finalStatus,
            proposalFingerprint: $proposalFingerprint,
            proposalRef: $proposalFingerprint,
        );
    }

    /**
     * @param  array<string, int|array<string, array{seo_true: int, seo_false: int}|list<array<string, mixed>>>  $metrics
     * @return array<string, int|array<string, array{seo_true: int, seo_false: int}|list<array<string, mixed>>>>
     */
    private function diagnosticSlice(array $metrics): array
    {
        return [
            'total_keywords' => (int) ($metrics['total_keywords'] ?? 0),
            'classified_keywords' => (int) ($metrics['classified_keywords'] ?? 0),
            'seo_eligible_keywords' => (int) ($metrics['seo_eligible_keywords'] ?? 0),
            'clustered' => (int) ($metrics['clustered'] ?? 0),
            'unclustered' => (int) ($metrics['unclustered'] ?? 0),
            'unclassified_keywords' => (int) ($metrics['unclassified_keywords'] ?? 0),
            'non_seo_keywords' => (int) ($metrics['non_seo_keywords'] ?? 0),
            'non_seo_but_clustered' => (int) ($metrics['non_seo_but_clustered'] ?? 0),
            'initial_cluster_count' => (int) ($metrics['initial_cluster_count'] ?? 0),
            'final_cluster_count' => (int) ($metrics['final_cluster_count'] ?? 0),
            'loose_clusters_detected' => (int) ($metrics['loose_clusters_detected'] ?? 0),
            'clusters_split_count' => (int) ($metrics['clusters_split_count'] ?? 0),
            'split_passes' => (int) ($metrics['split_passes'] ?? 0),
            'subgroups_peeled' => (int) ($metrics['subgroups_peeled'] ?? 0),
            'subgroups_rehomed' => (int) ($metrics['subgroups_rehomed'] ?? 0),
            'competitive_moves' => (int) ($metrics['competitive_moves'] ?? 0),
            'strong_duplicate_merges' => (int) ($metrics['strong_duplicate_merges'] ?? 0),
            'members_released' => (int) ($metrics['members_released'] ?? 0),
            'potential_duplicate_count' => (int) ($metrics['potential_duplicate_count'] ?? 0),
            'potential_duplicate_pairs' => is_array($metrics['potential_duplicate_pairs'] ?? null)
                ? $metrics['potential_duplicate_pairs']
                : [],
            'competitive_move_log' => is_array($metrics['competitive_move_log'] ?? null)
                ? $metrics['competitive_move_log']
                : [],
            'competitive_review_log' => is_array($metrics['competitive_review_log'] ?? null)
                ? $metrics['competitive_review_log']
                : [],
            'strong_merge_log' => is_array($metrics['strong_merge_log'] ?? null)
                ? $metrics['strong_merge_log']
                : [],
            'lineage_disposition' => is_array($metrics['lineage_disposition'] ?? null)
                ? $metrics['lineage_disposition']
                : ['lineages' => [], 'all_conserved' => false],
            'ready_proposal_count' => (int) ($metrics['ready_proposal_count'] ?? 0),
            'needs_review_proposal_count' => (int) ($metrics['needs_review_proposal_count'] ?? 0),
            'largest_initial_cluster_size' => (int) ($metrics['largest_initial_cluster_size'] ?? 0),
            'largest_final_cluster_size' => (int) ($metrics['largest_final_cluster_size'] ?? 0),
            'phrase_kind_distribution' => is_array($metrics['phrase_kind_distribution'] ?? null)
                ? $metrics['phrase_kind_distribution']
                : [],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @param  array<int, array<int, float>>  $similarity
     * @param  array{member: float, cohesion: float, ambiguous_penalty: float}  $thresholds
     * @return list<list<int>>
     */
    private function buildInitialClusters(
        array $ids,
        array $profileMap,
        array $similarity,
        array $thresholds,
    ): array {
        $unassigned = $ids;
        $clusters = [];

        while ($unassigned !== []) {
            $seedId = $this->pickSeed($unassigned, $similarity);
            $clusterIds = $this->growCluster($seedId, $unassigned, $profileMap, $similarity, $thresholds);

            if (count($clusterIds) >= 2) {
                $clusters[] = $clusterIds;
            }

            $unassigned = array_values(array_diff($unassigned, $clusterIds));
        }

        return $clusters;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array<int, array<int, float>>
     */
    private function buildSimilarityMatrix(array $ids, array $profileMap, KeywordClusterCorpusStatistics $corpus): array
    {
        $matrix = [];
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            $leftId = $ids[$i];
            $matrix[$leftId][$leftId] = 1.0;
            for ($j = $i + 1; $j < $count; $j++) {
                $rightId = $ids[$j];
                $score = $this->scorer->score($profileMap[$leftId], $profileMap[$rightId], $corpus);
                $matrix[$leftId][$rightId] = $score;
                $matrix[$rightId][$leftId] = $score;
            }
        }

        return $matrix;
    }

    /**
     * @param  list<int>  $unassigned
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @param  array<int, array<int, float>>  $similarity
     * @param  array{member: float, cohesion: float, ambiguous_penalty: float}  $thresholds
     * @return list<int>
     */
    private function growCluster(
        int $seedId,
        array $unassigned,
        array $profileMap,
        array $similarity,
        array $thresholds,
    ): array {
        $clusterIds = [$seedId];
        $pool = array_values(array_filter(
            $unassigned,
            static fn (int $id): bool => $id !== $seedId,
        ));

        $grew = true;
        while ($grew) {
            $grew = false;
            $medoidId = KeywordClusterSimilarityMatrix::medoid($clusterIds, $similarity);
            foreach ($pool as $index => $candidateId) {
                $profile = $profileMap[$candidateId];
                $memberThreshold = $thresholds['member']
                    + ($profile->isAmbiguous ? $thresholds['ambiguous_penalty'] : 0.0);
                if (($similarity[$medoidId][$candidateId] ?? 0.0) < $memberThreshold) {
                    continue;
                }

                $tentative = [...$clusterIds, $candidateId];
                if (KeywordClusterSimilarityMatrix::cohesion($tentative, $similarity) < $thresholds['cohesion']) {
                    continue;
                }

                $clusterIds[] = $candidateId;
                unset($pool[$index]);
                $grew = true;
            }
            $pool = array_values($pool);
        }

        sort($clusterIds, SORT_NUMERIC);

        return $clusterIds;
    }

    /**
     * @param  list<int>  $unassigned
     * @param  array<int, array<int, float>>  $similarity
     */
    private function pickSeed(array $unassigned, array $similarity): int
    {
        $bestId = $unassigned[0];
        $bestScore = -1.0;

        foreach ($unassigned as $candidateId) {
            $sum = 0.0;
            foreach ($unassigned as $otherId) {
                if ($otherId === $candidateId) {
                    continue;
                }
                $sum += $similarity[$candidateId][$otherId] ?? 0.0;
            }
            if ($sum > $bestScore || ($sum === $bestScore && $candidateId < $bestId)) {
                $bestScore = $sum;
                $bestId = $candidateId;
            }
        }

        return $bestId;
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @param  array<int, array<int, float>>  $similarity
     */
    private function buildProposalCluster(
        array $clusterIds,
        array $profileMap,
        array $similarity,
        ?KeywordClusterQualityMetrics $quality = null,
        ?string $splitFromLabel = null,
        ?string $splitReason = null,
        ?string $rehomeNote = null,
        string $finalStatus = KeywordClusterProposalCluster::FINAL_READY,
    ): KeywordClusterProposalCluster {
        sort($clusterIds, SORT_NUMERIC);
        $medoidId = KeywordClusterSimilarityMatrix::medoid($clusterIds, $similarity);
        $medoid = $profileMap[$medoidId];

        $members = [];
        foreach ($clusterIds as $keywordId) {
            $profile = $profileMap[$keywordId];
            $members[] = [
                'keyword_id' => $profile->keywordId,
                'phrase' => $profile->phrase,
                'seo_intent' => $profile->seoIntent,
            ];
        }

        usort(
            $members,
            static fn (array $a, array $b): int => strcmp((string) $a['phrase'], (string) $b['phrase']),
        );

        $cohesion = KeywordClusterSimilarityMatrix::cohesion($clusterIds, $similarity);
        $minSimilarity = KeywordClusterSimilarityMatrix::minPairSimilarity($clusterIds, $similarity);
        $representativeLabel = $this->canonicalizer->pickDisplay([
            [
                'raw_text' => $medoid->phrase,
                'normalized_text' => $medoid->normalizedText,
                'folded_text' => $medoid->foldedText,
            ],
        ]);

        return new KeywordClusterProposalCluster(
            representativeLabel: $representativeLabel !== '' ? $representativeLabel : $medoid->phrase,
            representativeKeywordId: $medoidId,
            cohesion: $cohesion,
            minSimilarity: $minSimilarity,
            memberCount: count($members),
            members: $members,
            quality: $quality,
            splitFromLabel: $splitFromLabel,
            splitReason: $splitReason,
            rehomeNote: $rehomeNote,
            finalStatus: $finalStatus,
        );
    }
}
