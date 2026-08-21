<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;

final class TopicClusterProposalFingerprint
{
    /**
     * @param  array<int, TopicClusterProposalMemberState>  $memberStates keyed by keyword_id
     */
    public function previewFingerprint(int $siteId, string $strategy, array $memberStates): string
    {
        $ids = array_keys($memberStates);
        sort($ids, SORT_NUMERIC);

        $members = [];
        foreach ($ids as $keywordId) {
            $members[] = [
                'keyword_id' => $keywordId,
                'state_hash' => $memberStates[$keywordId]->stateHash(),
            ];
        }

        return $this->hash([
            'algorithm_version' => TopicClusterProposalAlgorithmVersion::CURRENT,
            'site_id' => $siteId,
            'strategy' => KeywordClusterProposalStrategy::normalize($strategy),
            'members' => $members,
        ]);
    }

    /**
     * @param  array<int, TopicClusterProposalMemberState>  $memberStates
     */
    public function proposalFingerprint(
        int $siteId,
        string $strategy,
        string $previewFingerprint,
        KeywordClusterProposalCluster $cluster,
        array $memberStates,
    ): string {
        $memberIds = array_map(
            static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
            $cluster->members,
        );
        $memberIds = array_values(array_filter($memberIds, static fn (int $id): bool => $id > 0));
        sort($memberIds, SORT_NUMERIC);

        $memberHashes = [];
        foreach ($memberIds as $keywordId) {
            $memberHashes[] = [
                'keyword_id' => $keywordId,
                'state_hash' => ($memberStates[$keywordId] ?? null)?->stateHash() ?? '',
            ];
        }

        return $this->hash([
            'preview_fingerprint' => $previewFingerprint,
            'algorithm_version' => TopicClusterProposalAlgorithmVersion::CURRENT,
            'site_id' => $siteId,
            'strategy' => KeywordClusterProposalStrategy::normalize($strategy),
            'representative_keyword_id' => $cluster->representativeKeywordId,
            'representative_label' => $cluster->representativeLabel,
            'final_status' => $cluster->finalStatus,
            'quality_state' => $cluster->quality?->qualityState ?? KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
            'member_hashes' => $memberHashes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
