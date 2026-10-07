<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Jobs\RefreshKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupManualService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceMetricCache;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;

/**
 * Keyword Group list. Semantic refresh persists Groups only.
 */
final class KeywordGroups extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-groups';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'group')]
    public ?int $focusGroupId = null;

    public string $newGroupName = '';

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if ($this->redirectToFirstAccessibleDomainIfNeeded()) {
            return;
        }
        $this->dispatchKeywordWorkspaceLanguageContext();
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

    /**
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     unassigned: list<array{keyword_id: int, phrase: string}>,
     *     unassigned_count: int
     * }
     */
    public function getKeywordGroupView(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $inventory = app(KeywordGroupCandidateLoader::class)->load(
            $siteId,
            $this->resolveKeywordLanguageFilterVariants(),
        );

        return app(KeywordGroupReadModel::class)->forSite($siteId, $inventory);
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
        $this->afterGroupMutation();
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_group_created'))
            ->success()
            ->send();
    }

    public function renameGroup(int $groupId, string $name): void
    {
        if (! $this->canMutateKeywordGroups()) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        try {
            app(KeywordGroupManualService::class)->rename($siteId, $groupId, $name);
        } catch (InvalidArgumentException) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_name_required'))
                ->danger()
                ->send();

            return;
        }

        $this->afterGroupMutation();
    }

    public function toggleLock(int $groupId): void
    {
        if (! $this->canMutateKeywordGroups()) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $view = $this->getKeywordGroupView();
        $locked = false;
        foreach ($view['groups'] as $group) {
            if ((int) ($group['id'] ?? 0) === $groupId) {
                $locked = (bool) ($group['is_locked'] ?? false);
                break;
            }
        }

        try {
            app(KeywordGroupManualService::class)->setLocked($siteId, $groupId, ! $locked);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->afterGroupMutation();
    }

    public function assignKeyword(int $keywordId, string $targetGroupId): void
    {
        if (! $this->canMutateKeywordGroups()) {
            return;
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        $target = (int) $targetGroupId;
        try {
            app(KeywordGroupManualService::class)->assignKeyword(
                $siteId,
                $keywordId,
                $target > 0 ? $target : null,
            );
        } catch (InvalidArgumentException) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.keyword_group_assign_failed'))
                ->danger()
                ->send();

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

        $this->afterGroupMutation();
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.keyword_group_refresh_done'))
            ->success()
            ->send();
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
