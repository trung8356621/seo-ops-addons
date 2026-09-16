<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\DissolveTopicClusterResult;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMultiSiteOwnership;
use Throwable;

final class DissolveTopicClusterService
{
    public function __construct(
        private readonly KeywordClusterQuery $clusters,
        private readonly TopicClusterDissolveSideEffects $sideEffects,
        private readonly TopicClusterDerivedCleanup $derivedCleanup,
    ) {}

    public function dissolve(int $siteId, string $clusterKey, ?string $clusterLabel = null): DissolveTopicClusterResult
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '' || $siteId <= 0) {
            return DissolveTopicClusterResult::invalidClusterKey();
        }

        if (! $this->clusters->classificationsReady()) {
            return DissolveTopicClusterResult::alreadyEmpty($clusterKey);
        }

        $keywordIds = $this->siteScopedClusterMemberIds($siteId, $clusterKey);
        if ($keywordIds === []) {
            // Orphan derived rows (meta/DNA/aliases) with no members — still purge.
            $this->derivedCleanup->purgeClusterArtifacts($siteId, $clusterKey);

            return DissolveTopicClusterResult::alreadyEmpty($clusterKey);
        }

        try {
            $affected = (int) DB::connection('omi_seo_ai')->transaction(function () use ($keywordIds, $clusterKey, $siteId): int {
                // Site-local dissolve: never clear global classification while another site still owns the keyword.
                $clearableIds = [];
                foreach ($keywordIds as $keywordId) {
                    $keywordId = (int) $keywordId;
                    if ($keywordId <= 0) {
                        continue;
                    }
                    if (KeywordMultiSiteOwnership::isSharedWithOtherSites($keywordId, $siteId)) {
                        continue;
                    }
                    $clearableIds[] = $keywordId;
                }

                $updated = 0;
                if ($clearableIds !== []) {
                    $updated = SeoKeywordClassification::query()
                        ->whereIn('keyword_id', $clearableIds)
                        ->where('cluster_key', $clusterKey)
                        ->update(['cluster_key' => null]);

                    if ($updated > 0) {
                        $this->markManualExclude($clearableIds);
                    }
                }

                // Always purge site-scoped derived artifacts for this site+cluster.
                $this->derivedCleanup->purgeClusterArtifacts($siteId, $clusterKey);

                // Site-local success signal: cleared globals + site inventory members touched.
                return max($updated, count($keywordIds));
            });
        } catch (Throwable) {
            return DissolveTopicClusterResult::failed($clusterKey);
        }

        $label = trim((string) ($clusterLabel ?? ''));
        if ($label === '') {
            $label = $this->clusters->displayLabel($clusterKey, '', $siteId);
        }

        if ($affected > 0) {
            $this->sideEffects->afterDissolve($siteId, $clusterKey, $label, $affected);
        }

        return DissolveTopicClusterResult::success($clusterKey, $affected);
    }

    /**
     * Site-owned members of a cluster for dissolve (cluster scope, not UI dictionary word-count filter).
     *
     * @return list<int>
     */
    private function siteScopedClusterMemberIds(int $siteId, string $clusterKey): array
    {
        if (! $this->clusters->classificationsReady()) {
            return [];
        }

        $siteKeywordIds = KeywordClusterSiteScope::keywordIds($siteId, null, excludeSuggest: true, requireLinkedSource: true);
        if ($siteKeywordIds === []) {
            return [];
        }

        return SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey)
            ->whereIn('keyword_id', $siteKeywordIds)
            ->limit(5000)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $keywordIds
     */
    private function markManualExclude(array $keywordIds): void
    {
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return;
        }

        $now = now();
        $metaKey = ReclusterTopicClustersService::META_MANUAL_EXCLUDE;
        $connection = DB::connection('omi_seo_ai');

        foreach ($keywordIds as $keywordId) {
            $keywordId = (int) $keywordId;
            if ($keywordId <= 0) {
                continue;
            }

            $exists = $connection->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', $metaKey)
                ->exists();

            if ($exists) {
                $connection->table('keyword_meta')
                    ->where('keyword_id', $keywordId)
                    ->where('meta_key', $metaKey)
                    ->update([
                        'meta_value' => '1',
                        'updated_at' => $now,
                    ]);

                continue;
            }

            $connection->table('keyword_meta')->insert([
                'keyword_id' => $keywordId,
                'meta_key' => $metaKey,
                'meta_value' => '1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
