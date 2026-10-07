<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
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
    public bool $confirmRecluster = false;

    public bool $reclusterRunning = false;

    public bool $showProposalPreview = false;

    public bool $confirmApplyProposal = false;

    /** @var array<string, mixed>|null */
    public ?array $proposalPreview = null;

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
        if (TopicReclusterUiState::isActiveStatus($status)) {
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
        if ($this->isTopicMutationLocked()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_recluster_already_running'))
                ->warning()
                ->send();
            $this->syncReclusterStateFromCache();

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
                ->body(__('Topic Core tables missing'))
                ->danger()
                ->send();

            return;
        }

        $version = TopicReclusterAlgorithm::VERSION;
        $semantic = TopicGroupingProviderMode::isSemanticHttp();

        if ($sync) {
            if ($semantic) {
                TopicReclusterUiState::markQueued($siteId, TopicGroupingProviderMode::SEMANTIC_HTTP);
                $this->reclusterRunning = true;
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $run = app(TopicGroupingAnalysisService::class)->analyzeSite($siteId);
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterRunning = TopicReclusterUiState::isAnalyzeActive($siteId);
                if ($run->isProposalReady()) {
                    $metrics = is_array($this->reclusterResult['metrics'] ?? null)
                        ? $this->reclusterResult['metrics']
                        : [];
                    Notification::make()
                        ->title('Topic proposal ready')
                        ->body(sprintf(
                            '%d groups · %d unassigned · %d low confidence (not applied)',
                            (int) ($metrics['group_count'] ?? $run->group_count),
                            (int) ($metrics['unassigned_count'] ?? $run->unassigned_count),
                            (int) ($metrics['low_confidence_count'] ?? $run->low_confidence_count),
                        ))
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Topic analysis failed')
                        ->body((string) ($run->error_message ?? 'failed'))
                        ->danger()
                        ->send();
                }

                return;
            }

            TopicReclusterUiState::markRunning($siteId, $version);
            $this->reclusterRunning = true;
            $this->reclusterResult = TopicReclusterUiState::get($siteId);
            $result = app(TopicReclusterService::class)->recluster($siteId);
            if ($result->ok) {
                TopicReclusterUiState::markSucceeded($siteId, $result->metrics, $version);
                $this->reclusterResult = TopicReclusterUiState::get($siteId);
                $this->reclusterRunning = false;
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

        // Persist queued state BEFORE dispatch so F5 immediately shows running UX.
        TopicReclusterUiState::markQueued(
            $siteId,
            $semantic ? TopicGroupingProviderMode::SEMANTIC_HTTP : $version,
        );
        $this->reclusterRunning = true;
        $this->reclusterResult = TopicReclusterUiState::get($siteId);
        ReclusterSiteTopicsJob::dispatch($siteId, $version);
        Notification::make()
            ->title($semantic
                ? 'Topic analysis queued'
                : __('seo-content-ai::filament.keyword.topic_recluster_queued_title'))
            ->body($semantic
                ? 'Analyzing… proposal only (not applied)'
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
                ->title('Topic proposal ready')
                ->body(sprintf(
                    '%d groups · %d unassigned · %d low confidence (not applied)',
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
                ->title(TopicGroupingProviderMode::isSemanticHttp()
                    ? 'Topic analysis failed'
                    : __('seo-content-ai::filament.keyword.topic_recluster_failed_title'))
                ->body($error !== '' ? $error : null)
                ->danger()
                ->send();
        }
    }

    public function canPreviewProposal(): bool
    {
        if (! $this->canReclusterTopics() || ! TopicGroupingProviderMode::isSemanticHttp()) {
            return false;
        }
        $status = is_array($this->reclusterResult)
            ? (string) ($this->reclusterResult['status'] ?? '')
            : '';

        return in_array($status, [
            TopicReclusterUiState::STATUS_PROPOSAL_READY,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
            TopicReclusterUiState::STATUS_STALE,
        ], true);
    }

    public function canApplyProposal(): bool
    {
        if (! $this->canPreviewProposal()) {
            return false;
        }
        $status = (string) ($this->reclusterResult['status'] ?? '');

        return in_array($status, [
            TopicReclusterUiState::STATUS_PROPOSAL_READY,
            TopicReclusterUiState::STATUS_APPLY_FAILED,
        ], true)
            && is_array($this->proposalPreview)
            && (string) ($this->proposalPreview['plan_hash'] ?? '') !== '';
    }

    public function openProposalPreview(): void
    {
        if (! $this->canPreviewProposal()) {
            Notification::make()->title('Proposal preview unavailable')->warning()->send();

            return;
        }
        $runId = (int) ($this->reclusterResult['run_id']
            ?? $this->reclusterResult['metrics']['run_id']
            ?? 0);
        if ($runId <= 0) {
            Notification::make()->title('Missing run_id')->danger()->send();

            return;
        }

        $result = app(TopicGroupingApplyService::class)->preview($runId);
        if (! $result->ok() || $result->plan === null) {
            $this->syncReclusterStateFromCache();
            Notification::make()
                ->title($result->status === TopicGroupingApplyResult::STALE ? 'Proposal stale' : 'Preview failed')
                ->body((string) ($result->errorMessage ?? $result->status))
                ->danger()
                ->send();

            return;
        }

        $plan = $result->plan;
        $this->proposalPreview = [
            'run_id' => $runId,
            'input_hash' => (string) ($result->metrics['input_hash'] ?? ''),
            'plan_hash' => $plan->planHash,
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
            'high_churn' => $this->isHighChurnPlan($plan->counts),
        ];
        $this->showProposalPreview = true;
        $this->confirmApplyProposal = false;
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
    }

    public function beginConfirmApplyProposal(): void
    {
        if (! $this->canApplyProposal()) {
            Notification::make()->title('Apply unavailable')->warning()->send();

            return;
        }
        $this->confirmApplyProposal = true;
    }

    public function cancelConfirmApplyProposal(): void
    {
        $this->confirmApplyProposal = false;
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
        $result = app(TopicGroupingApplyService::class)->apply($runId, $planHash);
        $this->syncReclusterStateFromCache();

        if ($result->status === TopicGroupingApplyResult::ALREADY_APPLIED) {
            Notification::make()->title('Already applied')->warning()->send();
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

            return;
        }
        if (! $result->ok()) {
            Notification::make()
                ->title('Apply failed')
                ->body((string) ($result->errorMessage ?? $result->status))
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
        if (method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }
    }

    public function discardProposal(): void
    {
        $runId = (int) ($this->reclusterResult['run_id']
            ?? $this->reclusterResult['metrics']['run_id']
            ?? $this->proposalPreview['run_id']
            ?? 0);
        if ($runId <= 0 || ! $this->canReclusterTopics()) {
            Notification::make()->title('Discard unavailable')->warning()->send();

            return;
        }
        $result = app(TopicGroupingApplyService::class)->discard($runId);
        $this->syncReclusterStateFromCache();
        $this->showProposalPreview = false;
        $this->proposalPreview = null;
        if (! $result->ok() && $result->status !== TopicGroupingApplyResult::ALREADY_APPLIED) {
            Notification::make()
                ->title('Discard failed')
                ->body((string) ($result->errorMessage ?? $result->status))
                ->danger()
                ->send();

            return;
        }
        Notification::make()->title('Proposal discarded')->success()->send();
    }
}
