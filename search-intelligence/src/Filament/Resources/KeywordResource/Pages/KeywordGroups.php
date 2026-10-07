<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Jobs\RefreshKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupManualService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticSearchService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceMetricCache;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;

/**
 * Keyword Group list. Semantic refresh persists Groups only.
 *
 * Mutations: add unassigned → Group, remove Group → unassigned.
 * No direct Group-to-Group move.
 * Current-page members are visible by default (paginated Groups).
 */
final class KeywordGroups extends Page
{
    use HasKeywordWorkspaceNavigation;
    use WithPagination;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-groups';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'group')]
    public ?int $focusGroupId = null;

    public string $newGroupName = '';

    /** @var array<int, list<array{keyword_id: int, phrase: string, is_topic_candidate?: bool}>> */
    public array $loadedMembers = [];

    /** @var array<int, bool> */
    public array $memberHasMore = [];

    /** @var array<int, int> */
    public array $memberTotals = [];

    /**
     * Rename-triggered semantic suggestions keyed by Group id.
     *
     * @var array<int, list<array{keyword_id: int, phrase: string, similarity_score?: float}>>
     */
    public array $semanticSuggestions = [];

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if ($this->redirectToFirstAccessibleDomainIfNeeded()) {
            return;
        }
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->focusGroupPageIfNeeded();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.keyword_group_page_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'groups';
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->focusGroupId = null;
        $this->semanticSuggestions = [];
        $this->resetMemberState();
        $this->resetPage();
        $this->clearKeywordWorkspaceTabCountsCache();
    }

    public function canMutateKeywordGroups(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        return SeoAccessControl::canMutateInSeoPanel()
            && $siteId !== null
            && $siteId > 0
            && SeoAccessControl::canAccessSite($siteId);
    }

    public function getUnassignedCount(): int
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(KeywordGroupReadModel::class)->unassignedCount(
            $siteId,
            $this->resolveKeywordLanguageFilterVariants(),
        );
    }

    public function getGroupsPaginator(): LengthAwarePaginator
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0) {
            return new Paginator([], 0, KeywordGroupReadModel::DEFAULT_PER_PAGE);
        }

        return app(KeywordGroupReadModel::class)->paginateGroups(
            $siteId,
            max(1, (int) $this->getPage()),
            KeywordGroupReadModel::DEFAULT_PER_PAGE,
            $this->resolveKeywordLanguageFilterVariants(),
        )->withPath(KeywordResource::getUrl('groups'))
            ->appends(array_filter([
                'group' => $this->focusGroupId > 0 ? $this->focusGroupId : null,
            ], static fn (mixed $v): bool => $v !== null));
    }

    public function loadMoreMembers(int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }

        $this->loadGroupMembers($groupId, reset: false);
    }

    /**
     * @return list<array{keyword_id: int, phrase: string, similarity_score?: float}>
     */
    public function searchUnassignedKeywords(string $query = '', int $groupId = 0): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $needle = trim($query);
        if ($needle === '' && $groupId > 0 && isset($this->semanticSuggestions[$groupId])) {
            return $this->semanticSuggestions[$groupId];
        }

        return app(KeywordGroupReadModel::class)->searchUnassigned(
            $siteId,
            $needle,
            $this->resolveKeywordLanguageFilterVariants(),
        );
    }

    public function addKeywordToGroup(int $groupId, int $keywordId): void
    {
        if (! $this->canMutateKeywordGroups() || $groupId <= 0 || $keywordId <= 0) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        try {
            app(KeywordGroupManualService::class)->assignKeyword($siteId, $keywordId, $groupId);
        } catch (InvalidArgumentException) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_assign_failed'))
                ->danger()
                ->send();

            return;
        }

        unset($this->semanticSuggestions[$groupId]);
        $this->loadGroupMembers($groupId, reset: true);
        $this->afterGroupMutation();
    }

    public function removeKeywordFromGroup(int $groupId, int $keywordId): void
    {
        if (! $this->canMutateKeywordGroups() || $keywordId <= 0) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        try {
            app(KeywordGroupManualService::class)->assignKeyword($siteId, $keywordId, null);
        } catch (InvalidArgumentException) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_assign_failed'))
                ->danger()
                ->send();

            return;
        }

        if ($groupId > 0) {
            $this->loadGroupMembers($groupId, reset: true);
        }
        $this->afterGroupMutation();
    }

    public function toggleTopicCandidate(int $groupId, int $keywordId): void
    {
        if (! $this->canMutateKeywordGroups() || $groupId <= 0 || $keywordId <= 0) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if (! KeywordGroupSchema::topicCandidateReady()) {
            return;
        }

        $membership = \Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->where('group_id', $groupId)
            ->where('keyword_id', $keywordId)
            ->first(['id', 'is_topic_candidate']);
        if ($membership === null) {
            return;
        }

        try {
            app(KeywordGroupManualService::class)->setTopicCandidate(
                $siteId,
                $groupId,
                $keywordId,
                ! (bool) $membership->is_topic_candidate,
            );
        } catch (InvalidArgumentException) {
            return;
        }

        $this->loadGroupMembers($groupId, reset: true);
        $this->afterGroupMutation();
    }

    public function createGroup(): void
    {
        if (! $this->canMutateKeywordGroups()) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        try {
            $group = app(KeywordGroupManualService::class)->create($siteId, $this->newGroupName);
        } catch (InvalidArgumentException) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_name_required'))
                ->danger()
                ->send();

            return;
        }

        $this->newGroupName = '';
        $this->focusGroupId = (int) $group->id;
        $this->focusGroupPageIfNeeded();
        $this->afterGroupMutation();
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_group_created'))
            ->success()
            ->send();
    }

    public function renameGroup(int $groupId, string $name): void
    {
        if (! $this->canMutateKeywordGroups() || $groupId <= 0) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $normalized = trim($name);
        try {
            $group = app(KeywordGroupManualService::class)->rename($siteId, $groupId, $normalized);
        } catch (InvalidArgumentException) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_name_required'))
                ->danger()
                ->send();

            return;
        }

        // Rename is committed first; semantic enrichment must not roll it back.
        $enrichment = app(KeywordGroupSemanticSearchService::class)->appendAcceptedMatchesAfterRename(
            $siteId,
            $groupId,
            (string) $group->name,
            $this->resolveKeywordLanguageFilterVariants(),
        );

        unset($this->semanticSuggestions[$groupId]);
        $this->loadGroupMembers($groupId, reset: true);
        $this->afterGroupMutation();

        if ($enrichment['semantic_failed'] === true) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_rename_semantic_failed'))
                ->warning()
                ->send();
        }
    }

    public function toggleLock(int $groupId): void
    {
        if (! $this->canMutateKeywordGroups() || $groupId <= 0) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if (! KeywordGroupSchema::tablesReady()) {
            return;
        }

        $group = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereKey($groupId)
            ->first(['id', 'is_locked']);
        if (! $group instanceof SeoKeywordGroup) {
            return;
        }

        try {
            app(KeywordGroupManualService::class)->setLocked($siteId, $groupId, ! (bool) $group->is_locked);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->afterGroupMutation();
    }

    public function refreshSemanticGroups(): void
    {
        if (! $this->canMutateKeywordGroups()) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        try {
            RefreshKeywordGroupsJob::dispatchSync(
                $siteId,
                $this->keywordLanguageFilter,
                $this->resolveKeywordLanguageFilterVariants(),
            );
        } catch (SemanticHttpException $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_refresh_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->semanticSuggestions = [];
        $this->resetMemberState();
        $this->afterGroupMutation();
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_group_refresh_done'))
            ->success()
            ->send();
    }

    private function loadGroupMembers(int $groupId, bool $reset): void
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $offset = $reset ? 0 : count($this->loadedMembers[$groupId] ?? []);
        $chunk = app(KeywordGroupReadModel::class)->groupMembers(
            $siteId,
            $groupId,
            KeywordGroupReadModel::MEMBER_PAGE_SIZE,
            $offset,
        );

        if ($reset) {
            $this->loadedMembers[$groupId] = $chunk['members'];
        } else {
            $this->loadedMembers[$groupId] = array_values(array_merge(
                $this->loadedMembers[$groupId] ?? [],
                $chunk['members'],
            ));
        }
        $this->memberHasMore[$groupId] = $chunk['has_more'];
        $this->memberTotals[$groupId] = $chunk['total'];
    }

    private function focusGroupPageIfNeeded(): void
    {
        $groupId = (int) ($this->focusGroupId ?? 0);
        if ($groupId <= 0) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $page = app(KeywordGroupReadModel::class)->pageForGroup(
            $siteId,
            $groupId,
            KeywordGroupReadModel::DEFAULT_PER_PAGE,
        );
        if ($page !== null) {
            $this->setPage($page);
        }
    }

    private function resetMemberState(): void
    {
        $this->loadedMembers = [];
        $this->memberHasMore = [];
        $this->memberTotals = [];
    }

    private function afterGroupMutation(): void
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId > 0) {
            app(KeywordWorkspaceMetricCache::class)->invalidateNamespace($siteId, KeywordWorkspaceMetricCache::GROUPS);
        }
        $this->clearKeywordWorkspaceTabCountsCache();
        $this->reloadKeywordWorkspaceStatisticsAfterRender();
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
                KeywordResource::getUrl('groups'),
                (int) $first->getKey(),
            ),
            navigate: false,
        );

        return true;
    }
}
