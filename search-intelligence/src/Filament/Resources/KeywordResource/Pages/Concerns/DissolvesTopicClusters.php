<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterDetailBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait DissolvesTopicClusters
{
    public ?string $dissolveClusterKey = null;

    public string $dissolveClusterLabel = '';

    public int $dissolveClusterKeywordCount = 0;

    public function canDissolveCluster(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canAccessPlannerFeatures()
            && SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    public function openDissolveConfirm(string $clusterKey): void
    {
        if (! $this->canDissolveCluster()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $clusterKey = trim($clusterKey);
        if ($clusterKey === '') {
            return;
        }

        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $detail = app(KeywordClusterDetailBuilder::class)->build($siteId, $clusterKey);
        $keywordCount = is_array($detail) ? (int) ($detail['keyword_count'] ?? 0) : 0;
        if ($keywordCount < 1) {
            $counts = app(KeywordClusterQuery::class)->countsForKeys($siteId, [$clusterKey]);
            $keywordCount = (int) ($counts[$clusterKey] ?? 0);
        }

        $this->dissolveClusterKey = $clusterKey;
        $this->dissolveClusterLabel = is_array($detail)
            ? (string) ($detail['label'] ?? $clusterKey)
            : app(KeywordClusterQuery::class)->displayLabel($clusterKey, '', $siteId);
        $this->dissolveClusterKeywordCount = max(0, $keywordCount);
    }

    public function cancelDissolveConfirm(): void
    {
        $this->dissolveClusterKey = null;
        $this->dissolveClusterLabel = '';
        $this->dissolveClusterKeywordCount = 0;
    }

    public function confirmDissolveCluster(): void
    {
        if (! $this->canDissolveCluster() || $this->dissolveClusterKey === null || trim($this->dissolveClusterKey) === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $clusterKey = trim((string) $this->dissolveClusterKey);
        $clusterLabel = $this->dissolveClusterLabel;
        $shouldRedirect = $this->shouldRedirectAfterDissolve();

        $result = app(DissolveTopicClusterService::class)->dissolve($siteId, $clusterKey, $clusterLabel);

        if (! $result->success) {
            $this->cancelDissolveConfirm();
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_dissolve_failed'))
                ->danger()
                ->send();

            return;
        }

        $count = max(0, $result->affectedKeywordCount);
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_dissolve_success_title', [
                'label' => $clusterLabel !== '' ? $clusterLabel : $clusterKey,
            ]))
            ->body(trans_choice(
                'seo-content-ai::filament.keyword.topic_dissolve_success_body',
                $count,
                ['count' => number_format($count)],
            ))
            ->success()
            ->send();

        $this->cancelDissolveConfirm();

        if (method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }

        if ($shouldRedirect) {
            $this->redirect($this->dissolveClustersListUrl(), navigate: false);

            return;
        }
    }

    protected function shouldRedirectAfterDissolve(): bool
    {
        return false;
    }

    protected function dissolveClustersListUrl(): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('clusters'),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }
}
