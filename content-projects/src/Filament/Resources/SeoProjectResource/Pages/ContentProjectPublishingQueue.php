<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Resources\Pages\Page;

/**
 * Legacy nested Publishing Queue route — compatibility redirect only.
 * Canonical UI lives on the independent hub: Omnichannel\Addons\ContentProjects\Filament\Pages\PublishingQueueHub.
 *
 * @see \Omnichannel\Addons\ContentProjects\Filament\Pages\PublishingQueueHub
 */
final class ContentProjectPublishingQueue extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.redirect-placeholder';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record = 0;

    public function mount(int|string $record): void
    {
        $this->record = $record;
        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find($record);
        abort_unless($project instanceof SeoProject, 404);
        abort_unless(SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0)), 403);
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $this->redirect(SeoProjectResource::getPublishingQueueUrl($project), navigate: false);
    }
}
