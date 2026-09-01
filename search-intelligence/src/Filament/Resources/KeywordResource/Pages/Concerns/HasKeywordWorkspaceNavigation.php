<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Livewire\Attributes\On;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
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
     * @var array{total: int, topics: int, dictionary: int, focus: int}|null
     */
    private ?array $keywordWorkspaceTabCountsCache = null;

    private ?string $keywordWorkspaceTabCountsCacheKey = null;

    protected function initializeKeywordWorkspaceSiteFilter(): void
    {
        $this->syncKeywordWorkspaceSiteFromGlobal();
        $this->initializeKeywordWorkspaceLanguageFilter();
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $this->keywordWorkspaceTabCountsCache = null;
        $this->keywordWorkspaceTabCountsCacheKey = null;
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
     * Inventory counts for Topics / Dictionary / Focus tabs + header Total badge.
     * Scoped by site + language filter only — ignores table search/filters.
     *
     * @return array{total: int, topics: int, dictionary: int, focus: int}
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

        // Total = distinct UI inventory rows (not Dictionary tab + Focus tab).
        $total = app(KeywordUiInventoryQuery::class)->count($siteId, $languageVariants);
        $dictionary = $total;
        $focus = (int) app(KeywordDictionaryQuery::class)
            ->filtered($siteId, $languageVariants, ['focus' => true])
            ->count();

        if (method_exists($this, 'getSummary')) {
            /** @var array<string, mixed> $summary */
            $summary = $this->getSummary();
            $topics = (int) ($summary['topic_clusters'] ?? 0);
        } else {
            $topics = (int) (app(KeywordClusterQuery::class)
                ->summary($siteId, $languageVariants)['topic_clusters'] ?? 0);
        }

        $this->keywordWorkspaceTabCountsCacheKey = $cacheKey;

        return $this->keywordWorkspaceTabCountsCache = [
            'total' => $total,
            'topics' => $topics,
            'dictionary' => $dictionary,
            'focus' => $focus,
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
                'key' => 'workspace-2',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_two'),
                'url' => KeywordResource::getUrl('clusters'),
                'count' => $counts['topics'],
            ],
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
                'key' => 'cannibalization',
                'label' => __('seo-content-ai::filament.keyword.cannibalization_nav'),
                'url' => KeywordResource::getUrl('cannibalization'),
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
