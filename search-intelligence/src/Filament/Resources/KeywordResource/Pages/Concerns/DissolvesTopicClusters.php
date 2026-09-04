<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Livewire\Attributes\Renderless;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterReclusterState;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait DissolvesTopicClusters
{
    public function canDissolveCluster(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canAccessPlannerFeatures()
            && SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId)
            && ! TopicClusterReclusterState::isMutationLocked((int) $siteId);
    }

    /**
     * Immediate dissolve — no confirm UI. Renderless so list pagination / scroll stay put.
     *
     * @return array{ok: bool, cluster_key?: string, label?: string, affected_count?: int}
     */
    #[Renderless]
    public function dissolveTopicCluster(string $clusterKey): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if (! TopicClusterReclusterState::assertMutationAllowed($siteId)) {
            return ['ok' => false];
        }

        if (! $this->canDissolveCluster()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $clusterKey = trim($clusterKey);
        if ($clusterKey === '') {
            return ['ok' => false];
        }

        $clusterLabel = app(KeywordClusterQuery::class)->displayLabel($clusterKey, '', $siteId);
        $result = app(DissolveTopicClusterService::class)->dissolve($siteId, $clusterKey, $clusterLabel);

        if (! $result->success) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_dissolve_failed'))
                ->danger()
                ->send();

            return ['ok' => false, 'cluster_key' => $clusterKey];
        }

        $count = max(0, $result->affectedKeywordCount);
        $label = $clusterLabel !== '' ? $clusterLabel : $clusterKey;

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_dissolve_success_title', [
                'label' => $label,
            ]))
            ->body(trans_choice(
                'seo-content-ai::filament.keyword.topic_dissolve_success_body',
                $count,
                ['count' => number_format($count)],
            ))
            ->success()
            ->send();

        if ($this->shouldRedirectAfterDissolve()) {
            $this->redirect($this->dissolveClustersListUrl(), navigate: false);
        }

        return [
            'ok' => true,
            'cluster_key' => $clusterKey,
            'label' => $label,
            'affected_count' => $count,
        ];
    }

    /**
     * List page: stay on current paginator page (never resetPage / never bump clusterDataEpoch).
     * Detail page overrides to redirect back to the list after dissolve.
     */
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
