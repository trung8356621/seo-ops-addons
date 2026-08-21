<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ApplyTopicClusterProposalBatchService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchMode;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;

trait AppliesTopicClusterProposalBatches
{
    /** @var list<string> */
    public array $selectedReadyProposalFingerprints = [];

    public ?string $batchApplyMode = null;

    public int $batchApplyProposalCount = 0;

    public int $batchApplyKeywordCount = 0;

    public int $batchApplyReadyCount = 0;

    public int $batchApplyNeedsReviewCount = 0;

    public function toggleReadyProposalSelection(string $proposalFingerprint): void
    {
        $fingerprint = trim($proposalFingerprint);
        if ($fingerprint === '') {
            return;
        }

        if (in_array($fingerprint, $this->selectedReadyProposalFingerprints, true)) {
            $this->selectedReadyProposalFingerprints = array_values(array_filter(
                $this->selectedReadyProposalFingerprints,
                static fn (string $value): bool => $value !== $fingerprint,
            ));

            return;
        }

        $this->selectedReadyProposalFingerprints[] = $fingerprint;
        sort($this->selectedReadyProposalFingerprints, SORT_STRING);
    }

    public function getSelectedReadyProposalCount(): int
    {
        return count($this->selectedReadyProposalFingerprints);
    }

    /**
     * @return array{ready: int, needs_review: int, ready_keywords: int}
     */
    public function getBatchPreviewCounts(): array
    {
        $preview = $this->clusterProposalPreview;
        if (! is_array($preview)) {
            return ['ready' => 0, 'needs_review' => 0, 'ready_keywords' => 0];
        }

        $clusters = is_array($preview['proposed_clusters'] ?? null) ? $preview['proposed_clusters'] : [];
        $ready = 0;
        $needsReview = 0;
        $readyKeywords = 0;

        foreach ($clusters as $cluster) {
            $status = (string) ($cluster['final_status'] ?? '');
            if ($status === KeywordClusterProposalCluster::FINAL_READY) {
                $ready++;
                $readyKeywords += max(0, (int) ($cluster['member_count'] ?? 0));
            } elseif ($status === KeywordClusterProposalCluster::FINAL_NEEDS_REVIEW) {
                $needsReview++;
            }
        }

        return [
            'ready' => $ready,
            'needs_review' => $needsReview,
            'ready_keywords' => $readyKeywords,
        ];
    }

    public function openBatchApplySelectedConfirm(): void
    {
        if (! $this->canApplyProposal() || $this->selectedReadyProposalFingerprints === []) {
            return;
        }

        $counts = $this->summarizeBatchSelection($this->selectedReadyProposalFingerprints);
        if ($counts === null) {
            return;
        }

        $this->batchApplyMode = ApplyTopicClusterProposalBatchMode::SELECTED;
        $this->batchApplyProposalCount = $counts['proposal_count'];
        $this->batchApplyKeywordCount = $counts['keyword_count'];
        $this->batchApplyReadyCount = $counts['ready_count'];
        $this->batchApplyNeedsReviewCount = $counts['needs_review_count'];
    }

    public function openBatchApplyAllReadyConfirm(): void
    {
        if (! $this->canApplyProposal()) {
            return;
        }

        $counts = $this->getBatchPreviewCounts();
        if ($counts['ready'] === 0) {
            return;
        }

        $this->batchApplyMode = ApplyTopicClusterProposalBatchMode::ALL_READY;
        $this->batchApplyProposalCount = $counts['ready'];
        $this->batchApplyKeywordCount = $counts['ready_keywords'];
        $this->batchApplyReadyCount = $counts['ready'];
        $this->batchApplyNeedsReviewCount = $counts['needs_review'];
    }

    public function cancelBatchApplyConfirm(): void
    {
        $this->batchApplyMode = null;
        $this->batchApplyProposalCount = 0;
        $this->batchApplyKeywordCount = 0;
        $this->batchApplyReadyCount = 0;
        $this->batchApplyNeedsReviewCount = 0;
    }

    public function confirmBatchApply(): void
    {
        if (! $this->canApplyProposal() || $this->batchApplyMode === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_failed'))
                ->danger()
                ->send();

            return;
        }

        $preview = $this->clusterProposalPreview;
        if (! is_array($preview)) {
            $this->cancelBatchApplyConfirm();

            return;
        }

        $previewFingerprint = trim((string) ($preview['preview_fingerprint'] ?? ''));
        if ($previewFingerprint === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_failed'))
                ->danger()
                ->send();
            $this->cancelBatchApplyConfirm();

            return;
        }

        $mode = $this->batchApplyMode;
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $result = app(ApplyTopicClusterProposalBatchService::class)->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: $siteId,
            strategy: (string) ($this->clusterProposalStrategy ?? 'balanced'),
            previewFingerprint: $previewFingerprint,
            mode: $mode,
            selectedProposalFingerprints: $mode === ApplyTopicClusterProposalBatchMode::SELECTED
                ? $this->selectedReadyProposalFingerprints
                : [],
        ));

        $this->cancelBatchApplyConfirm();
        $this->selectedReadyProposalFingerprints = [];

        if ($result->status === ApplyTopicClusterProposalBatchStatus::STALE) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_stale_title'))
                ->body(__('seo-content-ai::filament.keyword.topic_batch_apply_stale_body'))
                ->warning()
                ->send();
            $this->refreshClusterProposalPreview();

            return;
        }

        if ($result->status === ApplyTopicClusterProposalBatchStatus::CONFLICT) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_conflict_title'))
                ->body(__('seo-content-ai::filament.keyword.topic_batch_apply_conflict_body'))
                ->danger()
                ->send();
            $this->refreshClusterProposalPreview();

            return;
        }

        if ($result->status === ApplyTopicClusterProposalBatchStatus::INVALID_SELECTION) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_invalid_title'))
                ->body(__('seo-content-ai::filament.keyword.topic_batch_apply_invalid_body'))
                ->warning()
                ->send();
            $this->refreshClusterProposalPreview();

            return;
        }

        if ($result->status === ApplyTopicClusterProposalBatchStatus::UNAUTHORIZED) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        if (! $result->isSuccess()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_failed'))
                ->danger()
                ->send();

            return;
        }

        $proposalCount = max(0, $result->proposalCount);
        $keywordCount = max(0, $result->keywordCount);

        if ($mode === ApplyTopicClusterProposalBatchMode::ALL_READY) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_all_success_title', [
                    'count' => number_format($proposalCount),
                ]))
                ->body(trans_choice(
                    'seo-content-ai::filament.keyword.topic_batch_apply_all_success_body',
                    $keywordCount,
                    ['count' => number_format($keywordCount)],
                ))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_batch_apply_selected_success_title', [
                    'count' => number_format($proposalCount),
                ]))
                ->body(trans_choice(
                    'seo-content-ai::filament.keyword.topic_batch_apply_selected_success_body',
                    $keywordCount,
                    ['count' => number_format($keywordCount)],
                ))
                ->success()
                ->send();
        }

        $this->refreshClusterProposalPreview();

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * @param  list<string>  $fingerprints
     * @return array{
     *     proposal_count: int,
     *     keyword_count: int,
     *     ready_count: int,
     *     needs_review_count: int,
     * }|null
     */
    private function summarizeBatchSelection(array $fingerprints): ?array
    {
        $preview = $this->clusterProposalPreview;
        if (! is_array($preview)) {
            return null;
        }

        $counts = $this->getBatchPreviewCounts();
        $clusters = is_array($preview['proposed_clusters'] ?? null) ? $preview['proposed_clusters'] : [];
        $byFingerprint = [];
        foreach ($clusters as $cluster) {
            $fp = trim((string) ($cluster['proposal_fingerprint'] ?? ''));
            if ($fp !== '') {
                $byFingerprint[$fp] = $cluster;
            }
        }

        $proposalCount = 0;
        $keywordCount = 0;
        foreach ($fingerprints as $fingerprint) {
            $cluster = $byFingerprint[$fingerprint] ?? null;
            if (! is_array($cluster)) {
                return null;
            }
            if (($cluster['final_status'] ?? '') !== KeywordClusterProposalCluster::FINAL_READY) {
                return null;
            }
            $proposalCount++;
            $keywordCount += max(0, (int) ($cluster['member_count'] ?? 0));
        }

        return [
            'proposal_count' => $proposalCount,
            'keyword_count' => $keywordCount,
            'ready_count' => $counts['ready'],
            'needs_review_count' => $counts['needs_review'],
        ];
    }
}
