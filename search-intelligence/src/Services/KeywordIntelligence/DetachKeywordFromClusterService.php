<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use RuntimeException;

final class DetachKeywordFromClusterService
{
    public function __construct(
        private readonly PruneAutoSingletonClustersService $singletonPruner,
    ) {}

    /**
     * @return array{cluster_key: string, keyword_id: int}
     */
    public function detach(int $siteId, int $keywordId, string $clusterKey): array
    {
        if ($siteId <= 0 || $keywordId <= 0 || trim($clusterKey) === '') {
            throw new RuntimeException('invalid_input');
        }

        $row = SeoKeywordClassification::query()
            ->where('keyword_id', $keywordId)
            ->first();
        if (! $row instanceof SeoKeywordClassification) {
            throw new RuntimeException('keyword_not_classified');
        }

        $currentKey = trim((string) ($row->cluster_key ?? ''));
        if ($currentKey !== trim($clusterKey)) {
            throw new RuntimeException('cluster_mismatch');
        }

        $row->cluster_key = null;
        $row->save();

        $this->singletonPruner->prune($siteId);
        TopicClusterDirtyState::mark($siteId, 'keyword_detached_from_cluster');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'keyword_detached_from_cluster');

        return [
            'cluster_key' => $clusterKey,
            'keyword_id' => $keywordId,
        ];
    }
}
