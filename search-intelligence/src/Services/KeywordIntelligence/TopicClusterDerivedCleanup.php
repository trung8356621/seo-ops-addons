<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterAlias;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;

/**
 * Removes derived artifacts that belong only to a cluster_key (not keywords themselves).
 */
final class TopicClusterDerivedCleanup
{
    public function purgeClusterArtifacts(int $siteId, string $clusterKey): void
    {
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '') {
            return;
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')) {
            SeoKeywordDna::query()
                ->where('site_id', $siteId)
                ->where('cluster_key', $clusterKey)
                ->delete();
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_aliases')) {
            SeoTopicClusterAlias::query()
                ->where('site_id', $siteId)
                ->where('cluster_key', $clusterKey)
                ->delete();
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            SeoTopicClusterMeta::query()
                ->where('site_id', $siteId)
                ->where('cluster_key', $clusterKey)
                ->delete();
        }

        app(McpTopicGroupService::class)->removeCluster($siteId, $clusterKey);
    }
}
