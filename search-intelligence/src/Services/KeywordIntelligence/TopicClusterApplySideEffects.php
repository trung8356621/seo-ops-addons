<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use App\Core\Operations\OperationLogger;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use Throwable;

final class TopicClusterApplySideEffects
{
    public function afterApply(
        int $siteId,
        string $clusterKey,
        string $representativeLabel,
        int $affectedKeywordCount,
        string $strategy,
        string $finalStatus,
        ?string $qualityState,
        string $proposalFingerprint,
        array $keywordIds,
    ): void {
        $this->invalidateLandscapeCache($siteId);
        SiteMcpTopicalProfileStaleState::mark($siteId, 'cluster_proposal_applied');
        $this->logApply(
            siteId: $siteId,
            clusterKey: $clusterKey,
            representativeLabel: $representativeLabel,
            affectedKeywordCount: $affectedKeywordCount,
            strategy: $strategy,
            finalStatus: $finalStatus,
            qualityState: $qualityState,
            proposalFingerprint: $proposalFingerprint,
            keywordIds: $keywordIds,
        );
    }

    /**
     * @param  list<string>  $clusterKeys
     * @param  list<array{
     *     cluster: \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster,
     *     member_ids: list<int>,
     *     cluster_key: string,
     *     proposal_fingerprint: string,
     * }>  $plans
     */
    public function afterBatchApply(
        int $siteId,
        string $mode,
        int $proposalCount,
        int $keywordCount,
        string $strategy,
        string $previewFingerprint,
        array $clusterKeys,
        array $plans,
    ): void {
        $this->invalidateLandscapeCache($siteId);
        SiteMcpTopicalProfileStaleState::mark($siteId, 'cluster_proposal_batch_applied');
        $this->logBatchApply(
            siteId: $siteId,
            mode: $mode,
            proposalCount: $proposalCount,
            keywordCount: $keywordCount,
            strategy: $strategy,
            previewFingerprint: $previewFingerprint,
            clusterKeys: $clusterKeys,
        );

        foreach ($plans as $plan) {
            $cluster = $plan['cluster'];
            $this->logApply(
                siteId: $siteId,
                clusterKey: $plan['cluster_key'],
                representativeLabel: $cluster->representativeLabel,
                affectedKeywordCount: count($plan['member_ids']),
                strategy: $strategy,
                finalStatus: $cluster->finalStatus,
                qualityState: $cluster->quality?->qualityState,
                proposalFingerprint: $plan['proposal_fingerprint'],
                keywordIds: $plan['member_ids'],
            );
        }
    }

    /**
     * @param  array<string, int>  $metrics
     */
    public function afterRecluster(int $siteId, array $metrics): void
    {
        $this->invalidateLandscapeCache($siteId);
        SiteMcpTopicalProfileStaleState::mark($siteId, 'recluster_completed');

        try {
            if (! function_exists('app') || ! app()->bound(OperationLogger::class)) {
                return;
            }

            app(OperationLogger::class)->info('keyword_cluster.recluster_completed', [
                'site_id' => $siteId,
                'actor_id' => auth()->id(),
                ...$metrics,
            ]);
        } catch (Throwable) {
            // Best-effort audit only.
        }
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

    /**
     * @param  list<int>  $keywordIds
     */
    private function logApply(
        int $siteId,
        string $clusterKey,
        string $representativeLabel,
        int $affectedKeywordCount,
        string $strategy,
        string $finalStatus,
        ?string $qualityState,
        string $proposalFingerprint,
        array $keywordIds,
    ): void {
        try {
            if (! function_exists('app') || ! app()->bound(OperationLogger::class)) {
                return;
            }

            app(OperationLogger::class)->info('keyword_cluster.proposal_applied', [
                'site_id' => $siteId,
                'actor_id' => auth()->id(),
                'cluster_key' => $clusterKey,
                'representative' => $representativeLabel,
                'member_count' => $affectedKeywordCount,
                'keyword_ids' => $keywordIds,
                'proposal_fingerprint' => $proposalFingerprint,
                'strategy' => $strategy,
                'quality' => $qualityState,
                'final_status' => $finalStatus,
                'algorithm_version' => \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalAlgorithmVersion::CURRENT,
            ]);
        } catch (Throwable) {
            // Best-effort audit only.
        }
    }

    /**
     * @param  list<string>  $clusterKeys
     */
    private function logBatchApply(
        int $siteId,
        string $mode,
        int $proposalCount,
        int $keywordCount,
        string $strategy,
        string $previewFingerprint,
        array $clusterKeys,
    ): void {
        try {
            if (! function_exists('app') || ! app()->bound(OperationLogger::class)) {
                return;
            }

            app(OperationLogger::class)->info('keyword_cluster.proposal_batch_applied', [
                'site_id' => $siteId,
                'actor_id' => auth()->id(),
                'mode' => $mode,
                'proposal_count' => $proposalCount,
                'keyword_count' => $keywordCount,
                'preview_fingerprint' => $previewFingerprint,
                'strategy' => $strategy,
                'cluster_keys' => $clusterKeys,
                'algorithm_version' => \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalAlgorithmVersion::CURRENT,
            ]);
        } catch (Throwable) {
            // Best-effort audit only.
        }
    }
}
