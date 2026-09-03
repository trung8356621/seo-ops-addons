<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftAiCalls;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Dedicated Draft AI History (PromptResult) — opens in its own tab.
 */
final class ContentProjectDraftAiHistory extends SeoPanelPage
{
    use InteractsWithDraftAiCalls;

    protected static ?string $slug = 'content-projects/ai-history';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-draft-ai-history';

    #[Url(as: 'project')]
    public ?int $projectId = null;

    public ?SeoProject $project = null;

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageContentProjectWorkflow();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $this->resolveProjectOrAbort();
    }

    public function getTitle(): string|Htmlable
    {
        $domain = trim((string) ($this->project?->site?->domain ?? ''));
        $base = (string) __('seo-content-ai::filament.projects.draft_ai_history_page_title');

        return $domain !== '' ? $base.' — '.$domain : $base;
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::SevenExtraLarge;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        $crumbs = [
            SeoProjectResource::getUrl() => SeoProjectResource::getNavigationLabel(),
            ContentProjectSeoAuditPlanner::getUrl(
                $this->projectId !== null && $this->projectId > 0
                    ? ['project' => $this->projectId]
                    : []
            ) => (string) __('seo-content-ai::filament.projects.content_planning_title'),
        ];
        $crumbs[static::getUrl(
            $this->projectId !== null && $this->projectId > 0
                ? ['project' => $this->projectId]
                : []
        )] = (string) __('seo-content-ai::filament.projects.draft_ai_history_nav');

        return $crumbs;
    }

    public static function urlForProject(SeoProject|int $project): string
    {
        $id = $project instanceof SeoProject ? (int) $project->getKey() : (int) $project;

        return static::getUrl(['project' => $id]);
    }

    private function resolveProjectOrAbort(): void
    {
        $projectId = (int) ($this->projectId ?? 0);
        abort_if($projectId <= 0, 404);

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->with('site:id,domain')
            ->find($projectId);

        abort_unless($project instanceof SeoProject, 404);

        $projectSiteId = (int) ($project->site_id ?? 0);
        $isSharedDraft = $project->isDraftPlanning() && $projectSiteId <= 0;
        if (! $isSharedDraft) {
            abort_unless(SeoAccessControl::canAccessSite($projectSiteId), 403);
        }

        abort_unless(SeoAccessControl::canAccessContentProjectRun($project), 403);

        $this->project = $project;
        $this->projectId = (int) $project->getKey();
    }
}
