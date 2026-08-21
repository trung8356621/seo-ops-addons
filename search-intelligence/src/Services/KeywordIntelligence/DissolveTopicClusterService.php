<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
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
                return SeoKeywordClassification::query()
                    ->whereIn('keyword_id', $keywordIds)
                    ->where('cluster_key', $clusterKey)
                    ->update(['cluster_key' => null]);
            });
        } catch (Throwable) {
            return DissolveTopicClusterResult::failed($clusterKey);
        }

        $label = trim((string) ($clusterLabel ?? ''));
        if ($label === '') {
            $label = $this->clusters->displayLabel($clusterKey);
        }

        if ($affected > 0) {
            $this->sideEffects->afterDissolve($siteId, $clusterKey, $label, $affected);
        }

        return DissolveTopicClusterResult::success($clusterKey, $affected);
    }
}
