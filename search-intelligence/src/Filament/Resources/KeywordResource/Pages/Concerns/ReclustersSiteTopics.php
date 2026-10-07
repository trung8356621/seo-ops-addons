<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Jobs\RebuildTopicsFromKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromGroupSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromKeywordGroupMaterializer;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Topic rebuild UI: Alpine configure modal → queue Group→Topic materializer.
 *
 * Does not dispatch semantic regrouping ({@see ReclusterSiteTopicsJob} is legacy/compat only).
 */
trait ReclustersSiteTopics
{
    public const RECLUSTER_STEP_CONFIGURE = 'configure';

    public const RECLUSTER_STEP_ANALYZING = 'analyzing';

    public const RECLUSTER_STEP_PREPARING_APPLY = 'preparing_apply';

    public const RECLUSTER_STEP_APPLYING = 'applying';

    public const RECLUSTER_STEP_APPLIED = 'applied';

    public const RECLUSTER_STEP_FAILED = 'failed';

    /** @deprecated Use showReclusterModal — kept for BC wire:click aliases */
    public bool $confirmRecluster = false;

    /** Progress / result modal only — configure open is Alpine client-side. */
    public bool $showReclusterModal = false;

    /** configure|analyzing|preparing_apply|applying|applied|failed */
    public string $reclusterModalStep = self::RECLUSTER_STEP_CONFIGURE;

    /** UI checkbox only at dispatch; run.rebuild_mode is authoritative after that. */
    public bool $fullResetTopicStructure = false;

    public bool $reclusterRunning = false;

    /** @var array<string, mixed>|null */
    public ?array $reclusterResult = null;

    public ?string $reclusterModalError = null;

    public function canReclusterTopics(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    /** Full-reset checkbox is always available on the Topic UI. */
    public function canUseFullResetRebuildMode(): bool
    {
        return true;
    }

    public function selectedRebuildMode(): string
    {
        return $this->fullResetTopicStructure
            ? TopicGroupingRebuildMode::FULL_RESET
            : TopicGroupingRebuildMode::PRESERVE_EXISTING;
    }

    /**
     * @deprecated Topic UI no longer uses a grouping provider.
     * Kept for BC contract tests referencing the method name.
     */
    public function selectedGroupingProvider(): string
    {
        return TopicFromKeywordGroupMaterializer::ALGORITHM;
    }

    public function persistedRebuildMode(): string
    {
        $fromUi = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['metrics']['rebuild_mode'] ?? '')
            : '';
        if ($fromUi !== '') {
            return TopicGroupingRebuildMode::normalize($fromUi);
        }

        return TopicGroupingRebuildMode::PRESERVE_EXISTING;
    }

    public function isFullResetPersisted(): bool
    {
        return TopicGroupingRebuildMode::isFullReset($this->persistedRebuildMode());
    }

    /**
     * Cheap snapshot embedded at page render — do not query on modal open.
     *
     * @return array{group_count: int, topic_candidate_count: int, topic_no_focus_count: int, topic_blocked_count: int}
     */
    public function topicFromGroupSnapshot(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return TopicFromGroupSnapshot::forSite($siteId);
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
        if (TopicReclusterUiState::isActiveStatus($status)
            || $status === TopicReclusterUiState::STATUS_PROPOSAL_READY
        ) {
            $this->reclusterRunning = true;
            $this->reclusterResult = $state;

            return;
        }

        $this->reclusterRunning = false;
        $this->reclusterResult = $state;
    }

    /**
     * Open progress/result modal only (after job started or on failure banner).
     * Idle configure modal must open via Alpine — not this method.
     */
    public function openReclusterModal(): void
    {
        if (! $this->canReclusterTopics()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_denied'))
                ->danger()
                ->send();

            return;
        }

        $this->syncReclusterStateFromCache();
        $this->showReclusterModal = true;
        $this->confirmRecluster = true;
        $this->reclusterModalError = null;
        $this->hydrateReclusterModalStepFromState();
    }

    /** @deprecated BC alias */
    public function beginConfirmRecluster(): void
    {
        $this->openReclusterModal();
    }

    /** @deprecated BC alias */
    public function openReclusterConfirm(): void
    {
        $this->openReclusterModal();
    }

    public function closeReclusterModal(): void
    {
        $this->showReclusterModal = false;
        $this->confirmRecluster = false;
    }

    public function retryReclusterConfigure(): void
    {
        $this->reclusterModalError = null;
        $this->reclusterModalStep = self::RECLUSTER_STEP_CONFIGURE;
        $this->fullResetTopicStructure = false;
        $this->showReclusterModal = false;
    }

    /** @deprecated BC alias */
    public function cancelConfirmRecluster(): void
    {
        $this->closeReclusterModal();
    }

    /** @deprecated BC alias */
    public function cancelReclusterConfirm(): void
    {
        $this->closeReclusterModal();
    }

    /** @deprecated BC alias — use startTopicRebuildFromGroups */
    public function confirmDispatchReclusterTopicClusters(): void
    {
        $this->startTopicRebuildFromGroups($this->fullResetTopicStructure);
    }

    public function canReclusterTopicClusters(): bool
    {
        return $this->canReclusterTopics();
    }

    /** @deprecated BC alias — use startTopicRebuildFromGroups */
    public function startTopicAnalysis(bool $sync = false): void
    {
        $this->startTopicRebuildFromGroups($this->fullResetTopicStructure, $sync);
    }

    /** @deprecated BC alias — use startTopicRebuildFromGroups */
    public function runTopicRecluster(bool $sync = false): void
    {
        $this->startTopicRebuildFromGroups($this->fullResetTopicStructure, $sync);
    }

    /**
     * Sole Livewire entry for user-facing Topic rebuild confirm.
     * fullReset is passed explicitly from Alpine — do not rely on wire:model sync.
     */
    public function startTopicRebuildFromGroups(bool $fullReset = false, bool $sync = false): void
    {
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
            $this->hydrateReclusterModalStepFromState();
            $this->showReclusterModal = true;

            return;
        }

        if (! TopicReclusterService::tablesReady()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                ->body(__('Topic Core tables missing'))
                ->danger()
                ->send();

            return;
        }

        $this->fullResetTopicStructure = $fullReset;
        $rebuildMode = $fullReset
            ? TopicGroupingRebuildMode::FULL_RESET
            : TopicGroupingRebuildMode::PRESERVE_EXISTING;

        $this->showReclusterModal = true;
        $this->confirmRecluster = true;
        $this->reclusterModalError = null;

        TopicReclusterUiState::put($siteId, [
            'status' => TopicReclusterUiState::STATUS_QUEUED,
            'site_id' => $siteId,
            'mode' => TopicGroupingProviderMode::LEGACY,
            'algorithm_version' => TopicFromKeywordGroupMaterializer::ALGORITHM,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'metrics' => ['rebuild_mode' => $rebuildMode, 'source' => TopicFromKeywordGroupMaterializer::ALGORITHM],
            'error' => null,
            'failure_reason' => null,
        ]);
        $this->reclusterRunning = true;
        $this->reclusterResult = TopicReclusterUiState::get($siteId);
        $this->reclusterModalStep = self::RECLUSTER_STEP_ANALYZING;

        if ($sync) {
            RebuildTopicsFromKeywordGroupsJob::dispatchSync($siteId, $rebuildMode);
            $this->syncReclusterStateFromCache();
            $this->hydrateReclusterModalStepFromState();
            $status = is_array($this->reclusterResult)
                ? (string) ($this->reclusterResult['status'] ?? '')
                : '';
            if (in_array($status, [
                TopicReclusterUiState::STATUS_APPLIED,
                TopicReclusterUiState::STATUS_SUCCEEDED,
            ], true)) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.topic_recluster_result_title'))
                    ->success()
                    ->send();
            } elseif (in_array($status, [
                TopicReclusterUiState::STATUS_FAILED,
                TopicReclusterUiState::STATUS_APPLY_FAILED,
                TopicReclusterUiState::STATUS_STALE,
            ], true)) {
                $this->reclusterModalError = (string) ($this->reclusterResult['error'] ?? 'failed');
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                    ->body($this->reclusterModalError)
                    ->danger()
                    ->send();
            }
            if (method_exists($this, 'refreshClusterSummaryCounters')) {
                $this->refreshClusterSummaryCounters();
            }

            return;
        }

        RebuildTopicsFromKeywordGroupsJob::dispatch($siteId, $rebuildMode);
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_recluster_action_running'))
            ->body(TopicGroupingRebuildMode::isFullReset($rebuildMode)
                ? __('seo-content-ai::filament.keyword.topic_rebuild_mode_full_reset')
                : __('seo-content-ai::filament.keyword.topic_rebuild_mode_preserve'))
            ->success()
            ->send();
    }

    public function pollReclusterResult(): void
    {
        $wasRunning = $this->reclusterRunning;
        $previousStatus = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';
        $this->syncReclusterStateFromCache();
        $nowStatus = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';

        if ($this->showReclusterModal) {
            $this->hydrateReclusterModalStepFromState();
        }

        if ($wasRunning && ! $this->reclusterRunning && method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }

        if (
            $this->isOperationalInFlight($previousStatus)
            && in_array($nowStatus, [
                TopicReclusterUiState::STATUS_SUCCEEDED,
                TopicReclusterUiState::STATUS_APPLIED,
            ], true)
        ) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_result_title'))
                ->success()
                ->send();
        }

        if (
            $this->isOperationalInFlight($previousStatus)
            && in_array($nowStatus, [
                TopicReclusterUiState::STATUS_FAILED,
                TopicReclusterUiState::STATUS_APPLY_FAILED,
                TopicReclusterUiState::STATUS_STALE,
            ], true)
        ) {
            $error = (string) ($this->reclusterResult['error'] ?? '');
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                ->body($error !== '' ? $error : null)
                ->danger()
                ->send();
        }
    }

    public function hasPendingReclusterProposal(): bool
    {
        $this->syncReclusterStateFromCache();
        $status = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';

        return in_array($status, [
            TopicReclusterUiState::STATUS_QUEUED,
            TopicReclusterUiState::STATUS_ANALYZING,
            TopicReclusterUiState::STATUS_RUNNING,
            TopicReclusterUiState::STATUS_APPLYING,
            TopicReclusterUiState::STATUS_PROPOSAL_READY,
            TopicReclusterUiState::STATUS_FAILED,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
        ], true);
    }

    private function isOperationalInFlight(string $status): bool
    {
        return TopicReclusterUiState::isActiveStatus($status)
            || $status === TopicReclusterUiState::STATUS_PROPOSAL_READY;
    }

    private function hydrateReclusterModalStepFromState(): void
    {
        $status = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';

        if (in_array($status, [
            TopicReclusterUiState::STATUS_QUEUED,
            TopicReclusterUiState::STATUS_ANALYZING,
            TopicReclusterUiState::STATUS_RUNNING,
        ], true)) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_ANALYZING;

            return;
        }

        if ($status === TopicReclusterUiState::STATUS_PROPOSAL_READY) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_PREPARING_APPLY;

            return;
        }

        if ($status === TopicReclusterUiState::STATUS_APPLYING) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_APPLYING;

            return;
        }

        if ($status === TopicReclusterUiState::STATUS_APPLIED
            || $status === TopicReclusterUiState::STATUS_SUCCEEDED
        ) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_APPLIED;

            return;
        }

        if (in_array($status, [
            TopicReclusterUiState::STATUS_FAILED,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
            TopicReclusterUiState::STATUS_STALE,
        ], true)) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
            $this->reclusterModalError = (string) ($this->reclusterResult['error'] ?? 'failed');

            return;
        }

        $this->reclusterModalStep = self::RECLUSTER_STEP_CONFIGURE;
        $this->fullResetTopicStructure = false;
    }
}
