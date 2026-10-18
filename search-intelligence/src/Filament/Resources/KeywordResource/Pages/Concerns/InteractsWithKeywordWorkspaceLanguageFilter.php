<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cookie;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceLanguageScope;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;

trait InteractsWithKeywordWorkspaceLanguageFilter
{
    public ?string $keywordLanguageFilter = null;

    public bool $keywordLanguagePinned = false;

    protected function initializeKeywordWorkspaceLanguageFilter(): void
    {
        $options = $this->getKeywordLanguageFilterOptions();
        $pinned = $this->pinnedKeywordWorkspaceLanguage();
        $this->keywordLanguagePinned = $pinned !== null;
        $this->keywordLanguageFilter = KeywordWorkspaceLanguageScope::resolveSelectedCode(
            $pinned,
            $options,
            $this->resolveKeywordWorkspacePrimaryLanguage(),
        );
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
        $selected = KeywordWorkspaceLanguageScope::resolveSelectedCode(
            $this->keywordLanguageFilter,
            $options,
            $this->resolveKeywordWorkspacePrimaryLanguage(),
        );
        if ($selected === null) {
            return null;
        }

        return KeywordWorkspaceLanguageScope::variantsForCode($selected);
    }

    public function updatedKeywordLanguageFilter(): void
    {
        $options = $this->getKeywordLanguageFilterOptions();
        $this->keywordLanguageFilter = KeywordWorkspaceLanguageScope::resolveSelectedCode(
            $this->keywordLanguageFilter,
            $options,
            $this->resolveKeywordWorkspacePrimaryLanguage(),
        );
        $this->keywordLanguagePinned = true;
        $this->rememberKeywordWorkspaceLanguage();

        if (method_exists($this, 'clearKeywordWorkspaceTabCountsCache')) {
            $this->clearKeywordWorkspaceTabCountsCache();
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

        if (method_exists($this, 'refreshAiAuditSnapshot')) {
            $this->refreshAiAuditSnapshot();
        }

        if (method_exists($this, 'reloadKeywordWorkspaceStatisticsAfterRender')) {
            $this->reloadKeywordWorkspaceStatisticsAfterRender();
        }

        if (method_exists($this, 'onKeywordWorkspaceLanguageFilterChanged')) {
            $this->onKeywordWorkspaceLanguageFilterChanged();
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

        return KeywordWorkspaceLanguageScope::applyToKeywordQuery(
            $query,
            $variants,
            $this->resolveKeywordWorkspaceSiteId(),
        );
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

    public function pinKeywordWorkspaceLanguage(): void
    {
        if ($this->keywordLanguagePinned) {
            return;
        }

        $this->keywordLanguagePinned = true;
        $this->rememberKeywordWorkspaceLanguage();
        if (method_exists($this, 'loadAnalysisStatus')) {
            $this->loadAnalysisStatus();
        }
        if (method_exists($this, 'focusGroupPageIfNeeded')) {
            $this->focusGroupPageIfNeeded();
        }
        if (method_exists($this, 'reloadKeywordWorkspaceStatisticsAfterRender')) {
            $this->reloadKeywordWorkspaceStatisticsAfterRender();
        }
    }

    private function pinnedKeywordWorkspaceLanguage(): ?string
    {
        if (! method_exists($this, 'resolveKeywordWorkspaceSiteId')) {
            return null;
        }

        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        $pinned = request()->cookie('kw_lang_'.$siteId);
        if (! is_string($pinned)) {
            return null;
        }

        $pinned = trim($pinned);

        return $pinned === '' ? null : $pinned;
    }

    private function rememberKeywordWorkspaceLanguage(): void
    {
        if (! method_exists($this, 'resolveKeywordWorkspaceSiteId')) {
            return;
        }

        $siteId = $this->resolveKeywordWorkspaceSiteId();
        $code = trim((string) ($this->keywordLanguageFilter ?? ''));
        if ($siteId === null || $siteId <= 0 || $code === '') {
            return;
        }

        Cookie::queue(cookie(
            'kw_lang_'.$siteId,
            $code,
            60 * 24 * 365,
            '/',
            null,
            false,
            false,
            false,
            'lax',
        ));
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
