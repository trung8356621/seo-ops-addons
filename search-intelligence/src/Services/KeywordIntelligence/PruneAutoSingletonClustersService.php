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
 * Focus-Article singletons are preserved (invariant: Focus Article ⇒ Topic not null).
 */
final class PruneAutoSingletonClustersService
{
    public function __construct(
        private readonly TopicClusterDerivedCleanup $cleanup,
    ) {}

    /**
     * @param  array<string, true>|null  $touchedClusters
     * @return array{pruned: int, keywords_unclustered: int, focus_singletons_kept: int}
     */
    public function prune(int $siteId, ?array &$touchedClusters = null): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
            return ['pruned' => 0, 'keywords_unclustered' => 0, 'focus_singletons_kept' => 0];
        }

        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return ['pruned' => 0, 'keywords_unclustered' => 0, 'focus_singletons_kept' => 0];
        }

        $focusKeywordIds = $this->keywordIdsWithFocusArticle($keywordIds);

        $counts = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->selectRaw('cluster_key, COUNT(*) as member_count')
            ->groupBy('cluster_key')
            ->pluck('member_count', 'cluster_key');

        $membersByCluster = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->get(['keyword_id', 'cluster_key'])
            ->groupBy(static fn ($row): string => trim((string) $row->cluster_key));

        $pruned = 0;
        $keywordsUnclustered = 0;
        $focusSingletonsKept = 0;

        foreach ($counts as $clusterKey => $memberCount) {
            $key = trim((string) $clusterKey);
            if ($key === '' || (int) $memberCount >= 2) {
                continue;
            }

            if ($this->isManualCluster($siteId, $key)) {
                continue;
            }

            $memberIds = ($membersByCluster[$key] ?? collect())
                ->pluck('keyword_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $hasFocus = false;
            foreach ($memberIds as $memberId) {
                if (isset($focusKeywordIds[$memberId])) {
                    $hasFocus = true;
                    break;
                }
            }
            if ($hasFocus) {
                $focusSingletonsKept++;

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
            'focus_singletons_kept' => $focusSingletonsKept,
        ];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, true>
     */
    private function keywordIdsWithFocusArticle(array $keywordIds): array
    {
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return [];
        }

        $ids = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->where('meta_key', \Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::MainArticleId->value)
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->pluck('keyword_id')
            ->all();

        $out = [];
        foreach ($ids as $id) {
            $kid = (int) $id;
            if ($kid > 0) {
                $out[$kid] = true;
            }
        }

        return $out;
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
