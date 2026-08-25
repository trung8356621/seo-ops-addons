<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Legacy New Content planner route — redirects into unified Content Planning.
 * Slug kept for Agent / deep-link / test compatibility.
 */
final class ContentProjectNewContentPlanner extends SeoPanelPage
{
    protected static ?string $slug = 'content-projects/new-content';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-new-content-planner';

    #[Url(as: 'project')]
    public ?int $projectId = null;

    #[Url(as: 'site')]
    public ?int $filterSiteId = null;

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.projects.content_planning_nav_label');
    }

    public static function getNavigationParentItem(): ?string
    {
        return SeoProjectResource::getNavigationLabel();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageContentProjectWorkflow();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $params = [];
        if ($this->projectId !== null && $this->projectId > 0) {
            $params['project'] = $this->projectId;
        }
        if ($this->filterSiteId !== null && $this->filterSiteId > 0) {
            $params['site'] = $this->filterSiteId;
        }

        $this->redirect(ContentProjectSeoAuditPlanner::getUrl($params), navigate: false);
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.projects.content_planning_title');
    }
}
