<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithSeoAuditSuggestions;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Schema;
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

    public ?SeoProject $project = null;

    /** @var list<int> */
    public array $selectedTaskIds = [];

    /** all|unreviewed|reviewed */
    public string $draftReviewFilter = 'all';

    /** all|rewrite|improve|create */
    public string $draftTypeFilter = 'all';

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

        if ($this->shouldRedirectLegacyAdvancedParam()) {
            $this->redirect(static::getUrl($this->canonicalPlannerQueryParams()), navigate: false);

            return;
        }

        $this->workspaceTab = 'suggestions';
        $this->resolveSelectedProject();
        $this->autoSelectSiteDraftIfNeeded();
        $this->mountInteractsWithSeoAuditSuggestions();
        $this->mountInteractsWithNewContentSuggestions();
        $this->mountInteractsWithDraftSplit();
    }

    public function getTitle(): string|Htmlable
    {
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
        $this->projectId = null;
        $this->project = null;
        $this->selectedTaskIds = [];
        $this->clearSuggestionSelection();
        $this->resetPage('suggestionsPage');

        $this->autoSelectSiteDraftIfNeeded();
        $this->mountInteractsWithSeoAuditSuggestions();
        $this->mountInteractsWithNewContentSuggestions();
        $this->mountInteractsWithDraftSplit();
    }

    /**
     * @return array{id: int, name: string, site_id: int, domain: string}|null
     */
    public function getCanonicalPlanningDraftProperty(): ?array
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0 && $this->project instanceof SeoProject) {
            $siteId = (int) ($this->project->site_id ?? 0);
        }
        if ($siteId <= 0) {
            return null;
        }

        $draft = app(PlanningDraftResolver::class)->findPlanningDraftForSite($siteId);
        if (! $draft instanceof SeoProject) {
            return null;
        }

        return [
            'id' => (int) $draft->getKey(),
            'name' => (string) $draft->name,
            'site_id' => (int) ($draft->site_id ?? 0),
            'domain' => (string) ($draft->site?->domain ?? ''),
        ];
    }

    /**
     * @deprecated Prefer canonicalPlanningDraft — kept for blade/test compatibility.
     * @return list<array{id: int, name: string, site_id: int, domain: string}>
     */
    public function getDraftProjectOptionsProperty(): array
    {
        $canonical = $this->canonicalPlanningDraft;

        return $canonical !== null ? [$canonical] : [];
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
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   counts: array{all: int, unreviewed: int, reviewed: int}
     * }
     */
    public function getDraftPlanningPayloadProperty(): array
    {
        if (! $this->project instanceof SeoProject || ! $this->project->isDraftPlanning()) {
            return [
                'rows' => [],
                'counts' => ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0],
            ];
        }

        return app(ContentProjectDraftPlanningItemsReadModel::class)->forProject($this->project, [
            'review' => $this->draftReviewFilter,
            'type' => $this->draftTypeFilter,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDraftPlanningItemsProperty(): array
    {
        return $this->draftPlanningPayload['rows'] ?? [];
    }

    /**
     * @return array{all: int, unreviewed: int, reviewed: int}
     */
    public function getDraftPlanningCountsProperty(): array
    {
        $counts = $this->draftPlanningPayload['counts'] ?? [];

        return [
            'all' => (int) ($counts['all'] ?? 0),
            'unreviewed' => (int) ($counts['unreviewed'] ?? 0),
            'reviewed' => (int) ($counts['reviewed'] ?? 0),
        ];
    }

    public function setDraftReviewFilter(string $filter): void
    {
        $normalized = strtolower(trim($filter));
        $this->draftReviewFilter = in_array($normalized, ['all', 'unreviewed', 'reviewed'], true)
            ? $normalized
            : 'all';
        $this->selectedTaskIds = [];
    }

    public function setDraftTypeFilter(string $filter): void
    {
        $normalized = strtolower(trim($filter));
        $this->draftTypeFilter = in_array($normalized, ['all', 'rewrite', 'improve', 'create'], true)
            ? $normalized
            : 'all';
        $this->selectedTaskIds = [];
    }

    public function setPlanningReviewed(int $taskId, bool $reviewed): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planning_review_unavailable'))
                ->body(__('seo-content-ai::filament.projects.planning_review_migration_required'))
                ->danger()
                ->send();

            throw new Halt;
        }

        $project = $this->requireProject();
        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            throw new Halt;
        }

        if ($reviewed) {
            $task->planning_reviewed_at = now();
            $task->planning_reviewed_by = auth()->id() !== null ? (int) auth()->id() : null;
        } else {
            $task->planning_reviewed_at = null;
            $task->planning_reviewed_by = null;
        }
        $task->save();
    }

    /**
     * Inline Draft metadata save (Title / Keyword / Description).
     */
    public function updatePlanningField(int $taskId, string $field, string $value): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $field = strtolower(trim($field));
        if (! in_array($field, ['title', 'keyword', 'description'], true)) {
            return;
        }

        $project = $this->requireProject();
        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            return;
        }

        $trimmed = trim($value);

        match ($field) {
            'title' => $task->title = $trimmed !== '' ? $trimmed : $task->title,
            'keyword' => $this->applyPlanningKeyword($task, $trimmed),
            'description' => $this->applyPlanningDescription($task, $trimmed),
        };

        $task->save();
    }

    public function archiveOne(int $taskId): void
    {
        $this->dispatchDraftArchive([$taskId]);
    }

    public function skipSeoAuditOne(int $taskId): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $project = $this->requireProject();
        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereKey($taskId)
            ->first();

        $articleId = (int) ($task?->article_id ?? 0);
        if ($articleId <= 0 || ! $task instanceof SeoProjectTask) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.queue_select_required'))
                ->warning()
                ->send();

            throw new Halt;
        }

        $actor = ActorContext::user(
            auth()->id() !== null ? (int) auth()->id() : null,
            (int) ($project->site_id ?? 0) ?: null,
        );

        $skipResult = app(ContentProjectCommandBus::class)->dispatch(
            new SkipSeoAuditArticlesCommand(
                (int) $project->getKey(),
                [$articleId],
            ),
            $actor,
        );

        if (! $skipResult->success) {
            Notification::make()
                ->title('Failed')
                ->body($skipResult->message)
                ->danger()
                ->send();

            throw new Halt;
        }

        // Soft-remove from Draft without project-scoped dismissal (Restore → Fill again).
        $archiveResult = app(ContentProjectCommandBus::class)->dispatch(
            new ArchiveProjectItemsCommand(
                (int) $project->getKey(),
                [(int) $task->getKey()],
                note: 'global_skip_seo_audit',
                removeReason: ArchiveProjectItemsCommand::REASON_GLOBAL_SKIP,
            ),
            $actor,
        );

        if ($archiveResult->success) {
            $archived = [(int) $task->getKey() => true];
            $this->selectedTaskIds = array_values(array_filter(
                $this->normalizeSelectedIds($this->selectedTaskIds),
                static fn (int $id): bool => ! isset($archived[$id]),
            ));
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.seo_audit_skipped_on'))
            ->body($archiveResult->success
                ? $skipResult->message
                : $skipResult->message.' · '.$archiveResult->message)
            ->{$archiveResult->success ? 'success' : 'warning'}()
            ->send();

        if (! $archiveResult->success) {
            throw new Halt;
        }
    }

    public function plannerRunDetailUrl(int $plannerRunId): string
    {
        $project = $this->project;
        if (! $project instanceof SeoProject || $plannerRunId <= 0) {
            return '#';
        }

        return ContentProjectPlannerRunDetail::urlFor($project, $plannerRunId);
    }

    public function draftAiHistoryUrl(): string
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return '#';
        }

        return ContentProjectDraftAiHistory::urlForProject($project);
    }

    private function applyPlanningKeyword(SeoProjectTask $task, string $keyword): void
    {
        if ($keyword === '') {
            return;
        }

        $task->keyword = $keyword;
        // Keep Create identity / list source aligned with Content Project Edit Keyword.
        if (SeoProjectTask::isNewArticleType($task->type) || trim((string) ($task->source_content ?? '')) === '') {
            $task->source_content = $keyword;
        }
    }

    private function applyPlanningDescription(SeoProjectTask $task, string $description): void
    {
        $value = $description !== '' ? $description : null;
        // Create planning brief lives on secondary_description (Project Edit → Description).
        // Product gallery brief stays on description (Project Edit → Gallery description).
        if (SeoProjectTask::isNewArticleType($task->type)) {
            $task->secondary_description = $value;

            return;
        }

        $task->description = $value;
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

        $existing = app(PlanningDraftResolver::class)->findPlanningDraftForSite($siteId);
        if ($existing instanceof SeoProject) {
            $this->redirect(static::getUrl([
                'project' => (int) $existing->getKey(),
                'site' => $siteId,
            ]));

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

        $reused = (bool) ($result->metadata['reused_existing_draft'] ?? false);
        Notification::make()
            ->title($reused
                ? __('seo-content-ai::filament.projects.suggestions_draft_reused')
                : __('seo-content-ai::filament.projects.suggestions_draft_created'))
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

    /**
     * Unreviewed count among currently selected Draft rows (warning only).
     */
    public function selectedUnreviewedCount(): int
    {
        $selected = array_flip($this->normalizeSelectedIds($this->selectedTaskIds));
        if ($selected === []) {
            return 0;
        }

        $count = 0;
        foreach ($this->draftPlanningItems as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($selected[$id]) && empty($row['planning_reviewed'])) {
                $count++;
            }
        }

        return $count;
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

    private function autoSelectSiteDraftIfNeeded(): void
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0) {
            return;
        }

        $draft = app(PlanningDraftResolver::class)->findPlanningDraftForSite($siteId);
        if (! $draft instanceof SeoProject) {
            return;
        }

        if ($this->projectId === null || $this->projectId <= 0 || (int) $this->projectId !== (int) $draft->getKey()) {
            $this->projectId = (int) $draft->getKey();
            $this->resolveSelectedProject();
        }
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

        if (! $project->isDraftPlanning()) {
            // Prefer canonical planning draft for the site instead of execution project.
            $siteId = (int) ($project->site_id ?? 0);
            $canonical = $siteId > 0
                ? app(PlanningDraftResolver::class)->findPlanningDraftForSite($siteId)
                : null;
            if ($canonical instanceof SeoProject) {
                $this->projectId = (int) $canonical->getKey();
                $this->project = $canonical;
                $this->filterSiteId = $siteId ?: $this->filterSiteId;

                return;
            }
            $this->projectId = null;

            return;
        }

        $this->project = $project;

        if ($this->filterSiteId === null || $this->filterSiteId <= 0) {
            $this->filterSiteId = (int) ($project->site_id ?? 0) ?: null;
        }
    }

    private function shouldRedirectLegacyAdvancedParam(): bool
    {
        $advanced = request()->query('advanced');

        return in_array(strtolower(trim((string) $advanced)), ['1', 'true'], true);
    }

    /**
     * @return array<string, int|string>
     */
    private function canonicalPlannerQueryParams(): array
    {
        $params = [];

        $projectId = (int) ($this->projectId ?? 0);
        if ($projectId > 0) {
            $params['project'] = $projectId;
        }

        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId > 0) {
            $params['site'] = $siteId;
        }

        $domain = trim((string) request()->query('domain', ''));
        if ($domain !== '') {
            $params['domain'] = $domain;
        }

        return $params;
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchDraftArchive(array $taskIds): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $ids = $this->normalizeSelectedIds($taskIds);
        if ($ids === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.queue_select_required'))
                ->warning()
                ->send();

            return;
        }

        $project = $this->requireProject();

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                new ArchiveProjectItemsCommand(
                    (int) $project->getKey(),
                    $ids,
                ),
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($project->site_id ?? 0) ?: null,
                ),
            );

            Notification::make()
                ->title($result->success
                    ? __('seo-content-ai::filament.projects.archive_item_completed')
                    : __('seo-content-ai::filament.projects.archive_failed'))
                ->body($result->success
                    ? __('seo-content-ai::filament.projects.archive_item_completed_body', [
                        'archived' => (int) ($result->metadata['affected_count'] ?? count($ids)),
                    ])
                    : $result->message)
                ->{$result->success ? 'success' : 'danger'}()
                ->send();

            if ($result->success) {
                $archived = array_flip($ids);
                $this->selectedTaskIds = array_values(array_filter(
                    $this->normalizeSelectedIds($this->selectedTaskIds),
                    static fn (int $id): bool => ! isset($archived[$id]),
                ));

                return;
            }

            throw new Halt;
        } catch (Halt $e) {
            throw $e;
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.content_planning.archive',
                'project_id' => (int) $project->getKey(),
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }
    }
}
