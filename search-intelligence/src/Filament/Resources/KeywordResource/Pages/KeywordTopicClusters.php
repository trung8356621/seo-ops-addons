<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\DissolvesTopics;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualCreateService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicRenameService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;
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

    /**
     * URL-backed custom Topic tag filter (comma-separated ids). Multi-select AND semantics.
     * Survives F5 / back-forward via Livewire Url attribute.
     */
    #[Url(as: 'topic_tags')]
    public string $topicTags = '';

    /** Built-in Topic filters (code-derived; not DB tags). */
    #[Url(as: 'intent')]
    public string $intentFilter = '';

    #[Url(as: 'coverage')]
    public string $coverageFilter = '';

    #[Url(as: 'source')]
    public string $sourceFilter = '';

    public int $clusterDataEpoch = 0;

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if ($this->redirectToFirstAccessibleDomainIfNeeded()) {
            return;
        }
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->clusterSearchInput = $this->clusterSearch;
        $this->normalizeBuiltinFilters();
        $this->pruneInvalidTopicTagFilter();
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

    public function updatedTopicTags(): void
    {
        $this->topicTags = $this->serializeTopicTagIds($this->resolvedTopicTagFilterIds());
        $this->pruneInvalidTopicTagFilter();
        $this->resetPage();
    }

    public function updatedIntentFilter(): void
    {
        $this->normalizeBuiltinFilters();
        $this->resetPage();
    }

    public function updatedCoverageFilter(): void
    {
        $this->normalizeBuiltinFilters();
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->normalizeBuiltinFilters();
        $this->resetPage();
    }

    public function setTopicTagFilterIds(array $tagIds): void
    {
        $this->topicTags = $this->serializeTopicTagIds($tagIds);
        $this->pruneInvalidTopicTagFilter();
        $this->resetPage();
    }

    public function toggleTopicTagFilter(int $tagId): void
    {
        if ($tagId <= 0) {
            return;
        }
        $ids = $this->resolvedTopicTagFilterIds();
        if (in_array($tagId, $ids, true)) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $tagId));
        } else {
            $ids[] = $tagId;
        }
        $this->setTopicTagFilterIds($ids);
    }

    public function clearTopicTagFilters(): void
    {
        $this->topicTags = '';
        $this->resetPage();
    }

    /**
     * Site-scoped custom tags for filter/add-tag comboboxes.
     *
     * @return list<array{id: int, name: string}>
     */
    public function getTopicTagFilterOptions(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(TopicUserTagService::class)->search($siteId, '', 200);
    }

    /**
     * Autocomplete for Add Tag / Tags filter (current site only).
     *
     * @return list<array{id: int, name: string}>
     */
    public function searchTopicTags(string $query = ''): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(TopicUserTagService::class)->search($siteId, $query, 20);
    }

    /**
     * Labels for currently selected custom tag chips (site-scoped; orphans dropped).
     *
     * @return list<array{id: int, name: string}>
     */
    public function getSelectedTopicTagChips(): array
    {
        $ids = $this->resolvedTopicTagFilterIds();
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->getTopicTagFilterOptions() as $row) {
            $byId[(int) $row['id']] = (string) $row['name'];
        }
        $chips = [];
        foreach ($ids as $id) {
            $name = $byId[$id] ?? '';
            if ($name === '') {
                continue;
            }
            $chips[] = ['id' => $id, 'name' => $name];
        }

        return $chips;
    }

    /**
     * @return list<int>
     */
    private function resolvedTopicTagFilterIds(): array
    {
        $raw = trim($this->topicTags);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,\s]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $part): int => (int) $part, $parts),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  list<int|string>  $tagIds
     */
    private function serializeTopicTagIds(array $tagIds): string
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $tagIds),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        return implode(',', $ids);
    }

    private function pruneInvalidTopicTagFilter(): void
    {
        $ids = $this->resolvedTopicTagFilterIds();
        if ($ids === []) {
            $this->topicTags = '';

            return;
        }
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || ! TopicUserTagService::tablesReady()) {
            $this->topicTags = '';

            return;
        }
        $valid = \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $this->topicTags = $this->serializeTopicTagIds($valid);
    }

    private function normalizeBuiltinFilters(): void
    {
        $intent = strtolower(trim($this->intentFilter));
        $this->intentFilter = in_array($intent, ['commercial', 'informational'], true) ? $intent : '';

        $coverage = strtolower(trim($this->coverageFilter));
        $this->coverageFilter = in_array($coverage, ['strong', 'medium', 'weak'], true) ? $coverage : '';

        $source = strtolower(trim($this->sourceFilter));
        $this->sourceFilter = in_array($source, ['auto', 'manual'], true) ? $source : '';
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->clusterDataEpoch++;
        $this->topicTags = '';
        $this->intentFilter = '';
        $this->coverageFilter = '';
        $this->sourceFilter = '';
        $this->clearKeywordWorkspaceTabCountsCache();
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

        return app(TopicListQuery::class)->summary(
            $siteId,
            $this->resolveKeywordLanguageFilterVariants(),
        );
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
                'tag_ids' => $this->resolvedTopicTagFilterIds(),
                'intent' => $this->intentFilter,
                'coverage' => $this->coverageFilter,
                'source' => $this->sourceFilter,
                'per_page' => 25,
            ])
            ->withPath(KeywordResource::getUrl('clusters'))
            ->appends(array_filter([
                'site_id' => $siteId > 0 ? $siteId : null,
                'topic_tags' => $this->topicTags !== '' ? $this->topicTags : null,
                'intent' => $this->intentFilter !== '' ? $this->intentFilter : null,
                'coverage' => $this->coverageFilter !== '' ? $this->coverageFilter : null,
                'source' => $this->sourceFilter !== '' ? $this->sourceFilter : null,
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
            KeywordResource::buildTopicAssignmentFilterUrl('unassigned'),
            $this->resolveKeywordWorkspaceSiteId(),
        );
    }

    public function assignedUrl(): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            KeywordResource::buildTopicAssignmentFilterUrl('assigned'),
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
            'source' => (string) ($result['source'] ?? 'manual'),
            'promoted_to_manual' => (bool) ($result['promoted_to_manual'] ?? false),
        ];
    }

    /**
     * @return array{ok: bool, tags?: list<array{id: int, name: string}>, error?: string}
     */
    public function attachTopicTag(int $topicId, int $tagId): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || $topicId <= 0 || ! $this->canEditTopicName()) {
            return ['ok' => false, 'error' => 'denied'];
        }

        $result = app(TopicUserTagService::class)->attach($siteId, $topicId, $tagId);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'failed')];
        }

        $this->clusterDataEpoch++;

        return ['ok' => true, 'tags' => $result['tags']];
    }

    /**
     * @return array{ok: bool, tags?: list<array{id: int, name: string}>, error?: string}
     */
    public function attachTopicTagByName(int $topicId, string $name): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || $topicId <= 0 || ! $this->canEditTopicName()) {
            return ['ok' => false, 'error' => 'denied'];
        }

        $result = app(TopicUserTagService::class)->attachByName($siteId, $topicId, $name);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'failed')];
        }

        $this->clusterDataEpoch++;

        return ['ok' => true, 'tags' => $result['tags']];
    }

    /**
     * @return array{ok: bool, tags?: list<array{id: int, name: string}>, error?: string}
     */
    public function detachTopicTag(int $topicId, int $tagId): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || $topicId <= 0 || ! $this->canEditTopicName()) {
            return ['ok' => false, 'error' => 'denied'];
        }

        $result = app(TopicUserTagService::class)->detach($siteId, $topicId, $tagId);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'failed')];
        }

        $this->clusterDataEpoch++;

        return ['ok' => true, 'tags' => $result['tags']];
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
        $reconcile = is_array($result['reconcile'] ?? null) ? $result['reconcile'] : [];
        $attached = (int) ($reconcile['attached'] ?? 0) + (int) ($reconcile['moved'] ?? 0);
        $body = $attached > 0
            ? __('seo-content-ai::filament.keyword.topic_quick_create_attached', ['count' => $attached])
            : null;
        $notification = Notification::make()
            ->title(__($titleKey, ['label' => $label]))
            ->success();
        if (is_string($body) && $body !== '') {
            $notification->body($body);
        }
        $notification->send();

        $this->clusterSearch = '';
        $this->clusterSearchInput = '';
        $this->clusterDataEpoch++;
        $this->clearKeywordWorkspaceTabCountsCache();
        $this->resetPage();
    }

    public function refreshClusterSummaryCounters(): void
    {
        $this->clusterDataEpoch++;
        $this->clearKeywordWorkspaceTabCountsCache();
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
