<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterTopicClustersJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ReclusterTopicClustersResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait ReclustersTopicClusters
{
    public bool $confirmRecluster = false;

    public bool $reclusterRunning = false;

    /** @var array<string, mixed>|null */
    public ?array $reclusterResult = null;

    public function canReclusterTopicClusters(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    public function openReclusterConfirm(): void
    {
        if (! $this->canReclusterTopicClusters()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_denied'))
                ->danger()
                ->send();

            return;
        }

        $this->confirmRecluster = true;
    }

    public function cancelReclusterConfirm(): void
    {
        $this->confirmRecluster = false;
    }

    public function confirmDispatchReclusterTopicClusters(): void
    {
        $this->confirmRecluster = false;
        $this->dispatchReclusterTopicClusters();
    }

    public function dispatchReclusterTopicClusters(): void
    {
        if (! $this->canReclusterTopicClusters()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $cacheKey = ReclusterTopicClustersJob::resultCacheKey($siteId);
        $cached = Cache::get($cacheKey);
        $status = is_array($cached) ? (string) ($cached['status'] ?? '') : '';

        if ($status === 'running') {
            $this->reclusterRunning = true;
            $this->reclusterResult = is_array($cached) ? $cached : null;
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_running'))
                ->warning()
                ->send();

            return;
        }

        // Stale "queued" (worker never picked job / wrong queue) — allow redispatch.
        if ($status === 'queued' && ! $this->isReclusterCacheStale($cached)) {
            $this->reclusterRunning = true;
            $this->reclusterResult = is_array($cached) ? $cached : null;
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_running'))
                ->warning()
                ->send();

            return;
        }

        Cache::put($cacheKey, [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'queue' => ReclusterTopicClustersJob::QUEUE_NAME,
        ], 3600);

        ReclusterTopicClustersJob::dispatch($siteId);
        $this->reclusterRunning = true;
        $this->reclusterResult = [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'queue' => ReclusterTopicClustersJob::QUEUE_NAME,
        ];

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_recluster_dispatched'))
            ->body(__('seo-content-ai::filament.keyword.topic_recluster_dispatched_body', [
                'queue' => ReclusterTopicClustersJob::QUEUE_NAME,
            ]))
            ->success()
            ->send();
    }

    public function pollReclusterResult(): void
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return;
        }

        $cached = Cache::get(ReclusterTopicClustersJob::resultCacheKey($siteId));
        if (! is_array($cached)) {
            return;
        }

        $status = (string) ($cached['status'] ?? '');

        if ($status === 'queued' && $this->isReclusterCacheStale($cached)) {
            Cache::put(ReclusterTopicClustersJob::resultCacheKey($siteId), [
                'status' => 'failed',
                'error' => __('seo-content-ai::filament.keyword.topic_recluster_stale_queue', [
                    'queue' => ReclusterTopicClustersJob::QUEUE_NAME,
                ]),
                'finished_at' => now()->toIso8601String(),
                'queue' => ReclusterTopicClustersJob::QUEUE_NAME,
            ], 3600);
            $this->reclusterRunning = false;
            $this->reclusterResult = Cache::get(ReclusterTopicClustersJob::resultCacheKey($siteId));

            return;
        }

        if ($status === 'queued' || $status === 'running') {
            $this->reclusterRunning = true;
            $this->reclusterResult = $cached;

            return;
        }

        if ($status === 'completed' || $status === 'failed') {
            $this->reclusterRunning = false;
            $this->reclusterResult = $cached;

            if ($status === 'completed' && method_exists($this, 'resetPage')) {
                $this->resetPage();
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $cached
     */
    private function isReclusterCacheStale(?array $cached): bool
    {
        if ($cached === null) {
            return true;
        }

        $queuedAt = (string) ($cached['queued_at'] ?? '');
        if ($queuedAt === '') {
            return true;
        }

        try {
            return \Illuminate\Support\Carbon::parse($queuedAt)->lt(now()->subSeconds(90));
        } catch (\Throwable) {
            return true;
        }
    }

    public function runReclusterSynchronouslyForTests(): ReclusterTopicClustersResult
    {
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();

        return app(ReclusterTopicClustersService::class)->recluster($siteId);
    }
}
