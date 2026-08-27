<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\DissolveTopicClusterResult;
use Throwable;

final class DissolveTopicClusterService
{
    public function __construct(
        private readonly KeywordClusterQuery $clusters,
        private readonly TopicClusterDissolveSideEffects $sideEffects,
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

        $keywordIds = $this->clusters->memberKeywordIds($siteId, $clusterKey);
        if ($keywordIds === []) {
            return DissolveTopicClusterResult::alreadyEmpty($clusterKey);
        }

        try {
            $affected = (int) DB::connection('omi_seo_ai')->transaction(function () use ($keywordIds, $clusterKey): int {
                $updated = SeoKeywordClassification::query()
                    ->whereIn('keyword_id', $keywordIds)
                    ->where('cluster_key', $clusterKey)
                    ->update(['cluster_key' => null]);

                if ($updated > 0) {
                    $this->markManualExclude($keywordIds);
                }

                return $updated;
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
