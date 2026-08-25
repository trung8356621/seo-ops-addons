<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithSeoAuditSuggestions;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Throwable;

/**
 * Canonical Content Planning page (UI: Lập kế hoạch nội dung).
 * Route slug kept as content-projects/seo-audit for deep-link compatibility.
 * Navigation item owned by SeoProjectResource::getNavigationItems().
 */
final class ContentProjectSeoAuditPlanner extends SeoPanelPage
{
    use InteractsWithDraftSplit;
    use InteractsWithNewContentSuggestions;
    use InteractsWithSeoAuditSuggestions;
    use WithPagination;

    protected static ?string $slug = 'content-projects/seo-audit';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-seo-audit-planner';

    #[Url(as: 'project')]
    public ?int $projectId = null;

    #[Url(as: 'site')]
    public ?int $filterSiteId = null;

    /** Advanced SEO Audit candidate table (not primary workflow). */
    #[Url(as: 'advanced')]
    public bool $advanced = false;

    public ?SeoProject $project = null;

    /** @var list<int> */
    public array $selectedTaskIds = [];

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
        $this->workspaceTab = 'suggestions';
        $this->resolveSelectedProject();
        $this->mountInteractsWithSeoAuditSuggestions();
        $this->mountInteractsWithNewContentSuggestions();
        $this->mountInteractsWithDraftSplit();
    }

    public function getTitle(): string|Htmlable
    {
        if ($this->advanced) {
            return __('seo-content-ai::filament.projects.seo_audit_advanced_heading');
        }

        return __('seo-content-ai::filament.projects.content_planning_title');
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            SeoProjectResource::getUrl() => SeoProjectResource::getNavigationLabel(),
            static::getUrl() => (string) __('seo-content-ai::filament.projects.content_planning_title'),
        ];
    }

    public function updatedProjectId(): void
    {
        $this->resolveSelectedProject();
        $this->clearSuggestionSelection();
        $this->selectedTaskIds = [];
        $this->resetPage('suggestionsPage');
        $this->mountInteractsWithSeoAuditSuggestions();
        $this->mountInteractsWithNewContentSuggestions();
        $this->mountInteractsWithDraftSplit();
    }

    public function updatedFilterSiteId(): void
    {
        if ($this->project instanceof SeoProject) {
            $siteId = (int) ($this->filterSiteId ?? 0);
            if ($siteId > 0 && (int) ($this->project->site_id ?? 0) !== $siteId) {
                $this->projectId = null;
                $this->project = null;
            }
        }

        $this->clearSuggestionSelection();
        $this->selectedTaskIds = [];
        $this->resetPage('suggestionsPage');
        $this->mountInteractsWithNewContentSuggestions();
    }

    /**
     * @return list<array{id: int, name: string, site_id: int, domain: string}>
     */
    public function getDraftProjectOptionsProperty(): array
    {
        $query = SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->with('site:id,domain')
            ->where('status', SeoProject::STATUS_DRAFT)
            ->activeProjects()
            ->where(function (Builder $q): void {
                $q->whereNull('kind')->orWhere('kind', '!=', SeoProject::KIND_ARCHIVE);
            })
            ->orderByDesc('updated_at');

        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0 && $this->project instanceof SeoProject) {
            $siteId = (int) ($this->project->site_id ?? 0);
        }

        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $out = [];
        foreach ($query->limit(100)->get() as $draft) {
            if (! $draft instanceof SeoProject || ! $draft->isDraftPlanning()) {
                continue;
            }
            $out[] = [
                'id' => (int) $draft->getKey(),
                'name' => (string) $draft->name,
                'site_id' => (int) ($draft->site_id ?? 0),
                'domain' => (string) ($draft->site?->domain ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function getSiteFilterOptionsProperty(): array
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->pluck('domain', 'id')->all();
    }

    /**
     * Compact Draft items for the Content Planning page (not SEO Audit candidates).
     *
     * @return list<array{
     *   id: int,
     *   title: string,
     *   type: string,
     *   type_label: string,
     *   source_label: string,
     *   why: string|null,
     *   updated_label: string,
     * }>
     */
    public function getDraftPlanningItemsProperty(): array
    {
        if (! $this->project instanceof SeoProject || ! $this->project->isDraftPlanning()) {
            return [];
        }

        $projectId = (int) $this->project->getKey();
        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->planned()
            ->inContentProjectWorkingSet()
            ->with(['itemOrigin'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $out = [];
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $type = strtolower(trim((string) ($task->type ?? '')));
            $origin = $task->itemOrigin;
            $source = $origin instanceof SeoContentProjectItemOrigin
                ? (string) ($origin->source_type ?? '')
                : '';
            $meta = is_array($origin?->metadata) ? $origin->metadata : [];
            $why = isset($meta['suggestion_reason']) ? trim((string) $meta['suggestion_reason']) : '';
            if ($why === '' && isset($meta['source_signal'])) {
                $why = trim((string) $meta['source_signal']);
            }

            $out[] = [
                'id' => (int) $task->getKey(),
                'title' => (string) ($task->title !== '' ? $task->title : ('#'.$task->getKey())),
                'type' => $type,
                'type_label' => match ($type) {
                    SeoProjectTask::TYPE_REWRITE => 'Rewrite',
                    SeoProjectTask::TYPE_IMPROVE => 'Improve',
                    SeoProjectTask::TYPE_CREATE => 'Create',
                    default => ucfirst($type !== '' ? $type : 'Item'),
                },
                'source_label' => match ($source) {
                    SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT => 'SEO Audit',
                    SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT => 'AI Suggestion',
                    default => $source !== '' ? $source : 'Manual',
                },
                'why' => $why !== '' ? $why : null,
                'updated_label' => $task->updated_at?->diffForHumans() ?? '—',
            ];
        }

        return $out;
    }

    public function createDraftForPlanner(): void
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.seo_audit_draft_site_required'))
                ->warning()
                ->send();

            return;
        }

        $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '');

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                new CreateContentProjectCommand([
                    'name' => SeoProject::defaultDraftName($domain !== '' ? $domain : null),
                    'site_id' => $siteId,
                    'status' => SeoProject::STATUS_DRAFT,
                    'user_id' => auth()->id() !== null ? (int) auth()->id() : null,
                    'month' => SeoProject::draftCompatibilityMonth(),
                ]),
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    $siteId,
                ),
            );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.content_planning.create_draft',
                'site_id' => $siteId,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_create_draft_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result->success || $result->projectId === null || $result->projectId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_create_draft_failed'))
                ->body($result->message)
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.suggestions_draft_created'))
            ->success()
            ->send();

        $this->redirect(static::getUrl([
            'project' => $result->projectId,
            'site' => $siteId,
        ]));
    }

    public function openPublishFromPlanner(): void
    {
        $this->openDraftSplitModal();
    }

    protected function requireProject(): SeoProject
    {
        if ($this->project instanceof SeoProject) {
            return $this->project;
        }

        Notification::make()
            ->warning()
            ->title((string) __('seo-content-ai::filament.projects.seo_audit_draft_required_title'))
            ->body((string) __('seo-content-ai::filament.projects.seo_audit_draft_required_body'))
            ->send();

        throw new Halt;
    }

    protected function resolvePlannerProject(): ?SeoProject
    {
        return $this->project instanceof SeoProject ? $this->project : null;
    }

    private function resolveSelectedProject(): void
    {
        $this->project = null;
        if ($this->projectId === null || $this->projectId <= 0) {
            return;
        }

        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->with('site:id,domain')
            ->find($this->projectId);

        if (! $project instanceof SeoProject || ! SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0))) {
            $this->projectId = null;

            return;
        }

        $this->project = $project;

        if ($this->filterSiteId === null || $this->filterSiteId <= 0) {
            $this->filterSiteId = (int) ($project->site_id ?? 0) ?: null;
        }
    }
}
