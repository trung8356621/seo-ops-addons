<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\WithPagination;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\DissolvesTopics;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualCreateService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicRenameService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Topic list — old UX presentation, Topic Core SSOT (seo_topics.id).
 */
final class KeywordTopicClusters extends Page
{
    use DissolvesTopics;
    use HasKeywordWorkspaceNavigation;
    use ReclustersSiteTopics;
    use WithPagination;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-cluster-index';

    protected static bool $shouldRegisterNavigation = false;

    public string $clusterSearch = '';

    public string $clusterSearchInput = '';

    public string $lockFilter = '';

    public bool $hasArticles = false;

    public string $clusterSort = 'topical_share_desc';

    public int $clusterDataEpoch = 0;

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if ($this->redirectToFirstAccessibleDomainIfNeeded()) {
            return;
        }
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->clusterSearchInput = $this->clusterSearch;
        $this->syncReclusterStateFromCache();
    }

    public function applyClusterSearch(): void
    {
        $this->clusterSearch = trim($this->clusterSearchInput);
        $this->clusterSearchInput = $this->clusterSearch;
        $this->resetPage();
    }

    public function clearClusterSearch(): void
    {
        $this->clusterSearch = '';
        $this->clusterSearchInput = '';
        $this->resetPage();
    }

    public function updatedLockFilter(): void
    {
        $this->resetPage();
    }

    public function updatedHasArticles(): void
    {
        $this->resetPage();
    }

    public function updatedClusterSort(): void
    {
        $this->resetPage();
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->clusterDataEpoch++;
        $this->resetPage();
        $this->syncReclusterStateFromCache();
    }

    private function redirectToFirstAccessibleDomainIfNeeded(): bool
    {
        if ($this->resolveKeywordWorkspaceSiteId() !== null) {
            return false;
        }

        $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->first();
        if (! $first instanceof Site) {
            return false;
        }

        $this->redirect(
            app(DomainContextResolver::class)->appendSiteToUrl(
                KeywordResource::getUrl('clusters'),
                (int) $first->getKey(),
            ),
            navigate: false,
        );

        return true;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.topic_cluster_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'clusters';
    }

    /**
     * @return array<string, int|float>
     */
    public function getSummary(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(TopicListQuery::class)->summary($siteId);
    }

    public function getClusters()
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(TopicListQuery::class)
            ->paginate($siteId, [
                'search' => $this->clusterSearch,
                'sort' => $this->clusterSort,
                'has_articles' => $this->hasArticles,
                'lock_filter' => $this->lockFilter,
                'per_page' => 25,
            ])
            ->withPath(KeywordResource::getUrl('clusters'))
            ->appends(array_filter([
                'site_id' => $siteId > 0 ? $siteId : null,
            ], static fn (mixed $v): bool => $v !== null));
    }

    public function topicUrl(int $topicId): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('cluster', ['topic' => $topicId]),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function unassignedUrl(): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::getUrl('index'),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function canEditTopicName(): bool
    {
        return $this->hasTopicMutationPermission() && ! $this->isTopicMutationLocked();
    }

    /**
     * @return array{ok: bool, label?: string, topic_id?: int}
     */
    public function saveTopicNameFromIndex(int $topicId, string $phrase): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || $topicId <= 0 || ! $this->canEditTopicName()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_denied'))
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $result = app(TopicRenameService::class)->rename($siteId, $topicId, $phrase);
        if (! $result['ok']) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_failed'))
                ->danger()
                ->send();

            return ['ok' => false];
        }

        $label = KeywordPhrasePresentation::present(trim($phrase));
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_canonical_edit_saved'))
            ->success()
            ->send();

        return [
            'ok' => true,
            'topic_id' => $topicId,
            'label' => $label !== '' ? $label : trim($phrase),
        ];
    }

    public function quickCreateTopic(): void
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $phrase = trim($this->clusterSearchInput);
        if ($siteId <= 0 || ! $this->canEditTopicName()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_quick_create_failed'))
                ->danger()
                ->send();

            return;
        }
        if ($phrase === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_quick_create_empty'))
                ->warning()
                ->send();

            return;
        }

        $result = app(TopicManualCreateService::class)->create($siteId, $phrase);
        if (! ($result['ok'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_quick_create_failed'))
                ->danger()
                ->send();

            return;
        }

        $label = (string) ($result['topic_name'] ?? $phrase);
        $titleKey = ($result['reused'] ?? false)
            ? 'seo-content-ai::filament.keyword.topic_quick_create_reused'
            : 'seo-content-ai::filament.keyword.topic_quick_create_success';
        Notification::make()
            ->title(__($titleKey, ['label' => $label]))
            ->success()
            ->send();

        $this->clusterSearch = '';
        $this->clusterSearchInput = '';
        $this->clusterDataEpoch++;
        $this->resetPage();
    }

    public function refreshClusterSummaryCounters(): void
    {
        $this->clusterDataEpoch++;
    }

    /** Topic Core has no dirty-cluster signal yet; keep blade hook stable. */
    public function clusterStateIsDirty(): bool
    {
        return false;
    }

    /** @deprecated BC alias */
    public function canEditClusterCanonical(): bool
    {
        return $this->canEditTopicName();
    }

    /** @deprecated BC alias */
    public function canDissolveCluster(): bool
    {
        return $this->canDissolveTopic();
    }

    public function hasTopicClusterMutationPermission(): bool
    {
        return $this->hasTopicMutationPermission();
    }
}
