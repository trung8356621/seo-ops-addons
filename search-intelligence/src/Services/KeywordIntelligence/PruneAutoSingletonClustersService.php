<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;

/**
 * Formal clustering stage: AUTO clusters with member_count < 2 are not real clusters.
 * Keywords return to unclustered (cluster_key = null). MANUAL singletons are preserved.
 */
final class PruneAutoSingletonClustersService
{
    public function __construct(
        private readonly TopicClusterDerivedCleanup $cleanup,
    ) {}

    /**
     * @param  array<string, true>|null  $touchedClusters
     * @return array{pruned: int, keywords_unclustered: int}
     */
    public function prune(int $siteId, ?array &$touchedClusters = null): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
            return ['pruned' => 0, 'keywords_unclustered' => 0];
        }

        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return ['pruned' => 0, 'keywords_unclustered' => 0];
        }

        $counts = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->selectRaw('cluster_key, COUNT(*) as member_count')
            ->groupBy('cluster_key')
            ->pluck('member_count', 'cluster_key');

        $pruned = 0;
        $keywordsUnclustered = 0;

        foreach ($counts as $clusterKey => $memberCount) {
            $key = trim((string) $clusterKey);
            if ($key === '' || (int) $memberCount >= 2) {
                continue;
            }

            if ($this->isManualCluster($siteId, $key)) {
                continue;
            }

            $cleared = (int) DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $key, $keywordIds): int {
                $updated = SeoKeywordClassification::query()
                    ->whereIn('keyword_id', $keywordIds)
                    ->where('cluster_key', $key)
                    ->update(['cluster_key' => null]);

                $this->cleanup->purgeClusterArtifacts($siteId, $key);

                return $updated;
            });

            if ($cleared > 0) {
                $pruned++;
                $keywordsUnclustered += $cleared;
                if (is_array($touchedClusters)) {
                    unset($touchedClusters[$key]);
                }
            }
        }

        return [
            'pruned' => $pruned,
            'keywords_unclustered' => $keywordsUnclustered,
        ];
    }

    private function isManualCluster(int $siteId, string $clusterKey): bool
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return false;
        }
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            return false;
        }

        $meta = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();

        return $meta instanceof SeoTopicClusterMeta && $meta->isManual();
    }
}
