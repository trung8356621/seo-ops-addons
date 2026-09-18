<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Livewire\WithPagination;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicBuiltinShortcutStats;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Site-scoped custom Topic tag management + built-in Topic filter shortcuts.
 */
final class KeywordTopicTags extends Page
{
    use HasKeywordWorkspaceNavigation;
    use WithPagination;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.topic-tags';

    protected static bool $shouldRegisterNavigation = false;

    public string $tagSearch = '';

    public string $tagSearchInput = '';

    public int $tagsDataEpoch = 0;

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        if ($this->redirectToFirstAccessibleDomainIfNeeded()) {
            return;
        }
        $this->dispatchKeywordWorkspaceLanguageContext();
        $this->tagSearchInput = $this->tagSearch;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.topic_tags_page_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'tags';
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->tagsDataEpoch++;
        $this->clearKeywordWorkspaceTabCountsCache();
        $this->resetPage();
    }

    public function applyTagSearch(): void
    {
        $this->tagSearch = trim($this->tagSearchInput);
        $this->tagSearchInput = $this->tagSearch;
        $this->resetPage();
    }

    public function clearTagSearch(): void
    {
        $this->tagSearch = '';
        $this->tagSearchInput = '';
        $this->resetPage();
    }

    /**
     * @return list<array{key: string, group: string, label: string, topic_count: int, filter: array<string, string>}>
     */
    public function getBuiltinShortcuts(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        return app(TopicBuiltinShortcutStats::class)->forSite($siteId);
    }

    public function getCustomTagsPaginator(): LengthAwarePaginator
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || ! TopicUserTagService::tablesReady()) {
            return new Paginator([], 0, 25);
        }

        $rows = app(TopicUserTagService::class)->listForSite($siteId, $this->tagSearch, 0);
        $perPage = 25;
        $page = max(1, (int) Paginator::resolveCurrentPage());
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return (new Paginator($slice, $total, $perPage, $page, [
            'path' => KeywordResource::getUrl('topic-tags'),
        ]))->appends(array_filter([
            'site_id' => $siteId > 0 ? $siteId : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    public function topicsUrlForCustomTag(int $tagId): string
    {
        return $this->topicsUrlWithFilters([
            'topic_tags' => (string) max(0, $tagId),
        ]);
    }

    /**
     * @param  array{intent?: string, coverage?: string, source?: string}  $filter
     */
    public function topicsUrlForBuiltin(array $filter): string
    {
        $query = [];
        foreach (['intent', 'coverage', 'source'] as $key) {
            $value = trim((string) ($filter[$key] ?? ''));
            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        return $this->topicsUrlWithFilters($query);
    }

    public function deleteCustomTag(int $tagId): void
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0 || $tagId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_tags_delete_failed'))
                ->danger()
                ->send();

            return;
        }

        $result = app(TopicUserTagService::class)->deleteTag($siteId, $tagId);
        if (! ($result['ok'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_tags_delete_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_tags_delete_success'))
            ->success()
            ->send();

        $this->tagsDataEpoch++;
        $this->clearKeywordWorkspaceTabCountsCache();
        $this->resetPage();
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function topicsUrlWithFilters(array $extra): string
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $base = KeywordResource::getUrl('clusters');
        $query = array_filter($extra, static fn (string $v): bool => $v !== '');

        if ($query !== []) {
            $base .= (str_contains($base, '?') ? '&' : '?').http_build_query($query);
        }

        return app(DomainContextResolver::class)->appendSiteToUrl($base, $siteId);
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
                KeywordResource::getUrl('topic-tags'),
                (int) $first->getKey(),
            ),
            navigate: false,
        );

        return true;
    }
}
