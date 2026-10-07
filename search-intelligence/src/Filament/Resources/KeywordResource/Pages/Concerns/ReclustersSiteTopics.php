<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterAlgorithm;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Topic recluster UI: confirm modal → queue Analyze→Apply (no manual proposal review).
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

    /** Full-reset checkbox is always available on the Topic UI (semantic pipeline). */
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

    /** User-facing Topic recluster always uses semantic_http (not global TOPIC_GROUPING_PROVIDER). */
    public function selectedGroupingProvider(): string
    {
        return TopicGroupingProviderMode::SEMANTIC_HTTP;
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
        // Brief internal proposal_ready between Analyze and Apply still counts as in-flight.
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
        $this->showReclusterModal = true;
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

    /** @deprecated BC alias */
    public function confirmDispatchReclusterTopicClusters(): void
    {
        $this->startTopicAnalysis();
    }

    public function canReclusterTopicClusters(): bool
    {
        return $this->canReclusterTopics();
    }

    public function startTopicAnalysis(bool $sync = false): void
    {
        $this->runTopicRecluster($sync);
    }

    public function runTopicRecluster(bool $sync = false): void
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

        $version = TopicReclusterAlgorithm::VERSION;
        // Capture at dispatch — Job must not re-read checkbox or global TOPIC_GROUPING_PROVIDER.
        $rebuildMode = $this->selectedRebuildMode();
        $provider = $this->selectedGroupingProvider();

        $this->showReclusterModal = true;
        $this->confirmRecluster = true;
        $this->reclusterModalError = null;

        TopicReclusterUiState::markQueued($siteId, $provider, $rebuildMode);
        $this->reclusterRunning = true;
        $this->reclusterResult = TopicReclusterUiState::get($siteId);
        $this->reclusterModalStep = self::RECLUSTER_STEP_ANALYZING;

        if ($sync) {
            ReclusterSiteTopicsJob::dispatchSync($siteId, $version, $rebuildMode, $provider);
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

        ReclusterSiteTopicsJob::dispatch($siteId, $version, $rebuildMode, $provider);
        Notification::make()
            ->title('Đang tách lại chủ đề…')
            ->body(TopicGroupingRebuildMode::isFullReset($rebuildMode)
                ? 'Mode: Xóa cấu trúc Topic cũ và tách lại từ đầu'
                : 'Mode: Giữ cấu trúc Topic hiện tại')
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

        // Operational progress only — never surface historical proposal_ready as a review CTA.
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

        // Internal Analyze→Apply handoff — generic copy, no Preview CTA.
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

        // Discarded / idle / unknown — fresh confirm modal (do not revive old proposals).
        $this->reclusterModalStep = self::RECLUSTER_STEP_CONFIGURE;
        $this->fullResetTopicStructure = false;
    }
}
