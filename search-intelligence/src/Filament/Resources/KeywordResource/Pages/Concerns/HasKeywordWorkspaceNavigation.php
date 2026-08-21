<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Livewire\Attributes\On;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait HasKeywordWorkspaceNavigation
{
    public ?int $keywordWorkspaceSiteId = null;

    protected function initializeKeywordWorkspaceSiteFilter(): void
    {
        $this->syncKeywordWorkspaceSiteFromGlobal();
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $this->syncKeywordWorkspaceSiteFromGlobal(is_numeric($siteId) ? (int) $siteId : null);

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }

        if (method_exists($this, 'onKeywordWorkspaceSiteFilterChanged')) {
            $this->onKeywordWorkspaceSiteFilterChanged();
        }
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

    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    public function getKeywordWorkspaceNavItems(): array
    {
        return [
            [
                'key' => 'index',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_dictionary'),
                'url' => KeywordResource::getUrl('index'),
            ],
            [
                'key' => 'focus',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_focus'),
                'url' => KeywordResource::getUrl('focus'),
            ],
            [
                'key' => 'anchor-audit',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_anchor_audit'),
                'url' => KeywordResource::getUrl('anchor-audit'),
            ],
            [
                'key' => 'workspace-2',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_two'),
                'url' => KeywordResource::getUrl('clusters'),
            ],
            [
                'key' => 'cannibalization',
                'label' => __('seo-content-ai::filament.keyword.cannibalization_nav'),
                'url' => KeywordResource::getUrl('cannibalization'),
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

        $this->keywordWorkspaceSiteId = ($resolved !== null && $resolved > 0) ? $resolved : null;
    }

    abstract protected function getActiveKeywordWorkspaceKey(): string;
}
