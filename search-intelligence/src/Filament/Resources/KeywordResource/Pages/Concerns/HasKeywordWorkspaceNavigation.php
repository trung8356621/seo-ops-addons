<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Livewire\Attributes\On;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait HasKeywordWorkspaceNavigation
{
    use InteractsWithKeywordWorkspaceLanguageFilter;

    public ?int $keywordWorkspaceSiteId = null;

    /**
     * Request-scoped inventory tab counts (not table search/filter counts).
     *
     * @var array{total: int, dictionary: int, focus: int, topics: int, tags: int}|null
     */
    private ?array $keywordWorkspaceTabCountsCache = null;

    private ?string $keywordWorkspaceTabCountsCacheKey = null;

    protected function initializeKeywordWorkspaceSiteFilter(): void
    {
        $this->syncKeywordWorkspaceSiteFromGlobal();
        $this->initializeKeywordWorkspaceLanguageFilter();
    }

    protected function clearKeywordWorkspaceTabCountsCache(): void
    {
        $this->keywordWorkspaceTabCountsCache = null;
        $this->keywordWorkspaceTabCountsCacheKey = null;
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $this->clearKeywordWorkspaceTabCountsCache();
        $this->syncKeywordWorkspaceSiteFromGlobal(is_numeric($siteId) ? (int) $siteId : null);
        $this->initializeKeywordWorkspaceLanguageFilter();

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }

        if (method_exists($this, 'onKeywordWorkspaceSiteFilterChanged')) {
            $this->onKeywordWorkspaceSiteFilterChanged();
        }

        $this->dispatchKeywordWorkspaceLanguageContext();
    }

    public function shouldShowKeywordWorkspaceSiteFilter(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function getKeywordWorkspaceSiteFilterOptions(): array
    {
        return KeywordResource::siteSelectOptions();
    }

    public function resolveKeywordWorkspaceSiteId(): ?int
    {
        $this->syncKeywordWorkspaceSiteFromGlobal();
        $siteId = $this->keywordWorkspaceSiteId;

        return ($siteId !== null && $siteId > 0) ? $siteId : null;
    }

    public function getKeywordModuleDomainLabel(): string
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return '';
        }

        $options = $this->getKeywordWorkspaceSiteFilterOptions();

        return trim((string) ($options[$siteId] ?? $options[(string) $siteId] ?? ''));
    }

    public function getKeywordModuleHeading(): string
    {
        $domain = $this->getKeywordModuleDomainLabel();
        if ($domain === '') {
            return (string) __('seo-content-ai::filament.keyword.module_heading_fallback');
        }

        return (string) __('seo-content-ai::filament.keyword.module_heading', ['domain' => $domain]);
    }

    /**
     * Unique keyword inventory for the module header badge.
     * Same SSOT as Dictionary base ({@see KeywordUiInventoryQuery}) — not Dictionary+Focus.
     * Respects global Keywords language selector.
     */
    public function getKeywordWorkspaceTotalKeywords(): int
    {
        return $this->getKeywordWorkspaceTabCounts()['total'];
    }

    /**
     * Inventory counts for Dictionary / Focus / Topics / Tags tabs + header Total badge.
     * Scoped by site + language filter only — ignores table search/filters.
     * Topics tab count = Topics with ≥1 keyword in the selected language inventory
     * (Topics have no language column; membership is the language gate).
     * Tags tab count = custom Topic tags for current site only (not built-in badges).
     *
     * @return array{total: int, dictionary: int, focus: int, topics: int, tags: int}
     */
    public function getKeywordWorkspaceTabCounts(): array
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $languageVariants = $this->resolveKeywordLanguageFilterVariants();
        $cacheKey = ($siteId ?? 0).'|'.implode(',', $languageVariants ?? ['*']);

        if (
            $this->keywordWorkspaceTabCountsCache !== null
            && $this->keywordWorkspaceTabCountsCacheKey === $cacheKey
        ) {
            return $this->keywordWorkspaceTabCountsCache;
        }

        $total = app(KeywordUiInventoryQuery::class)->count($siteId, $languageVariants);
        $dictionary = $total;
        $focus = (int) app(KeywordDictionaryQuery::class)
            ->filtered($siteId, $languageVariants, ['focus' => true])
            ->count();
        $topics = 0;
        $tags = 0;
        if ($siteId !== null && $siteId > 0) {
            $topics = (int) app(\Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery::class)
                ->summary($siteId, $languageVariants)['topic_count'];
            $tags = app(\Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService::class)
                ->countForSite($siteId);
        }

        $this->keywordWorkspaceTabCountsCacheKey = $cacheKey;

        return $this->keywordWorkspaceTabCountsCache = [
            'total' => $total,
            'dictionary' => $dictionary,
            'focus' => $focus,
            'topics' => $topics,
            'tags' => $tags,
        ];
    }

    /**
     * @return list<array{key: string, label: string, url: string, count?: int|null}>
     */
    public function getKeywordWorkspaceNavItems(): array
    {
        $counts = $this->getKeywordWorkspaceTabCounts();

        return [
            [
                'key' => 'index',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_dictionary'),
                'url' => KeywordResource::getUrl('index'),
                'count' => $counts['dictionary'],
            ],
            [
                'key' => 'focus',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_focus'),
                'url' => KeywordResource::getUrl('focus'),
                'count' => $counts['focus'],
            ],
            [
                'key' => 'clusters',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_two'),
                'url' => KeywordResource::getUrl('clusters'),
                'count' => $counts['topics'],
            ],
            [
                'key' => 'tags',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_tags'),
                'url' => KeywordResource::getUrl('topic-tags'),
                'count' => $counts['tags'],
            ],
            [
                'key' => 'anchor-audit',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_anchor_audit'),
                'url' => KeywordResource::getUrl('anchor-audit'),
            ],
        ];
    }

    protected function appendKeywordWorkspaceSiteToUrl(string $url): string
    {
        return $url;
    }

    private function syncKeywordWorkspaceSiteFromGlobal(?int $siteId = null): void
    {
        $resolved = $siteId !== null && $siteId > 0
            ? $siteId
            : SeoAccessControl::globalSiteId();

        if ($resolved === null || $resolved <= 0) {
            // Never leave Keyword Intelligence on All domains (unscoped = heavy).
            $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->value('id');
            $resolved = is_numeric($first) ? (int) $first : null;
        }

        $this->keywordWorkspaceSiteId = ($resolved !== null && $resolved > 0) ? $resolved : null;
    }

    abstract protected function getActiveKeywordWorkspaceKey(): string;
}
