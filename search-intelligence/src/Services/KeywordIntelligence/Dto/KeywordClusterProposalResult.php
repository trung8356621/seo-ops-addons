<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class KeywordClusterProposalResult
{
    /**
     * @param  list<KeywordClusterProposalCluster>  $proposedClusters
     * @param  list<array{keyword_id: int, phrase: string}>  $unclustered
     * @param  array<string, int>  $diagnostics
     */
    public function __construct(
        public int $siteId,
        public string $strategy,
        public int $protectedClusterCount,
        public int $protectedClusteredKeywords,
        public int $candidateCount,
        public array $proposedClusters,
        public array $unclustered,
        public int $proposedKeywordCount,
        public array $diagnostics = [],
        public string $previewFingerprint = '',
        /** @var array<int, \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalMemberState> */
        public array $memberStates = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'strategy' => $this->strategy,
            'strategy_label' => \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy::label($this->strategy),
            'protected_cluster_count' => $this->protectedClusterCount,
            'protected_clustered_keywords' => $this->protectedClusteredKeywords,
            'candidate_count' => $this->candidateCount,
            'proposed_cluster_count' => count($this->proposedClusters),
            'proposed_keyword_count' => $this->proposedKeywordCount,
            'remain_unclustered' => count($this->unclustered),
            'preview_fingerprint' => $this->previewFingerprint,
            'algorithm_version' => \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalAlgorithmVersion::CURRENT,
            'diagnostics' => $this->diagnostics,
            'proposed_clusters' => array_map(
                static fn (KeywordClusterProposalCluster $cluster): array => $cluster->toArray(),
                $this->proposedClusters,
            ),
            'unclustered' => $this->unclustered,
        ];
    }
}
