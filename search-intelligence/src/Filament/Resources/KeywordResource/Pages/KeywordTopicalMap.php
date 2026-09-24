<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\TopicalMapAppPage;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

/**
 * Legacy Keywords Topical Map route — redirects to the standalone React app.
 *
 * Canonical UI: {@see TopicalMapAppPage} at /seo/topical-map
 */
final class KeywordTopicalMap extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-topical-map';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0) {
            $siteId = app(TopicalMapAccess::class)->resolveSiteId(null);
        }

        $this->redirect(TopicalMapAppPage::appUrl($siteId > 0 ? $siteId : null), navigate: false);
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('seo-content-ai::filament.keyword.topical_map_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'topical-map';
    }
}
