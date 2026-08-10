<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Livewire\Attributes\Url;

trait HasKeywordWorkspaceNavigation
{
    #[Url(as: 'site_id')]
    public ?int $keywordWorkspaceSiteId = null;

    protected function initializeKeywordWorkspaceSiteFilter(): void
    {
        SeoAccessControl::setGlobalSiteId(null);

        if ($this->keywordWorkspaceSiteId !== null && $this->keywordWorkspaceSiteId <= 0) {
            $this->keywordWorkspaceSiteId = null;
        }

        $legacySiteId = (int) request()->query('site', 0);
        if ($this->keywordWorkspaceSiteId === null && $legacySiteId > 0) {
            $this->keywordWorkspaceSiteId = $legacySiteId;
        }
    }

    public function updatedKeywordWorkspaceSiteId(): void
    {
        if ($this->keywordWorkspaceSiteId !== null && $this->keywordWorkspaceSiteId <= 0) {
            $this->keywordWorkspaceSiteId = null;
        }

        if (method_exists($this, 'onKeywordWorkspaceSiteFilterChanged')) {
            $this->onKeywordWorkspaceSiteFilterChanged();
        }
    }

    public function shouldShowKeywordWorkspaceSiteFilter(): bool
    {
        return count($this->getKeywordWorkspaceSiteFilterOptions()) > 1;
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
                'url' => $this->appendKeywordWorkspaceSiteToUrl(KeywordResource::getUrl('index')),
            ],
            [
                'key' => 'focus',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_focus'),
                'url' => $this->appendKeywordWorkspaceSiteToUrl(KeywordResource::getUrl('focus')),
            ],
            [
                'key' => 'anchor-audit',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_anchor_audit'),
                'url' => $this->appendKeywordWorkspaceSiteToUrl(KeywordResource::getUrl('anchor-audit')),
            ],
            [
                'key' => 'workspace-2',
                'label' => __('seo-content-ai::filament.keyword.workspace_nav_two'),
                'url' => $this->appendKeywordWorkspaceSiteToUrl(KeywordResource::getUrl('workspace-2')),
            ],
            [
                'key' => 'cannibalization',
                'label' => __('seo-content-ai::filament.keyword.cannibalization_nav'),
                'url' => $this->appendKeywordWorkspaceSiteToUrl(KeywordResource::getUrl('cannibalization')),
            ],
        ];
    }

    protected function appendKeywordWorkspaceSiteToUrl(string $url): string
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'site_id='.$siteId;
    }

    abstract protected function getActiveKeywordWorkspaceKey(): string;
}
