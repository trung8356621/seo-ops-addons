<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Resources\Pages\Page;

/**
 * Legacy Run History list — compatibility redirect only.
 * Canonical UI: Content Project → Project Items (ViewSeoProject / EditSeoProject).
 */
final class ListSeoProjectRuns extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.redirect-placeholder';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record;

    public function mount(int|string $record): void
    {
        self::authorizeResourceAccess();

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->find((int) $record);

        if (! $project instanceof SeoProject) {
            abort(404);
        }

        abort_unless(SeoAccessControl::canAccessContentProjectRun($project), 403);

        $this->redirect(SeoProjectResource::getProjectWorkspaceUrl($project), navigate: false);
    }
}
