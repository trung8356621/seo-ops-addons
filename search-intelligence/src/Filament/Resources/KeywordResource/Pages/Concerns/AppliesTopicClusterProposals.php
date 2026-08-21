<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ApplyTopicClusterProposalService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait AppliesTopicClusterProposals
{
    public ?string $applyProposalFingerprint = null;

    public ?string $applyPreviewFingerprint = null;

    /** @var list<int> */
    public array $applyMemberKeywordIds = [];

    public int $applyRepresentativeKeywordId = 0;

    public string $applyRepresentativeLabel = '';

    public int $applyMemberCount = 0;

    public string $applyFinalStatus = '';

    public string $applyQualityState = '';

    public function canApplyProposal(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canAccessPlannerFeatures()
            && SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    /**
     * @param  array<string, mixed>  $cluster
     */
    public function openApplyProposalConfirm(array $cluster): void
    {
        if (! $this->canApplyProposal()) {
            return;
        }

        $preview = $this->clusterProposalPreview;
        if (! is_array($preview)) {
            return;
        }

        $this->applyProposalFingerprint = trim((string) ($cluster['proposal_fingerprint'] ?? ''));
        $this->applyPreviewFingerprint = trim((string) ($preview['preview_fingerprint'] ?? ''));
        $this->applyMemberKeywordIds = array_values(array_filter(array_map(
            static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
            is_array($cluster['members'] ?? null) ? $cluster['members'] : [],
        ), static fn (int $id): bool => $id > 0));
        sort($this->applyMemberKeywordIds, SORT_NUMERIC);
        $this->applyRepresentativeKeywordId = max(0, (int) ($cluster['representative_keyword_id'] ?? 0));
        $this->applyRepresentativeLabel = trim((string) ($cluster['representative_label'] ?? ''));
        $this->applyMemberCount = max(0, (int) ($cluster['member_count'] ?? 0));
        $this->applyFinalStatus = trim((string) ($cluster['final_status'] ?? ''));
        $this->applyQualityState = trim((string) ($cluster['quality_state'] ?? $cluster['quality']['quality_state'] ?? ''));
    }

    public function cancelApplyProposalConfirm(): void
    {
        $this->applyProposalFingerprint = null;
        $this->applyPreviewFingerprint = null;
        $this->applyMemberKeywordIds = [];
        $this->applyRepresentativeKeywordId = 0;
        $this->applyRepresentativeLabel = '';
        $this->applyMemberCount = 0;
        $this->applyFinalStatus = '';
        $this->applyQualityState = '';
    }

    public function confirmApplyProposal(): void
    {
        if (! $this->canApplyProposal()
            || $this->applyProposalFingerprint === null
            || $this->applyPreviewFingerprint === null
            || $this->applyProposalFingerprint === ''
            || $this->applyPreviewFingerprint === ''
        ) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_apply_failed'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $result = app(ApplyTopicClusterProposalService::class)->apply(new ApplyTopicClusterProposalInput(
            siteId: $siteId,
            strategy: (string) ($this->clusterProposalStrategy ?? 'balanced'),
            previewFingerprint: $this->applyPreviewFingerprint,
            proposalFingerprint: $this->applyProposalFingerprint,
            memberKeywordIds: $this->applyMemberKeywordIds,
            representativeKeywordId: $this->applyRepresentativeKeywordId,
            representativeLabel: $this->applyRepresentativeLabel,
            finalStatus: $this->applyFinalStatus !== ''
                ? $this->applyFinalStatus
                : KeywordClusterProposalCluster::FINAL_READY,
            qualityState: $this->applyQualityState !== ''
                ? $this->applyQualityState
                : KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
        ));

        $label = $this->applyRepresentativeLabel;
        $this->cancelApplyProposalConfirm();

        if ($result->status === ApplyTopicClusterProposalStatus::STALE) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_apply_stale_title'))
                ->body(__('seo-content-ai::filament.keyword.topic_apply_stale_body'))
                ->warning()
                ->send();
            $this->refreshClusterProposalPreview();

            return;
        }

        if ($result->status === ApplyTopicClusterProposalStatus::CONFLICT) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_apply_conflict_title'))
                ->body(__('seo-content-ai::filament.keyword.topic_apply_conflict_body'))
                ->danger()
                ->send();
            $this->refreshClusterProposalPreview();

            return;
        }

        if ($result->status === ApplyTopicClusterProposalStatus::UNAUTHORIZED) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        if (! $result->isSuccess()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_apply_failed'))
                ->danger()
                ->send();

            return;
        }

        $count = max(0, $result->affectedKeywordCount);
        $displayLabel = $result->representativeLabel !== '' ? $result->representativeLabel : $label;
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_apply_success_title', [
                'label' => $displayLabel,
            ]))
            ->body(trans_choice(
                'seo-content-ai::filament.keyword.topic_apply_success_body',
                $count,
                ['count' => number_format($count)],
            ))
            ->success()
            ->send();

        $this->refreshClusterProposalPreview();

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }
}
