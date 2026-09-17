<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait ReclustersSiteTopics
{
    public bool $confirmRecluster = false;

    public bool $reclusterRunning = false;

    /** @var array<string, mixed>|null */
    public ?array $reclusterResult = null;

    public function canReclusterTopics(): bool
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
            && TopicReclusterUiState::isMutationLocked((int) $siteId);
    }

    public function hasTopicMutationPermission(): bool
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

        $state = TopicReclusterUiState::get((int) $siteId);
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

    public function beginConfirmRecluster(): void
    {
        if (! $this->canReclusterTopics()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_denied'))
                ->danger()
                ->send();

            return;
        }
        $this->confirmRecluster = true;
    }

    /** @deprecated BC alias for golden Blade wire:click */
    public function openReclusterConfirm(): void
    {
        $this->beginConfirmRecluster();
    }

    public function cancelConfirmRecluster(): void
    {
        $this->confirmRecluster = false;
    }

    /** @deprecated BC alias for golden Blade wire:click */
    public function cancelReclusterConfirm(): void
    {
        $this->cancelConfirmRecluster();
    }

    /** @deprecated BC alias for golden Blade wire:click */
    public function confirmDispatchReclusterTopicClusters(): void
    {
        $this->runTopicRecluster();
    }

    public function canReclusterTopicClusters(): bool
    {
        return $this->canReclusterTopics();
    }

    public function runTopicRecluster(bool $sync = false): void
    {
        $this->confirmRecluster = false;
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        if (! $this->canReclusterTopics() || $siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_denied'))
                ->danger()
                ->send();

            return;
        }

        if ($this->isTopicMutationLocked()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_already_running'))
                ->warning()
                ->send();
            $this->syncReclusterStateFromCache();

            return;
        }

        if (! TopicReclusterService::tablesReady()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                ->body('Topic Core tables missing')
                ->danger()
                ->send();

            return;
        }

        if ($sync) {
            TopicReclusterUiState::markRunning($siteId);
            $this->reclusterRunning = true;
            $result = app(TopicReclusterService::class)->recluster($siteId);
            if ($result->ok) {
                TopicReclusterUiState::markCompleted($siteId, $result->metrics);
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterRunning = false;
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.topic_recluster_result_title'))
                    ->success()
                    ->send();
            } else {
                TopicReclusterUiState::markFailed($siteId, $result->error ?? 'failed', $result->metrics);
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterRunning = false;
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                    ->body((string) ($result->error ?? ''))
                    ->danger()
                    ->send();
            }
            if (method_exists($this, 'refreshClusterSummaryCounters')) {
                $this->refreshClusterSummaryCounters();
            }

            return;
        }

        TopicReclusterUiState::markQueued($siteId);
        $this->reclusterRunning = true;
        $this->reclusterResult = TopicReclusterUiState::get($siteId);
        ReclusterSiteTopicsJob::dispatch($siteId);
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_recluster_queued_title'))
            ->success()
            ->send();
    }

    public function pollReclusterResult(): void
    {
        $wasRunning = $this->reclusterRunning;
        $this->syncReclusterStateFromCache();
        if ($wasRunning && ! $this->reclusterRunning && method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }
    }
}
