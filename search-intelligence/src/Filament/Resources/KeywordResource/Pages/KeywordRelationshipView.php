<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\KeywordRelationshipAppPage;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;

/**
 * Legacy Keywords Relationship route — redirects to the standalone React app.
 *
 * Canonical UI: {@see KeywordRelationshipAppPage} at /seo/topical-map/keyword/{id}
 */
final class KeywordRelationshipView extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-relationship';

    protected static bool $shouldRegisterNavigation = false;

    public int $keyword = 0;

    public function mount(int|string $keyword): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
        $this->keyword = (int) $keyword;
        abort_unless($this->keyword > 0, 404);

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0) {
            $siteId = app(TopicalMapAccess::class)->resolveSiteId(null);
        }

        $this->redirect(
            KeywordRelationshipAppPage::appUrl($this->keyword, $siteId > 0 ? $siteId : null),
            navigate: false,
        );
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('seo-content-ai::filament.keyword.relationship_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'index';
    }
}
