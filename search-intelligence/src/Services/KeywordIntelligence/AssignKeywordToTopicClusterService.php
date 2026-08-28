<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use RuntimeException;

final class AssignKeywordToTopicClusterService
{
    public function __construct(
        private readonly KeywordClusterQuery $clusters,
    ) {}

    public function assign(int $siteId, int $keywordId, string $clusterKey): void
    {
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $keywordId <= 0 || $clusterKey === '') {
            throw new RuntimeException('invalid_input');
        }

        if (! $this->clusters->clusterExists($clusterKey, $siteId)) {
            throw new RuntimeException('cluster_not_found');
        }

        $row = SeoKeywordClassification::query()->where('keyword_id', $keywordId)->first();
        if (! $row instanceof SeoKeywordClassification) {
            throw new RuntimeException('keyword_not_classified');
        }

        $row->cluster_key = $clusterKey;
        $row->save();

        TopicClusterDirtyState::mark($siteId, 'keyword_assigned_to_cluster');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'keyword_assigned_to_cluster');
    }
}
