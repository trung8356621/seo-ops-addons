<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Resources\Pages\Page;

/**
 * Legacy Run Detail — compatibility redirect only.
 * SeoProjectRun remains an internal execution record; UI does not navigate here.
 */
final class ViewSeoProjectRun extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.redirect-placeholder';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $run;

    public function mount(int|string $run): void
    {
        self::authorizeResourceAccess();

        $projectRun = SeoProjectRun::query()->with('project')->find((int) $run);
        if (! $projectRun instanceof SeoProjectRun) {
            abort(404);
        }

        $project = $projectRun->project;
        if (! $project instanceof SeoProject) {
            abort(404);
        }

        abort_unless(SeoAccessControl::canAccessContentProjectRun($project), 403);

        if (! SeoProjectResource::getRecordRouteBindingEloquentQuery()->whereKey((int) $project->getKey())->exists()) {
            abort(404);
        }

        $this->redirect(SeoProjectResource::getProjectWorkspaceUrl($project), navigate: false);
    }
}
