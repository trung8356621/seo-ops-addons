<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyResult;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingAnalysisService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterAlgorithm;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait ReclustersSiteTopics
{
    public const RECLUSTER_STEP_CONFIGURE = 'configure';

    public const RECLUSTER_STEP_ANALYZING = 'analyzing';

    public const RECLUSTER_STEP_PROPOSAL_READY = 'proposal_ready';

    public const RECLUSTER_STEP_PREVIEW = 'preview';

    public const RECLUSTER_STEP_CONFIRM_APPLY = 'confirm_apply';

    public const RECLUSTER_STEP_APPLYING = 'applying';

    public const RECLUSTER_STEP_APPLIED = 'applied';

    public const RECLUSTER_STEP_FAILED = 'failed';

    /** @deprecated Use showReclusterModal — kept for BC wire:click aliases */
    public bool $confirmRecluster = false;

    public bool $showReclusterModal = false;

    /** configure|analyzing|proposal_ready|preview|confirm_apply|applying|applied|failed */
    public string $reclusterModalStep = self::RECLUSTER_STEP_CONFIGURE;

    /** UI checkbox only at Analyze dispatch; run.rebuild_mode is authoritative after that. */
    public bool $fullResetTopicStructure = false;

    public bool $reclusterRunning = false;

    /** @deprecated Inline preview retired — modal owns preview */
    public bool $showProposalPreview = false;

    public bool $confirmApplyProposal = false;

    /** @var array<string, mixed>|null */
    public ?array $proposalPreview = null;

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

    public function canUseFullResetRebuildMode(): bool
    {
        return TopicGroupingProviderMode::isSemanticHttp();
    }

    public function selectedRebuildMode(): string
    {
        if (! $this->canUseFullResetRebuildMode() || ! $this->fullResetTopicStructure) {
            return TopicGroupingRebuildMode::PRESERVE_EXISTING;
        }

        return TopicGroupingRebuildMode::FULL_RESET;
    }

    public function persistedRebuildMode(): string
    {
        $fromPreview = is_array($this->proposalPreview)
            ? (string) ($this->proposalPreview['rebuild_mode'] ?? '')
            : '';
        if ($fromPreview !== '') {
            return TopicGroupingRebuildMode::normalize($fromPreview);
        }

        $fromUi = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['metrics']['rebuild_mode'] ?? '')
            : '';
        if ($fromUi !== '') {
            return TopicGroupingRebuildMode::normalize($fromUi);
        }

        $runId = $this->currentProposalRunId();
        if ($runId > 0) {
            $run = SeoTopicGroupingRun::query()->find($runId);
            if ($run instanceof SeoTopicGroupingRun) {
                return $run->rebuildMode();
            }
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
        if (TopicReclusterUiState::isActiveStatus($status)) {
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
        $this->confirmApplyProposal = false;
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
        $this->confirmApplyProposal = false;
        $this->showProposalPreview = false;
        // Keep proposalPreview / reclusterResult so reopen restores step.
        if ($this->reclusterModalStep === self::RECLUSTER_STEP_CONFIRM_APPLY) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_PREVIEW;
        }
    }

    public function retryReclusterConfigure(): void
    {
        $this->reclusterModalError = null;
        $this->proposalPreview = null;
        $this->confirmApplyProposal = false;
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
        $semantic = TopicGroupingProviderMode::isSemanticHttp();
        // Capture mode at dispatch — do not re-read checkbox later in the worker.
        $rebuildMode = $this->selectedRebuildMode();

        $this->showReclusterModal = true;
        $this->confirmRecluster = true;
        $this->proposalPreview = null;
        $this->confirmApplyProposal = false;
        $this->reclusterModalError = null;

        if ($sync) {
            if ($semantic) {
                TopicReclusterUiState::markQueued($siteId, TopicGroupingProviderMode::SEMANTIC_HTTP, $rebuildMode);
                $this->reclusterRunning = true;
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterModalStep = self::RECLUSTER_STEP_ANALYZING;
                $run = app(TopicGroupingAnalysisService::class)->analyzeSite($siteId, $rebuildMode);
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterRunning = TopicReclusterUiState::isAnalyzeActive($siteId);
                $this->hydrateReclusterModalStepFromState();
                if ($run->isProposalReady()) {
                    Notification::make()
                        ->title('Phân tích hoàn tất')
                        ->body(sprintf(
                            '%d nhóm · %d chưa gán · %d low confidence',
                            (int) $run->group_count,
                            (int) $run->unassigned_count,
                            (int) $run->low_confidence_count,
                        ))
                        ->success()
                        ->send();
                } else {
                    $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
                    $this->reclusterModalError = (string) ($run->error_message ?? 'analysis_failed');
                    Notification::make()
                        ->title('Topic analysis failed')
                        ->body($this->reclusterModalError)
                        ->danger()
                        ->send();
                }

                return;
            }

            TopicReclusterUiState::markRunning($siteId, $version);
            $this->reclusterRunning = true;
            $this->reclusterResult = TopicReclusterUiState::get($siteId);
            $this->reclusterModalStep = self::RECLUSTER_STEP_ANALYZING;
            $result = app(TopicReclusterService::class)->recluster($siteId);
            if ($result->ok) {
                TopicReclusterUiState::markSucceeded($siteId, $result->metrics, $version);
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterRunning = false;
                $this->reclusterModalStep = self::RECLUSTER_STEP_APPLIED;
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.topic_recluster_result_title'))
                    ->success()
                    ->send();
            } else {
                TopicReclusterUiState::markFailed(
                    $siteId,
                    $result->error ?? 'failed',
                    $result->metrics,
                    'recluster_failed',
                    $version,
                );
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterRunning = false;
                $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
                $this->reclusterModalError = (string) ($result->error ?? '');
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

        TopicReclusterUiState::markQueued(
            $siteId,
            $semantic ? TopicGroupingProviderMode::SEMANTIC_HTTP : $version,
            $semantic ? $rebuildMode : null,
        );
        $this->reclusterRunning = true;
        $this->reclusterResult = TopicReclusterUiState::get($siteId);
        $this->reclusterModalStep = self::RECLUSTER_STEP_ANALYZING;
        ReclusterSiteTopicsJob::dispatch($siteId, $version, $rebuildMode);
        Notification::make()
            ->title($semantic ? 'Đang phân tích…' : __('seo-content-ai::filament.keyword.topic_recluster_queued_title'))
            ->body($semantic
                ? (TopicGroupingRebuildMode::isFullReset($rebuildMode)
                    ? 'Mode: Tách lại hoàn toàn — chưa xóa Topic'
                    : 'Mode: Giữ cấu trúc hiện tại — proposal only')
                : __('seo-content-ai::filament.keyword.topic_recluster_running'))
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
            $this->hydrateReclusterModalStepFromState(preservePreview: true);
        }

        if ($wasRunning && ! $this->reclusterRunning && method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }

        if (
            TopicReclusterUiState::isActiveStatus($previousStatus)
            && $nowStatus === TopicReclusterUiState::STATUS_SUCCEEDED
        ) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_result_title'))
                ->success()
                ->send();
        }

        if (
            TopicReclusterUiState::isActiveStatus($previousStatus)
            && $nowStatus === TopicReclusterUiState::STATUS_PROPOSAL_READY
        ) {
            $metrics = is_array($this->reclusterResult['metrics'] ?? null)
                ? $this->reclusterResult['metrics']
                : [];
            Notification::make()
                ->title('Phân tích hoàn tất')
                ->body(sprintf(
                    '%d nhóm · %d chưa gán · %d low confidence',
                    (int) ($metrics['group_count'] ?? 0),
                    (int) ($metrics['unassigned_count'] ?? 0),
                    (int) ($metrics['low_confidence_count'] ?? 0),
                ))
                ->success()
                ->send();
        }

        if (
            TopicReclusterUiState::isActiveStatus($previousStatus)
            && $nowStatus === TopicReclusterUiState::STATUS_FAILED
        ) {
            $error = (string) ($this->reclusterResult['error'] ?? '');
            Notification::make()
                ->title($this->isSemanticProposalContext()
                    ? 'Topic analysis failed'
                    : __('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                ->body($error !== '' ? $error : null)
                ->danger()
                ->send();
        }
    }

    /**
     * Preview is allowed for pending semantic proposals even if config later flips to legacy.
     */
    public function canPreviewProposal(): bool
    {
        if (! $this->canReclusterTopics()) {
            return false;
        }
        $this->syncReclusterStateFromCache();
        if (! $this->isSemanticProposalContext()) {
            return false;
        }
        $status = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';

        return in_array($status, [
            TopicReclusterUiState::STATUS_PROPOSAL_READY,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
            TopicReclusterUiState::STATUS_STALE,
        ], true)
            && $this->currentProposalRunId() > 0;
    }

    public function canApplyProposal(): bool
    {
        if (! $this->canPreviewProposal()) {
            return false;
        }
        $status = (string) ($this->reclusterResult['status'] ?? '');

        if (! in_array($status, [
            TopicReclusterUiState::STATUS_PROPOSAL_READY,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
        ], true)
            || ! is_array($this->proposalPreview)
            || (string) ($this->proposalPreview['plan_hash'] ?? '') === ''
        ) {
            return false;
        }

        return ! (bool) ($this->proposalPreview['business_state']['hard_block'] ?? false);
    }

    public function openProposalPreview(): void
    {
        $this->syncReclusterStateFromCache();
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $runId = $this->currentProposalRunId();

        if (! $this->canPreviewProposal()) {
            Log::warning('topic_grouping.preview.unavailable', [
                'site_id' => $siteId,
                'run_id' => $runId,
                'rebuild_mode' => $this->persistedRebuildMode(),
                'ui_status' => is_array($this->reclusterResult) ? ($this->reclusterResult['status'] ?? null) : null,
                'provider_mode' => TopicGroupingProviderMode::current(),
                'semantic_context' => $this->isSemanticProposalContext(),
                'reason' => 'can_preview_false',
            ]);
            $this->showReclusterModal = true;
            $this->reclusterModalError = 'Không thể tạo bản xem trước (trạng thái / quyền / provider).';
            $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
            Notification::make()
                ->title('Không thể tạo bản xem trước')
                ->body($this->reclusterModalError)
                ->warning()
                ->send();

            return;
        }

        if ($runId <= 0) {
            Log::warning('topic_grouping.preview.missing_run', [
                'site_id' => $siteId,
                'rebuild_mode' => $this->persistedRebuildMode(),
            ]);
            $this->showReclusterModal = true;
            $this->reclusterModalError = 'Thiếu run_id.';
            $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
            Notification::make()->title('Missing run_id')->danger()->send();

            return;
        }

        try {
            $result = app(TopicGroupingApplyService::class)->preview($runId);
        } catch (\Throwable $e) {
            Log::error('topic_grouping.preview.exception', [
                'site_id' => $siteId,
                'run_id' => $runId,
                'rebuild_mode' => $this->persistedRebuildMode(),
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
            $this->showReclusterModal = true;
            $this->reclusterModalError = 'Không thể tạo bản xem trước.';
            $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
            Notification::make()
                ->title('Không thể tạo bản xem trước')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result->ok() || $result->plan === null) {
            $this->syncReclusterStateFromCache();
            Log::warning('topic_grouping.preview.failed', [
                'site_id' => $siteId,
                'run_id' => $runId,
                'rebuild_mode' => $this->persistedRebuildMode(),
                'status' => $result->status,
                'error' => $result->errorMessage,
            ]);
            $this->showReclusterModal = true;
            $this->reclusterModalError = (string) ($result->errorMessage ?? $result->status);
            $this->reclusterModalStep = $result->status === TopicGroupingApplyResult::STALE
                ? self::RECLUSTER_STEP_PROPOSAL_READY
                : self::RECLUSTER_STEP_FAILED;
            Notification::make()
                ->title($result->status === TopicGroupingApplyResult::STALE ? 'Proposal stale' : 'Không thể tạo bản xem trước')
                ->body($this->reclusterModalError)
                ->danger()
                ->send();

            return;
        }

        $plan = $result->plan;
        $this->proposalPreview = [
            'run_id' => $runId,
            'input_hash' => (string) ($result->metrics['input_hash'] ?? ''),
            'plan_hash' => $plan->planHash,
            'rebuild_mode' => $plan->rebuildMode,
            'counts' => $plan->counts,
            'topic_actions' => $plan->topicActions,
            'keyword_actions' => array_values(array_filter(
                $plan->keywordActions,
                static fn (array $a): bool => $a['action'] !== 'keep',
            )),
            'protected_topics' => $plan->protectedTopics,
            'protected_keywords' => $plan->protectedKeywords,
            'warnings' => $plan->warnings,
            'identity_migration' => $plan->identityMigration,
            'business_state' => $plan->businessState,
            'high_churn' => $this->isHighChurnPlan($plan->counts),
        ];
        $this->showReclusterModal = true;
        $this->showProposalPreview = true;
        $this->confirmApplyProposal = false;
        $this->reclusterModalError = null;
        $this->reclusterModalStep = self::RECLUSTER_STEP_PREVIEW;
    }

    /**
     * @param  array<string, int|float>  $counts
     */
    private function isHighChurnPlan(array $counts): bool
    {
        $moved = (int) ($counts['keywords_moved'] ?? 0)
            + (int) ($counts['keywords_assigned'] ?? 0)
            + (int) ($counts['keywords_unassigned'] ?? 0);
        $kept = (int) ($counts['keywords_kept'] ?? 0);
        $ratio = $moved / max(1, $moved + $kept);

        return $ratio >= 0.4
            || (int) ($counts['topics_created'] ?? 0) >= 20
            || (int) ($counts['topics_dissolved'] ?? 0) >= 10;
    }

    public function closeProposalPreview(): void
    {
        $this->showProposalPreview = false;
        $this->confirmApplyProposal = false;
        if ($this->showReclusterModal && $this->canPreviewProposal()) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_PROPOSAL_READY;
        }
    }

    public function backToProposalReady(): void
    {
        $this->confirmApplyProposal = false;
        $this->showProposalPreview = false;
        $this->reclusterModalStep = self::RECLUSTER_STEP_PROPOSAL_READY;
    }

    public function beginConfirmApplyProposal(): void
    {
        if (! $this->canApplyProposal()) {
            Notification::make()->title('Apply unavailable')->warning()->send();

            return;
        }
        $this->confirmApplyProposal = true;
        $this->reclusterModalStep = self::RECLUSTER_STEP_CONFIRM_APPLY;
    }

    public function cancelConfirmApplyProposal(): void
    {
        $this->confirmApplyProposal = false;
        $this->reclusterModalStep = self::RECLUSTER_STEP_PREVIEW;
    }

    public function applyProposal(): void
    {
        $this->confirmApplyProposal = false;
        if (! $this->canApplyProposal()) {
            Notification::make()->title('Apply unavailable')->warning()->send();

            return;
        }
        $runId = (int) ($this->proposalPreview['run_id'] ?? 0);
        $planHash = (string) ($this->proposalPreview['plan_hash'] ?? '');
        $this->reclusterModalStep = self::RECLUSTER_STEP_APPLYING;
        $result = app(TopicGroupingApplyService::class)->apply($runId, $planHash);
        $this->syncReclusterStateFromCache();

        if ($result->status === TopicGroupingApplyResult::ALREADY_APPLIED) {
            Notification::make()->title('Already applied')->warning()->send();
            $this->reclusterModalStep = self::RECLUSTER_STEP_APPLIED;
            $this->showProposalPreview = false;

            return;
        }
        if ($result->status === TopicGroupingApplyResult::STALE) {
            Notification::make()
                ->title('Stale plan — re-preview required')
                ->body((string) ($result->errorMessage ?? ''))
                ->warning()
                ->send();
            $this->proposalPreview = null;
            $this->showProposalPreview = false;
            $this->reclusterModalStep = self::RECLUSTER_STEP_PROPOSAL_READY;

            return;
        }
        if (! $result->ok()) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
            $this->reclusterModalError = (string) ($result->errorMessage ?? $result->status);
            Notification::make()
                ->title('Apply failed')
                ->body($this->reclusterModalError)
                ->danger()
                ->send();

            return;
        }

        $counts = is_array($result->plan?->counts) ? $result->plan->counts : [];
        Notification::make()
            ->title('Topic proposal applied')
            ->body(sprintf(
                'created %d · reused %d · dissolved %d · moved %d · assigned %d · unassigned %d · protected %d',
                (int) ($counts['topics_created'] ?? 0),
                (int) ($counts['topics_reused'] ?? 0),
                (int) ($counts['topics_dissolved'] ?? 0),
                (int) ($counts['keywords_moved'] ?? 0),
                (int) ($counts['keywords_assigned'] ?? 0),
                (int) ($counts['keywords_unassigned'] ?? 0),
                (int) ($counts['topics_protected'] ?? 0) + (int) ($counts['keywords_protected'] ?? 0),
            ))
            ->success()
            ->send();
        $this->showProposalPreview = false;
        $this->proposalPreview = null;
        $this->reclusterModalStep = self::RECLUSTER_STEP_APPLIED;
        if (method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }
    }

    public function discardProposal(): void
    {
        $runId = $this->currentProposalRunId();
        if ($runId <= 0 || ! $this->canReclusterTopics()) {
            Notification::make()->title('Discard unavailable')->warning()->send();

            return;
        }
        $result = app(TopicGroupingApplyService::class)->discard($runId);
        $this->syncReclusterStateFromCache();
        $this->showProposalPreview = false;
        $this->proposalPreview = null;
        $this->confirmApplyProposal = false;
        $this->fullResetTopicStructure = false;
        $this->reclusterModalStep = self::RECLUSTER_STEP_CONFIGURE;
        $this->reclusterModalError = null;
        if (! $result->ok() && $result->status !== TopicGroupingApplyResult::ALREADY_APPLIED) {
            Notification::make()
                ->title('Discard failed')
                ->body((string) ($result->errorMessage ?? $result->status))
                ->danger()
                ->send();

            return;
        }
        Notification::make()->title('Proposal discarded')->success()->send();
        $this->closeReclusterModal();
    }

    public function hasPendingReclusterProposal(): bool
    {
        $this->syncReclusterStateFromCache();
        $status = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';

        return in_array($status, [
            TopicReclusterUiState::STATUS_PROPOSAL_READY,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
            TopicReclusterUiState::STATUS_STALE,
            TopicReclusterUiState::STATUS_QUEUED,
            TopicReclusterUiState::STATUS_ANALYZING,
            TopicReclusterUiState::STATUS_APPLYING,
        ], true);
    }

    private function currentProposalRunId(): int
    {
        $fromUi = (int) ($this->reclusterResult['run_id']
            ?? $this->reclusterResult['metrics']['run_id']
            ?? 0);
        if ($fromUi > 0) {
            return $fromUi;
        }
        $fromPreview = (int) ($this->proposalPreview['run_id'] ?? 0);
        if ($fromPreview > 0) {
            return $fromPreview;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        if ($siteId <= 0 || ! TopicGroupingAnalysisService::runsTableReady()) {
            return 0;
        }

        $run = SeoTopicGroupingRun::query()
            ->where('site_id', $siteId)
            ->whereIn('status', [
                TopicReclusterUiState::STATUS_PROPOSAL_READY,
                TopicReclusterUiState::STATUS_APPLY_FAILED,
                TopicReclusterUiState::STATUS_STALE,
            ])
            ->orderByDesc('id')
            ->first();

        return $run instanceof SeoTopicGroupingRun ? (int) $run->id : 0;
    }

    private function isSemanticProposalContext(): bool
    {
        if (TopicGroupingProviderMode::isSemanticHttp()) {
            return true;
        }
        $mode = strtolower(trim((string) (
            $this->reclusterResult['mode']
            ?? $this->reclusterResult['provider']
            ?? $this->reclusterResult['algorithm_version']
            ?? ''
        )));

        return $mode === TopicGroupingProviderMode::SEMANTIC_HTTP
            || str_contains($mode, 'semantic');
    }

    private function hydrateReclusterModalStepFromState(bool $preservePreview = false): void
    {
        $status = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';

        if ($preservePreview
            && in_array($this->reclusterModalStep, [
                self::RECLUSTER_STEP_PREVIEW,
                self::RECLUSTER_STEP_CONFIRM_APPLY,
            ], true)
            && in_array($status, [
                TopicReclusterUiState::STATUS_PROPOSAL_READY,
                TopicReclusterUiState::STATUS_APPLY_FAILED,
            ], true)
            && is_array($this->proposalPreview)
        ) {
            return;
        }

        if (in_array($status, [
            TopicReclusterUiState::STATUS_QUEUED,
            TopicReclusterUiState::STATUS_ANALYZING,
            TopicReclusterUiState::STATUS_RUNNING,
        ], true)) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_ANALYZING;

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

        if ($status === TopicReclusterUiState::STATUS_FAILED) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_FAILED;
            $this->reclusterModalError = (string) ($this->reclusterResult['error'] ?? 'failed');

            return;
        }

        if (in_array($status, [
            TopicReclusterUiState::STATUS_PROPOSAL_READY,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
            TopicReclusterUiState::STATUS_STALE,
        ], true)) {
            $this->reclusterModalStep = self::RECLUSTER_STEP_PROPOSAL_READY;
            // Reflect persisted mode in checkbox for display only (not authoritative).
            $this->fullResetTopicStructure = $this->isFullResetPersisted();

            return;
        }

        $this->reclusterModalStep = self::RECLUSTER_STEP_CONFIGURE;
        $this->fullResetTopicStructure = false;
    }
}
