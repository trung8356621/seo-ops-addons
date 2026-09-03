<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithAuditNotes;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithIdeaCandidates;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithPlannerPlanClone;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithSeoAuditSuggestions;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\Seo\Filament\Concerns\HidesFilamentPageHeader;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Throwable;

/**
 * Canonical Project Planner page (UI label: Project Planner).
 * Route slug kept as content-projects/seo-audit for deep-link compatibility.
 */
final class ContentProjectSeoAuditPlanner extends SeoPanelPage
{
    use HidesFilamentPageHeader;
    use InteractsWithAuditNotes;
    use InteractsWithDraftSplit;
    use InteractsWithIdeaCandidates;
    use InteractsWithNewContentSuggestions;
    use InteractsWithPlannerPlanClone;
    use InteractsWithSeoAuditSuggestions;
    use WithPagination;

    protected static ?string $slug = 'content-projects/seo-audit';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_PROJECTS + 2;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-seo-audit-planner';

    #[Url(as: 'project')]
    public ?int $projectId = null;

    /** Working Site mirror of Global Domain Context — not a separate URL SSOT. */
    public ?int $filterSiteId = null;

    /**
     * Draft list Domain filter (bottom table) — independent of Working Site (?site=).
     * Allowed: all | 0 (no domain) | {accessible site id}.
     */
    #[Url(as: 'draft_domain', except: 'all')]
    public string $draftDomainFilter = 'all';

    public ?SeoProject $project = null;

    /** @var list<int> */
    public array $selectedTaskIds = [];

    /** all|unreviewed|reviewed */
    public string $draftReviewFilter = 'all';

    /** all|rewrite|improve|create */
    public string $draftTypeFilter = 'all';

    /**
     * Bumped on cp-ops-refresh so Alpine Draft snapshot remounts with fresh read-model rows.
     */
    public int $draftPlanningRefreshNonce = 0;

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.projects.content_planning_nav_label');
    }

    public static function getNavigationParentItem(): ?string
    {
        return \Omnichannel\Addons\Seo\Support\SeoUserNavigation::moduleProjects();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageContentProjectWorkflow();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $this->draftDomainFilter = $this->normalizeDraftDomainFilter($this->draftDomainFilter);

        $this->migrateLegacyPlannerSiteQuery();
        $this->ensureConcreteGlobalWorkingSite();

        if ($this->shouldRedirectLegacyAdvancedParam()) {
            $this->redirect(static::getUrl($this->canonicalPlannerQueryParams()), navigate: false);

            return;
        }

        if ($this->shouldCanonicalizePlannerUrl()) {
            $this->redirect(static::getUrl($this->canonicalPlannerQueryParams()), navigate: false);

            return;
        }

        $this->workspaceTab = 'suggestions';
        $this->resolveSelectedProject();
        $this->autoSelectSharedDraftIfNeeded();
        $this->applyWorkingSiteContext(null, remount: true);
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $resolved = is_numeric($siteId) && (int) $siteId > 0
            ? (int) $siteId
            : SeoAccessControl::globalSiteId();

        $this->ensureConcreteGlobalWorkingSite();
        $this->applyWorkingSiteContext($resolved, remount: true);
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
        $this->mountInteractsWithAuditNotes();
        $this->mountInteractsWithIdeaCandidates();
        $this->mountInteractsWithDraftSplit();
    }

    public function updatedFilterSiteId(): void
    {
        $this->applyWorkingSiteContext(null, remount: true);
    }

    private function applyWorkingSiteContext(?int $siteId, bool $remount = false): void
    {
        $resolved = $siteId ?? SeoAccessControl::globalSiteId();
        $this->filterSiteId = ($resolved !== null && $resolved > 0) ? $resolved : null;

        // Working site is data context only — Shared Draft id must stay the same.
        // Draft Domain filter stays independent unless the referenced site becomes inaccessible.
        $this->draftDomainFilter = $this->normalizeDraftDomainFilter($this->draftDomainFilter);

        $this->selectedTaskIds = [];
        $this->clearSuggestionSelection();
        $this->resetPage('suggestionsPage');

        if (! $this->project instanceof SeoProject || ! $this->project->isDraftPlanning()) {
            $this->autoSelectSharedDraftIfNeeded();
        }

        if ($remount) {
            $this->mountInteractsWithSeoAuditSuggestions();
            $this->mountInteractsWithNewContentSuggestions();
            $this->mountInteractsWithAuditNotes();
            $this->mountInteractsWithIdeaCandidates();
            $this->mountInteractsWithDraftSplit();
        }
    }

    private function migrateLegacyPlannerSiteQuery(): void
    {
        $legacySite = (int) request()->query('site', 0);
        if ($legacySite <= 0 || ! SeoAccessControl::canAccessSite($legacySite)) {
            return;
        }

        if (app(DomainContextResolver::class)->hasExplicitRequestKey()) {
            return;
        }

        SeoAccessControl::setGlobalSiteId($legacySite);
    }

    private function ensureConcreteGlobalWorkingSite(): void
    {
        $siteId = SeoAccessControl::globalSiteId();
        if ($siteId !== null && $siteId > 0) {
            return;
        }

        $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->first();
        if ($first instanceof Site) {
            SeoAccessControl::setGlobalSiteId((int) $first->getKey());
        }
    }

    private function shouldCanonicalizePlannerUrl(): bool
    {
        if (request()->has('site')) {
            return true;
        }

        $globalSiteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
        if ($globalSiteId <= 0) {
            return false;
        }

        return ! request()->has(DomainContext::SITE_ID_QUERY_KEY)
            && ! request()->has(DomainContext::QUERY_KEY);
    }

    /**
     * Persist Draft list Domain filter to URL and remount the Alpine projection
     * with domain-scoped rows + counts from the read model.
     */
    public function setDraftDomainFilter(string $value): void
    {
        $this->draftDomainFilter = $this->normalizeDraftDomainFilter($value);
        $this->selectedTaskIds = [];
        $this->draftPlanningRefreshNonce++;
    }

    /**
     * @return 'all'|'0'|string concrete accessible site id as string
     */
    private function normalizeDraftDomainFilter(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || $normalized === 'all') {
            return 'all';
        }

        if ($normalized === '0') {
            return '0';
        }

        if (! ctype_digit($normalized)) {
            return 'all';
        }

        $siteId = (int) $normalized;
        if ($siteId <= 0) {
            return 'all';
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return 'all';
        }

        return (string) $siteId;
    }

    /**
     * @return array{id: int, name: string, site_id: int|null, domain: string, item_count: int}|null
     */
    public function getCanonicalPlanningDraftProperty(): ?array
    {
        $draft = $this->project instanceof SeoProject && $this->project->isDraftPlanning()
            ? $this->project
            : app(PlanningDraftResolver::class)->findCanonicalSharedDraft();

        if (! $draft instanceof SeoProject) {
            return null;
        }

        return [
            'id' => (int) $draft->getKey(),
            'name' => (string) $draft->name,
            'site_id' => $draft->site_id !== null ? (int) $draft->site_id : null,
            'domain' => '',
            'item_count' => method_exists($draft, 'registeredTaskCount')
                ? (int) $draft->registeredTaskCount()
                : (int) ($draft->total_tasks ?? 0),
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

    public function getDraftSupportsProductProperty(): bool
    {
        $site = $this->resolvePlanningSite();
        if ($site instanceof Site) {
            return $this->newContentSiteSupportsProductForSite($site);
        }

        if (! $this->project instanceof SeoProject) {
            return false;
        }

        return $this->newContentSiteSupportsProduct($this->project);
    }

    /**
     * Canonical working Site for Project Planner (filterSiteId).
     * Shared Draft remains domain-neutral — this is explicit context, not project.site_id.
     */
    public function resolvePlanningSite(): ?Site
    {
        $siteId = (int) (SeoAccessControl::globalSiteId() ?? $this->filterSiteId ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return null;
        }

        $site = Site::query()->find($siteId);

        return $site instanceof Site ? $site : null;
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

        app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\DraftItemDomainRepairService::class)
            ->repairProject($this->project, persist: true);

        return app(ContentProjectDraftPlanningItemsReadModel::class)->forProject($this->project, [
            'review' => $this->draftReviewFilter,
            'type' => $this->draftTypeFilter,
            'domain' => $this->draftDomainFilter,
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
            if ((int) ($task->site_id ?? 0) <= 0) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.planning_domain_required_before_review'))
                    ->danger()
                    ->send();

                throw new Halt;
            }
            $task->planning_reviewed_at = now();
            $task->planning_reviewed_by = auth()->id() !== null ? (int) auth()->id() : null;
        } else {
            $task->planning_reviewed_at = null;
            $task->planning_reviewed_by = null;
        }
        $task->save();
    }

    /**
     * One-click Clone idea (Create plan only). Returns payload for Alpine row insert.
     *
     * @return array<string, mixed>
     */
    public function cloneDraftIdea(int $taskId): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $project = $this->requireProject();

        try {
            $result = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\CloneDraftCreateIdeaService::class)
                ->clone(
                    $project,
                    $taskId,
                    auth()->id() !== null ? (int) auth()->id() : null,
                );
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planning_clone_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        $cloneId = (int) ($result['clone_id'] ?? 0);
        $payload = app(ContentProjectDraftPlanningItemsReadModel::class)
            ->forProject($project->fresh() ?? $project, [
                'review' => 'all',
                'type' => 'all',
                'domain' => $this->draftDomainFilter,
            ]);

        $row = null;
        foreach ($payload['rows'] as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $cloneId) {
                $row = $candidate;
                break;
            }
        }

        // Alpine inserts the returned row locally only when it matches current domain projection.
        return [
            'clone_id' => $cloneId,
            'source_id' => (int) ($result['source_id'] ?? 0),
            'row' => $row,
            'counts' => $payload['counts'],
        ];
    }

    /**
     * Inline Draft metadata save (Title / Keyword / Description / Post type).
     * post_type goes through UpdateContentProjectItemCommand (strict CREATE-only).
     */
    public function updatePlanningField(int $taskId, string $field, string $value): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $field = strtolower(trim($field));
        if ($field === 'post_type') {
            $this->updateDraftPlanningItem($taskId, 'post_type', $value);

            return;
        }

        if (! in_array($field, ['title', 'keyword', 'description', 'site_id'], true)) {
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

        if ($field === 'site_id') {
            $siteId = (int) trim($value);
            if ($siteId > 0 && ! SeoAccessControl::canAccessSite($siteId)) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.planning_domain_save_failed'))
                    ->danger()
                    ->send();

                throw new Halt;
            }
            $task->site_id = $siteId > 0 ? $siteId : null;
            $task->save();

            return;
        }

        $trimmed = trim($value);

        match ($field) {
            'title' => $task->title = $trimmed !== '' ? $trimmed : null,
            'keyword' => $this->applyPlanningKeyword($task, $trimmed),
            'description' => $this->applyPlanningDescription($task, $trimmed),
        };

        $task->save();
    }

    /**
     * Command-bus Draft planning field update (post_type correction).
     */
    public function updateDraftPlanningItem(int $itemId, string $field, mixed $value): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $field = strtolower(trim($field));
        if ($field !== 'post_type') {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.planner_generate_failed'))
                ->body('Unsupported planning field.')
                ->danger()
                ->send();

            throw new Halt;
        }

        $project = $this->requireProject();
        $actor = ActorContext::user(
            auth()->id() !== null ? (int) auth()->id() : null,
            (int) ($project->site_id ?? 0) ?: null,
        );

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new UpdateContentProjectItemCommand($itemId, [
                'post_type' => is_scalar($value) || $value === null ? (string) $value : '',
            ]),
            $actor,
        );

        if (! $result->success) {
            Notification::make()
                ->title('Failed')
                ->body($result->message)
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    /**
     * Re-query Draft planning rows after SEO Audit / AI New Content mutations.
     */
    public function refreshDraftPlanningSnapshot(): void
    {
        $this->draftPlanningRefreshNonce++;
    }

    /**
     * Re-query Draft planning rows after AI New Content / SEO Audit mutations.
     * Alpine Draft table boots from a one-time snapshot — bump wire:key to remount.
     */
    #[On('cp-ops-refresh')]
    public function onCpOpsRefresh(): void
    {
        $this->refreshDraftPlanningSnapshot();
    }

    public function archiveOne(int $taskId): bool
    {
        return $this->dispatchDraftArchive([$taskId]);
    }

    public function skipSeoAuditOne(int $taskId): bool
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        try {
            $project = $this->requireProject();
        } catch (Halt) {
            return false;
        }

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

            return false;
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

            return false;
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

        return $archiveResult->success;
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

    /**
     * @deprecated Per-site Create Draft removed — Shared Draft is system-managed via ensureSharedDraft().
     */
    public function createDraftForPlanner(): void
    {
        $this->autoSelectSharedDraftIfNeeded(showErrors: true);
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

    private function autoSelectSharedDraftIfNeeded(bool $showErrors = false): void
    {
        $resolver = app(PlanningDraftResolver::class);
        $draft = $resolver->findCanonicalSharedDraft();

        if (! $draft instanceof SeoProject) {
            $bootstrap = (int) ($this->filterSiteId ?? 0);
            if ($bootstrap <= 0) {
                $bootstrap = (int) (array_key_first($this->siteFilterOptions) ?? 0);
            }

            if ($bootstrap <= 0) {
                if ($showErrors) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.seo_audit_draft_site_required'))
                        ->warning()
                        ->send();
                }

                return;
            }

            try {
                $draft = app(PlanningDraftIntakeService::class)->ensureSharedDraft(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    $bootstrap,
                );
            } catch (Throwable $e) {
                RuntimeLogger::report($e, [
                    'endpoint' => 'content_project.content_planning.ensure_shared_draft',
                    'bootstrap_site_id' => $bootstrap,
                ]);
                if ($showErrors) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.projects.suggestions_create_draft_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }

                return;
            }
        }

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

        // Prefer unscoped model lookup — Shared Draft has site_id NULL and must not fail canAccessSite(0).
        $project = SeoProject::query()
            ->with('site:id,domain')
            ->find($this->projectId);

        if (! $project instanceof SeoProject) {
            $this->projectId = null;

            return;
        }

        $projectSiteId = (int) ($project->site_id ?? 0);
        $isSharedDraft = $project->isDraftPlanning() && $projectSiteId <= 0;
        if (! $isSharedDraft && $projectSiteId > 0 && ! SeoAccessControl::canAccessSite($projectSiteId)) {
            $this->projectId = null;

            return;
        }

        if (! $project->isDraftPlanning()) {
            $canonical = app(PlanningDraftResolver::class)->findCanonicalSharedDraft();
            if ($canonical instanceof SeoProject) {
                $this->projectId = (int) $canonical->getKey();
                $this->project = $canonical;

                return;
            }
            $this->projectId = null;

            return;
        }

        $this->project = $project;
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

        $siteId = (int) (SeoAccessControl::globalSiteId() ?? $this->filterSiteId ?? 0);
        if ($siteId > 0) {
            $params[DomainContext::SITE_ID_QUERY_KEY] = $siteId;
        }

        $draftDomain = $this->normalizeDraftDomainFilter($this->draftDomainFilter);
        if ($draftDomain !== 'all') {
            $params['draft_domain'] = $draftDomain;
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
    private function dispatchDraftArchive(array $taskIds): bool
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $ids = $this->normalizeSelectedIds($taskIds);
        if ($ids === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.queue_select_required'))
                ->warning()
                ->send();

            return false;
        }

        try {
            $project = $this->requireProject();
        } catch (Halt) {
            return false;
        }

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
                    ? ($project->isDraftPlanning()
                        ? __('seo-content-ai::filament.projects.draft_remove_completed')
                        : __('seo-content-ai::filament.projects.archive_item_completed'))
                    : ($project->isDraftPlanning()
                        ? __('seo-content-ai::filament.projects.draft_remove_failed')
                        : __('seo-content-ai::filament.projects.archive_failed')))
                ->body($result->success
                    ? ($project->isDraftPlanning()
                        ? __('seo-content-ai::filament.projects.draft_remove_completed_body', [
                            'archived' => (int) ($result->metadata['affected_count'] ?? count($ids)),
                        ])
                        : __('seo-content-ai::filament.projects.archive_item_completed_body', [
                            'archived' => (int) ($result->metadata['affected_count'] ?? count($ids)),
                        ]))
                    : $result->message)
                ->{$result->success ? 'success' : 'danger'}()
                ->send();

            if (! $result->success) {
                return false;
            }

            $archived = array_flip($ids);
            $this->selectedTaskIds = array_values(array_filter(
                $this->normalizeSelectedIds($this->selectedTaskIds),
                static fn (int $id): bool => ! isset($archived[$id]),
            ));

            return true;
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.content_planning.archive',
                'project_id' => (int) $project->getKey(),
            ]);
            Notification::make()
                ->title($project->isDraftPlanning()
                    ? __('seo-content-ai::filament.projects.draft_remove_failed')
                    : __('seo-content-ai::filament.projects.archive_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }
}
