<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use App\Core\Operations\OperationLogger;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use Throwable;

final class TopicClusterDissolveSideEffects
{
    public function afterDissolve(int $siteId, string $clusterKey, string $clusterLabel, int $affectedKeywordCount): void
    {
        TopicClusterDirtyState::mark($siteId, 'cluster_dissolved');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'cluster_dissolved');
        $this->invalidateLandscapeCache($siteId);
        $this->logDissolve($siteId, $clusterKey, $clusterLabel, $affectedKeywordCount);
    }

    private function invalidateLandscapeCache(int $siteId): void
    {
        try {
            $connection = (string) config('database.default', 'mysql');
            if (! Schema::connection($connection)->hasTable('sites')) {
                return;
            }

            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                return;
            }

            $site->metas()->where('meta_key', KeywordClassificationService::META_LANDSCAPE)->delete();
        } catch (Throwable) {
            // Best-effort cache invalidation only.
        }
    }

    private function logDissolve(int $siteId, string $clusterKey, string $clusterLabel, int $affectedKeywordCount): void
    {
        try {
            if (! function_exists('app') || ! app()->bound(OperationLogger::class)) {
                return;
            }

            app(OperationLogger::class)->info('keyword_cluster.dissolved', [
                'site_id' => $siteId,
                'cluster_key' => $clusterKey,
                'cluster_name' => $clusterLabel,
                'affected_keyword_count' => $affectedKeywordCount,
                'actor_id' => auth()->id(),
            ]);
        } catch (Throwable) {
            // Best-effort audit only.
        }
    }
}
