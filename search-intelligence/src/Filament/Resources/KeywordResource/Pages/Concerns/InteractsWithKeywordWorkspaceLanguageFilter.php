<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceLanguageScope;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;

trait InteractsWithKeywordWorkspaceLanguageFilter
{
    public ?string $keywordLanguageFilter = null;

    protected function initializeKeywordWorkspaceLanguageFilter(): void
    {
        $primary = $this->resolveKeywordWorkspacePrimaryLanguage();
        $options = $this->getKeywordLanguageFilterOptions();

        if ($primary !== null && isset($options[$primary])) {
            $this->keywordLanguageFilter = $primary;

            return;
        }

        $first = array_key_first($options);
        $this->keywordLanguageFilter = is_string($first) ? $first : null;
    }

    /**
     * @return array<string, string>
     */
    public function getKeywordLanguageFilterOptions(): array
    {
        $site = $this->resolveKeywordWorkspaceSiteModel();
        if (! $site instanceof Site) {
            return [];
        }

        return app(SitePrimaryLanguageService::class)->formLanguageOptions($site);
    }

    public function resolveKeywordWorkspacePrimaryLanguage(): ?string
    {
        $site = $this->resolveKeywordWorkspaceSiteModel();
        if (! $site instanceof Site) {
            return null;
        }

        return app(SitePrimaryLanguageService::class)->resolvePrimaryLanguage($site);
    }

    /**
     * @return list<string>|null
     */
    public function resolveKeywordLanguageFilterVariants(): ?array
    {
        $options = $this->getKeywordLanguageFilterOptions();
        $selected = trim((string) ($this->keywordLanguageFilter ?? ''));
        if ($selected === '' || ! isset($options[$selected])) {
            return null;
        }

        return KeywordWorkspaceLanguageScope::variantsForCode($selected);
    }

    public function updatedKeywordLanguageFilter(): void
    {
        $options = $this->getKeywordLanguageFilterOptions();
        $selected = trim((string) ($this->keywordLanguageFilter ?? ''));
        if ($selected !== '' && ! isset($options[$selected])) {
            $this->initializeKeywordWorkspaceLanguageFilter();
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }

        if (method_exists($this, 'flushCachedTableRecords')) {
            $this->flushCachedTableRecords();
        }

        if (property_exists($this, 'clusterDataEpoch')) {
            $this->clusterDataEpoch++;
        }
    }

    protected function dispatchKeywordWorkspaceLanguageContext(): void
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();

        $this->dispatch(
            'keyword-workspace-language-site-changed',
            siteId: $siteId,
            primaryLanguage: $this->resolveKeywordWorkspacePrimaryLanguage(),
            selectedLanguage: $this->keywordLanguageFilter,
            optionCodes: array_keys($this->getKeywordLanguageFilterOptions()),
        );
    }

    /**
     * @param  Builder<\Omnichannel\Addons\SearchFoundation\Models\Keyword>  $query
     * @return Builder<\Omnichannel\Addons\SearchFoundation\Models\Keyword>
     */
    protected function applyKeywordWorkspaceLanguageScope(Builder $query): Builder
    {
        $variants = $this->resolveKeywordLanguageFilterVariants();
        if ($variants === null) {
            return $query;
        }

        return KeywordWorkspaceLanguageScope::applyToKeywordQuery($query, $variants);
    }

    /**
     * @param  Builder<\Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap>  $query
     * @return Builder<\Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap>
     */
    protected function applyKeywordWorkspaceLanguageScopeToLinkMaps(Builder $query): Builder
    {
        $variants = $this->resolveKeywordLanguageFilterVariants();
        if ($variants === null) {
            return $query;
        }

        return KeywordWorkspaceLanguageScope::applyToSeoLinkMapQuery($query, $variants);
    }

    private function resolveKeywordWorkspaceSiteModel(): ?Site
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        $site = SeoAccessControl::accessibleSitesQuery()
            ->whereKey($siteId)
            ->first();

        return $site instanceof Site ? $site : null;
    }
}
