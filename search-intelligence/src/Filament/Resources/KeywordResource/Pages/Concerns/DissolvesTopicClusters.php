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
            return;
        }

        $clusterKey = trim($clusterKey);
        if ($clusterKey === '') {
            return;
        }

        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $detail = app(KeywordClusterDetailBuilder::class)->build($siteId, $clusterKey);
        $keywordCount = (int) ($detail['keyword_count'] ?? 0);
        if ($keywordCount < 1) {
            $counts = app(KeywordClusterQuery::class)->countsForKeys($siteId, [$clusterKey]);
            $keywordCount = (int) ($counts[$clusterKey] ?? 0);
        }

        $this->dissolveClusterKey = $clusterKey;
        $this->dissolveClusterLabel = (string) ($detail['label'] ?? $clusterKey);
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
        if (! $this->canDissolveCluster() || $this->dissolveClusterKey === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $clusterKey = $this->dissolveClusterKey;
        $clusterLabel = $this->dissolveClusterLabel;

        $result = app(DissolveTopicClusterService::class)->dissolve($siteId, $clusterKey, $clusterLabel);

        $this->cancelDissolveConfirm();

        if (! $result->success) {
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

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }

        if ($this->shouldRedirectAfterDissolve()) {
            $this->redirect($this->dissolveClustersListUrl());
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
