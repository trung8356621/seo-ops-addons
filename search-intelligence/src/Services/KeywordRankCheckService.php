<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Jobs\RunKeywordGroupMetricBatchJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\RunKeywordRankCheckBatchJob;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankCheckRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroupItem;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Illuminate\Support\Facades\DB;

final class KeywordRankCheckService
{
    private const CHUNK_SIZE = 25;

    public function __construct(
        private readonly SeoSerpProviderConnectionService $serpConnections,
        private readonly SeoRankKeywordGroupService $rankGroups,
        private readonly SerpProviderCapabilityService $capabilities,
        private readonly KeywordSearchVolumeService $searchVolume,
    ) {}

    /**
     * @param  list<string>  $metrics
     * @return array{queued: bool, keyword_count: int, run_id: int|null, metrics: list<string>}
     */
    public function dispatchForGroup(
        int $groupId,
        int $userId,
        string $provider,
        array $metrics = ['rank'],
    ): array {
        if (! SerpProviderKeys::isValid($provider)) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.invalid_rank_provider'));
        }

        $group = $this->rankGroups->findAccessibleGroup($groupId, $userId);
        if ($group === null) {
            throw new \RuntimeException(__('seo-content-ai::filament.rank_group.not_accessible'));
        }

        $connection = $this->serpConnections->resolveForUser($userId, $provider);
        if ($connection === null || ! $connection->isConfigured()) {
            throw new \RuntimeException(__('seo-content-ai::filament.api_connections.serp_not_configured'));
        }

        $this->reconcileStaleRuns($groupId, $provider);

        if ($this->hasActiveRunForGroup($groupId, $provider)) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.rank_check_already_running'));
        }

        $items = SeoRankKeywordGroupItem::query()
            ->where('group_id', $group->id)
            ->orderBy('id')
            ->get(['id', 'keyword_id']);

        if ($items->isEmpty()) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.rank_check_no_keywords'));
        }

        $metrics = $this->capabilities->filterDispatchableMetrics($userId, $provider, $metrics, $this->searchVolume);
        if ($metrics === []) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.run_no_metrics_available'));
        }

        $connectionHash = SeoConnectionContext::hash();
        if ($connectionHash === null) {
            throw new \RuntimeException(__('seo-content-ai::filament.rank_group.missing_connection_context'));
        }

        $operationCount = $items->count() * count($metrics);

        $run = DB::connection('omi_seo_ai')->transaction(function () use (
            $group,
            $userId,
            $items,
            $provider,
            $connection,
            $connectionHash,
            $metrics,
            $operationCount,
        ): KeywordRankCheckRun {
            return KeywordRankCheckRun::query()->create([
                'site_id' => null,
                'rank_group_id' => (int) $group->id,
                'connection_hash' => $connectionHash,
                'user_id' => $userId,
                'status' => 'pending',
                'run_type' => 'batch',
                'total_keywords' => $operationCount,
                'processed_keywords' => 0,
                'failed_keywords' => 0,
                'provider' => $provider,
                'connection_id' => (int) $connection->id,
                'country' => $group->country_code,
                'location' => $group->location,
                'language' => $group->language_code,
                'device' => $group->device,
                'started_at' => now(),
                'metadata' => ['metrics' => $metrics],
            ]);
        });

        $itemPayload = $items->map(static fn (SeoRankKeywordGroupItem $item): array => [
            'item_id' => (int) $item->id,
            'keyword_id' => (int) $item->keyword_id,
        ])->values()->all();

        foreach ($metrics as $metric) {
            foreach (array_chunk($itemPayload, self::CHUNK_SIZE) as $chunk) {
                if ($metric === 'rank') {
                    RunKeywordRankCheckBatchJob::dispatch(
                        runId: (int) $run->id,
                        userId: $userId,
                        provider: $provider,
                        groupItems: $chunk,
                        connectionHash: $connectionHash,
                        country: $group->country_code,
                        location: $group->location,
                        language: $group->language_code,
                        device: $group->device,
                        trackedDomain: $group->target_domain,
                    )->onQueue('seo');
                } else {
                    RunKeywordGroupMetricBatchJob::dispatch(
                        runId: (int) $run->id,
                        userId: $userId,
                        provider: $provider,
                        metricType: $metric,
                        groupItems: $chunk,
                        connectionHash: $connectionHash,
                        country: $group->country_code,
                        location: $group->location,
                        language: $group->language_code,
                        device: $group->device,
                    )->onQueue('seo');
                }
            }
        }

        $run->status = 'running';
        $run->save();

        return [
            'queued' => true,
            'keyword_count' => $items->count(),
            'run_id' => (int) $run->id,
            'metrics' => $metrics,
        ];
    }

    /**
     * @deprecated Use dispatchForGroup — kept for legacy site-scoped callers during transition.
     *
     * @return array{queued: bool, keyword_count: int, run_id: int|null}
     */
    public function dispatchForSite(
        int $siteId,
        int $userId,
        string $provider,
        ?string $country = null,
        ?string $location = null,
        ?string $language = null,
        ?string $device = null,
    ): array {
        unset($country, $location, $language, $device);

        throw new \RuntimeException(__('seo-content-ai::filament.rank_group.use_group_dispatch'));
    }

    public function hasActiveRunForGroup(int $groupId, string $provider): bool
    {
        return KeywordRankCheckRun::query()
            ->where('rank_group_id', $groupId)
            ->where('provider', $provider)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    public function hasActiveRun(int $siteId, string $provider): bool
    {
        return KeywordRankCheckRun::query()
            ->where('site_id', $siteId)
            ->where('provider', $provider)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    public function reconcileStaleRuns(?int $groupId = null, ?string $provider = null): int
    {
        $query = KeywordRankCheckRun::query()
            ->whereIn('status', ['pending', 'running']);

        if ($groupId !== null && $groupId > 0) {
            $query->where('rank_group_id', $groupId);
        }

        if ($provider !== null && SerpProviderKeys::isValid($provider)) {
            $query->where('provider', $provider);
        }

        $reconciled = 0;

        foreach ($query->get() as $run) {
            if (! $this->shouldReconcileRun($run)) {
                continue;
            }

            $total = (int) $run->total_keywords;
            $done = (int) $run->processed_keywords + (int) $run->failed_keywords;

            if ($total > 0 && $done >= $total) {
                $run->status = 'completed';
                $run->completed_at = $run->completed_at ?? now();
            } else {
                $run->status = 'failed';
                $run->completed_at = now();
                $run->last_error = $run->last_error
                    ?: __('seo-content-ai::filament.performance_hub.rank_check_stale_reconciled');
            }

            $run->save();
            $reconciled++;
        }

        return $reconciled;
    }

    private function shouldReconcileRun(KeywordRankCheckRun $run): bool
    {
        $total = (int) $run->total_keywords;
        $done = (int) $run->processed_keywords + (int) $run->failed_keywords;

        if ($total > 0 && $done >= $total) {
            return true;
        }

        $started = $run->started_at ?? $run->created_at;
        if ($started === null) {
            return true;
        }

        $idleMinutes = $done === 0 ? 3 : 20;

        return $started->lt(now()->subMinutes($idleMinutes));
    }
}
