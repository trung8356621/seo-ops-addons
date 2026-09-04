<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterTopicClustersJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ReclusterTopicClustersResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterReclusterState;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
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

    public function isTopicMutationLocked(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return $siteId !== null
            && $siteId > 0
            && TopicClusterReclusterState::isMutationLocked((int) $siteId);
    }

    /**
     * Permission + site access only (ignores recluster lock). Used to keep controls visible but disabled.
     */
    public function hasTopicClusterMutationPermission(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    protected function syncReclusterStateFromCache(): void
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            $this->reclusterRunning = false;

            return;
        }

        $state = TopicClusterReclusterState::stateForSite((int) $siteId);
        if ($state === null) {
            $this->reclusterRunning = false;

            return;
        }

        $status = (string) ($state['status'] ?? '');
        if ($status === 'queued' || $status === 'running') {
            $this->reclusterRunning = true;
            $this->reclusterResult = $state;

            return;
        }

        $this->reclusterRunning = false;
        $this->reclusterResult = $state;
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

        if ($this->isTopicMutationLocked()) {
            $this->syncReclusterStateFromCache();
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_running'))
                ->warning()
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
        $this->closeTopicMutationUiBeforeRecluster();
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
        $this->closeTopicMutationUiBeforeRecluster();

        $state = TopicClusterReclusterState::stateForSite($siteId);
        $status = is_array($state) ? (string) ($state['status'] ?? '') : '';

        if ($status === 'running' || $status === 'queued') {
            $this->reclusterRunning = true;
            $this->reclusterResult = $state;
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_running'))
                ->warning()
                ->send();

            return;
        }

        $cacheKey = ReclusterTopicClustersJob::resultCacheKey($siteId);
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

        $wasPolling = $this->reclusterRunning;
        $state = TopicClusterReclusterState::stateForSite((int) $siteId);
        if ($state === null) {
            return;
        }

        $status = (string) ($state['status'] ?? '');

        if ($status === 'queued' || $status === 'running') {
            $this->reclusterRunning = true;
            $this->reclusterResult = $state;

            return;
        }

        if ($status === 'completed' || $status === 'failed') {
            $this->reclusterRunning = false;
            $this->reclusterResult = $state;

            // Only the active polling session observes the transition → one full refresh.
            if (! $wasPolling) {
                return;
            }

            if ($status === 'failed') {
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                    ->body((string) ($state['error'] ?? ''))
                    ->danger()
                    ->send();
            }

            $this->redirect($this->reclusterCompletionRedirectUrl(), navigate: false);
        }
    }

    protected function reclusterCompletionRedirectUrl(): string
    {
        if (method_exists($this, 'clusterDetailPageUrl')) {
            return (string) $this->clusterDetailPageUrl();
        }

        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('clusters'),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    protected function closeTopicMutationUiBeforeRecluster(): void
    {
        $this->confirmRecluster = false;

        if (method_exists($this, 'closeMcpGroupModal')) {
            $this->closeMcpGroupModal();
        }

        if (property_exists($this, 'moveClusterKeywordId')) {
            $this->moveClusterKeywordId = null;
            $this->moveClusterTargetKey = '';
            $this->moveClusterOptions = [];
            $this->dispatch('close-modal', id: 'keyword-move-cluster-modal');
        }
    }

    public function runReclusterSynchronouslyForTests(): ReclusterTopicClustersResult
    {
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();

        return app(ReclusterTopicClustersService::class)->recluster($siteId);
    }
}
