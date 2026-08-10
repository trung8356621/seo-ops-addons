<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AcknowledgeProjectItemGenerationErrorCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BlockProjectItemGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\DebugOverrideProjectItemLifecycleCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnblockProjectItemGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SelectExistingArticleForProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceDeepLink;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDebugLifecycleOverrideService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationReadStateStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationLaunchPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticlePickerService;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectInReviewReportingDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsCounterTransitionMap;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\WithPagination;
use Throwable;

/**
 * Canonical project workspace — one items table for generation/review/publishing.
 */
final class ViewSeoProject extends Page
{
    use InteractsWithContentProjectPublishingActions;
    use WithPagination;

    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-seo-project-operations';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record = 0;

    public ?SeoProject $project = null;

    /** @var list<int> */
    public array $selectedTaskIds = [];

    public bool $settingsOpen = false;

    public bool $executionDetailsOpen = false;

    public ?int $executionDetailsTaskId = null;

    public bool $selectExistingArticleOpen = false;

    public ?int $selectExistingArticleTaskId = null;

    public string $selectExistingArticleQuery = '';

    public string $selectExistingArticleDirect = '';

    public bool $selectExistingArticleLoading = false;

    public string $selectExistingArticleError = '';

    /** @var list<array<string, mixed>> */
    public array $selectExistingArticleResults = [];

    public string $search = '';

    public string $typeFilter = '';

    public string $generationFilter = '';

    public string $lifecycleFilter = '';

    /** Unified workflow summary filter (All / Draft / Pending / … / Failed). */
    public string $workflowFilter = '';

    public string $reportingFilter = '';

    public string $queueFilter = '';

    public string $scheduledFilter = '';

    public bool $failedOnly = false;

    /** Failed quick filter: prompt|model|queue|timeout|validation|wordpress|other|''. */
    public string $failureTypeFilter = '';

    /** Livewire public — must live on component, not trait. */
    public bool $bulkRunning = false;

    /** @var list<int> */
    public array $pendingTaskIds = [];

    public ?string $pendingOp = null;

    public ?string $pendingPhase = null;

    public ?string $pendingOperationId = null;

    public string $autoMode = 'interval';

    public string $autoStartAt = '';

    public int $autoIntervalMinutes = 15;

    public int $autoPerDay = 3;

    public string $autoDayStart = '09:00';

    public string $autoDayEnd = '17:00';

    /**
     * Bumps when ops table must reload (summary changed / manual refresh).
     * Filter/page changes already alter cache key.
     */
    public int $opsTableEpoch = 0;

    /** @var array{total_items:int,draft:int,pending:int,needs_review:int,failed:int,review:int,approved:int,scheduled:int,published:int,running:int}|null */
    public ?array $summarySnapshot = null;

    /** Client dirty hint — Editor save sets via event; lazy refresh clears. */
    public bool $opsNeedsRefresh = false;

    /** Request-local only — never public (payload has LengthAwarePaginator). */
    protected ?array $cachedOperationsPayload = null;

    protected string $cachedOperationsKey = '';

    /**
     * Presentation-only: hide rows from current ops list after command accept
     * until filter change / hard refresh. Survives remorph (unlike Alpine-only hide).
     *
     * @var array<int, true>
     */
    public array $optimisticHiddenTaskIds = [];

    /** @var array<string, mixed> */
    protected $queryString = [
        'search' => ['except' => ''],
        'typeFilter' => ['except' => '', 'as' => 'type'],
        'generationFilter' => ['except' => '', 'as' => 'generation'],
        'lifecycleFilter' => ['except' => '', 'as' => 'lifecycle'],
        'workflowFilter' => ['except' => '', 'as' => 'workflow'],
        'reportingFilter' => ['except' => '', 'as' => 'reporting'],
        'queueFilter' => ['except' => '', 'as' => 'queue'],
        'scheduledFilter' => ['except' => '', 'as' => 'scheduled'],
        'failedOnly' => ['except' => false, 'as' => 'failed'],
        'failureTypeFilter' => ['except' => '', 'as' => 'failure_type'],
    ];

    public function mount(int|string $record): void
    {
        $this->record = $record;
        $this->project = $this->resolveProject($record);
        abort_unless(SeoProjectResource::canView($this->project), 403);

        if ($this->project->isArchive() || $this->project->isProjectArchived()) {
            $this->redirect(SeoProjectResource::getUrl('index'));
        }

        if ($this->autoStartAt === '') {
            $this->autoStartAt = now()->addHour()->format('Y-m-d\TH:i');
        }

        // Content Manager lands on Needs Review (assigned edit queue).
        if (
            $this->generationFilter === ''
            && $this->lifecycleFilter === ''
            && $this->workflowFilter === ''
            && $this->reportingFilter === ''
            && ! $this->failedOnly
            && ! SeoAccessControl::canManageContentProjectWorkflow()
            && SeoAccessControl::canSubmitArticleReview()
        ) {
            $this->workflowFilter = ContentProjectRecentlyCompletedDefinition::FILTER;
            $this->reportingFilter = ContentProjectRecentlyCompletedDefinition::FILTER;
        }
    }

    public function getTitle(): string|Htmlable
    {
        return (string) ($this->project?->name ?? __('seo-content-ai::filament.projects.navigation'));
    }

    public function getHeading(): string|Htmlable
    {
        $name = (string) ($this->project?->name ?? __('seo-content-ai::filament.projects.navigation'));
        $total = $this->resolveTotalItemsCount();
        $badge = __('seo-content-ai::filament.projects.ops_total_badge', ['count' => $total]);

        $working = (int) ($this->summarySnapshot['working_set'] ?? 0);
        $queue = (int) ($this->summarySnapshot['publishing_queue'] ?? 0);
        $subtitle = __('seo-content-ai::filament.projects.ops_counts_subtitle', [
            'working' => $working,
            'queue' => $queue,
        ]);

        return new HtmlString(
            '<span class="inline-flex flex-wrap items-center gap-2">'
            .'<span>'.e($name).'</span>'
            .'<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-400/30">'
            .e(is_string($badge) ? $badge : $total.' Items')
            .'</span>'
            .'<span class="text-xs font-normal text-gray-500 dark:text-gray-400">'
            .e(is_string($subtitle) ? $subtitle : '')
            .'</span>'
            .'</span>'
        );
    }

    private function resolveTotalItemsCount(): int
    {
        if (! is_array($this->summarySnapshot)) {
            try {
                $this->summarySnapshot = $this->fetchOpsSummary();
            } catch (Throwable) {
                return 0;
            }
        }

        return (int) ($this->summarySnapshot['total_items'] ?? 0);
    }

    private function resolvedWorkflowFilter(): string
    {
        if ($this->workflowFilter !== '') {
            return $this->workflowFilter;
        }

        return $this->lifecycleFilter;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $project = $this->project;
        if (! $project instanceof SeoProject) {
            return null;
        }

        return implode(' · ', [
            (string) ($project->site?->domain ?? '—'),
            (string) ($project->user?->name ?? '—'),
            (string) ($project->month?->format('m/Y') ?? '—'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOperationsPayloadProperty(): array
    {
        $cacheKey = $this->buildOpsCacheKey();
        if ($this->cachedOperationsPayload !== null && $this->cachedOperationsKey === $cacheKey) {
            return $this->applyOptimisticRowHides($this->cachedOperationsPayload);
        }

        $payload = app(ContentProjectItemOperationsReadModel::class)->forProject(
            $this->requireProject(),
            [
                'search' => $this->search,
                'type' => $this->typeFilter,
                'generation' => $this->generationFilter,
                'lifecycle' => $this->lifecycleFilter,
                'workflow' => $this->resolvedWorkflowFilter(),
                'reporting' => $this->reportingFilter,
                'queue' => $this->queueFilter,
                'scheduled' => $this->scheduledFilter,
                'failed_only' => $this->failedOnly,
                'failure_type' => $this->failureTypeFilter,
                'page' => $this->getPage(),
                'viewer_user_id' => (int) (auth()->id() ?? 0),
            ],
        );

        $this->cachedOperationsPayload = $payload;
        $this->cachedOperationsKey = $cacheKey;

        return $this->applyOptimisticRowHides($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyOptimisticRowHides(array $payload): array
    {
        if ($this->optimisticHiddenTaskIds === []) {
            return $payload;
        }

        $hidden = $this->optimisticHiddenTaskIds;
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $payload['rows'] = array_values(array_filter(
            $rows,
            static function (mixed $row) use ($hidden): bool {
                if (! is_array($row)) {
                    return false;
                }
                $tid = (int) ($row['task_id'] ?? 0);

                return $tid <= 0 || ! isset($hidden[$tid]);
            },
        ));

        return $payload;
    }

    public function persistOptimisticRemovals(array $taskIds): void
    {
        foreach ($taskIds as $id) {
            $tid = (int) $id;
            if ($tid > 0) {
                $this->optimisticHiddenTaskIds[$tid] = true;
            }
        }
        $this->cachedOperationsPayload = null;
        $this->cachedOperationsKey = '';
        $this->opsTableEpoch++;
    }

    public function clearOptimisticRemovals(array $taskIds = []): void
    {
        if ($taskIds === []) {
            $this->optimisticHiddenTaskIds = [];
        } else {
            foreach ($taskIds as $id) {
                unset($this->optimisticHiddenTaskIds[(int) $id]);
            }
        }
        $this->cachedOperationsPayload = null;
        $this->cachedOperationsKey = '';
        $this->opsTableEpoch++;
    }

    /**
     * @return array{pending:int,needs_review:int,failed:int,review:int,approved:int,scheduled:int,published:int,running:int}
     */
    public function fetchOpsSummary(): array
    {
        return app(ContentProjectItemOperationsReadModel::class)->summaryForProject(
            $this->requireProject(),
            (int) (auth()->id() ?? 0),
        );
    }

    /**
     * Lazy refresh: summary-only; reload table only when summary differs.
     *
     * @return array{changed: bool, summary: array<string, int>}
     */
    public function lazyRefreshOps(): array
    {
        $summary = $this->fetchOpsSummary();
        $previous = $this->summarySnapshot
            ?? ContentProjectItemOperationsReadModel::normalizeSummaryStats(
                is_array($this->cachedOperationsPayload['stats'] ?? null)
                    ? $this->cachedOperationsPayload['stats']
                    : [],
            );
        $changed = $this->summaryFingerprint($summary) !== $this->summaryFingerprint($previous);
        $this->summarySnapshot = $summary;
        $this->opsNeedsRefresh = false;

        if (! $changed) {
            $this->skipRender();

            return ['changed' => false, 'summary' => $summary];
        }

        $this->invalidateOpsCache();

        return ['changed' => true, 'summary' => $summary];
    }

    public function manualRefreshOps(): array
    {
        $this->opsNeedsRefresh = false;
        $this->invalidateOpsCache();
        $this->summarySnapshot = $this->fetchOpsSummary();

        return ['changed' => true, 'summary' => $this->summarySnapshot];
    }

    public function markOpsNeedsRefresh(): void
    {
        $this->opsNeedsRefresh = true;
        $this->skipRender();
    }

    private function invalidateOpsCache(): void
    {
        $this->opsTableEpoch++;
        $this->cachedOperationsPayload = null;
        $this->cachedOperationsKey = '';
    }

    private function buildOpsCacheKey(): string
    {
        return hash('xxh128', (string) json_encode([
            'epoch' => $this->opsTableEpoch,
            'record' => (string) $this->record,
            'page' => $this->getPage(),
            'search' => $this->search,
            'type' => $this->typeFilter,
            'generation' => $this->generationFilter,
            'lifecycle' => $this->lifecycleFilter,
            'workflow' => $this->resolvedWorkflowFilter(),
            'reporting' => $this->reportingFilter,
            'queue' => $this->queueFilter,
            'scheduled' => $this->scheduledFilter,
            'failed' => $this->failedOnly,
            'failure_type' => $this->failureTypeFilter,
            'viewer' => (int) (auth()->id() ?? 0),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function summaryFingerprint(array $summary): string
    {
        $normalized = ContentProjectItemOperationsReadModel::normalizeSummaryStats($summary);

        return hash('xxh128', (string) json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function resetClientOptimisticHints(): void
    {
        $this->optimisticHiddenTaskIds = [];
        $this->dispatch('cp-ops-client-reset-optimistic');
    }

    public function getActiveSummaryCardProperty(): string
    {
        if ($this->failedOnly || $this->resolvedWorkflowFilter() === 'failed') {
            return 'failed';
        }

        $workflow = $this->resolvedWorkflowFilter();
        if ($workflow !== '') {
            return match ($workflow) {
                'normal', 'draft' => 'normal',
                'pending' => 'pending',
                ContentProjectRecentlyCompletedDefinition::FILTER, 'needs_review' => 'recently_completed',
                ContentProjectInReviewReportingDefinition::FILTER, 'review', 'in_review' => 'review',
                'approved' => 'approved',
                'waiting_publish', 'scheduled' => 'scheduled',
                'published' => 'published',
                default => '',
            };
        }

        if ($this->generationFilter === 'pending') {
            return 'pending';
        }
        if ($this->generationFilter === ContentProjectRecentlyCompletedDefinition::FILTER
            || $this->reportingFilter === ContentProjectRecentlyCompletedDefinition::FILTER
        ) {
            return 'recently_completed';
        }
        if ($this->generationFilter === ContentProjectInReviewReportingDefinition::FILTER
            || $this->reportingFilter === ContentProjectInReviewReportingDefinition::FILTER
            || $this->reportingFilter === 'review') {
            return 'review';
        }

        // No filter active at all — Normal (full working set) is the implicit default view.
        if (! $this->hasActiveFilters) {
            return 'normal';
        }

        return '';
    }

    public function getRunningCountProperty(): int
    {
        return (int) (($this->operationsPayload['stats']['running'] ?? 0));
    }

    public function getHasActiveFiltersProperty(): bool
    {
        return $this->search !== ''
            || $this->typeFilter !== ''
            || $this->generationFilter !== ''
            || $this->lifecycleFilter !== ''
            || $this->workflowFilter !== ''
            || $this->reportingFilter !== ''
            || $this->queueFilter !== ''
            || $this->scheduledFilter !== ''
            || $this->failedOnly
            || $this->failureTypeFilter !== '';
    }

    protected function getHeaderActions(): array
    {
        $project = $this->requireProject();

        return [
            Actions\Action::make('open_in_agent')
                ->label(__('seo-content-ai::filament.agent_workspace.open_workspace'))
                ->icon('heroicon-o-cpu-chip')
                ->color('gray')
                ->url(fn (): string => AgentWorkspaceDeepLink::url([
                    'project_ref' => ContentProjectPublicRef::project((int) $project->getKey()),
                ])),
            Actions\Action::make('running_items_indicator')
                ->label(fn (): string => __('seo-content-ai::filament.projects.ops_running_items_indicator', [
                    'count' => $this->runningCount,
                ]))
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->disabled()
                ->extraAttributes([
                    'class' => 'pointer-events-none animate-pulse',
                ])
                ->visible(fn (): bool => $this->runningCount > 0),
            SeoProjectResource::makeGeneratePendingItemsAction($project),
            Actions\Action::make('publishing_queue')
                ->label(__('seo-content-ai::filament.projects.publishing_queue'))
                ->icon('heroicon-o-queue-list')
                ->color('primary')
                ->url(fn (): string => SeoProjectResource::getPublishingQueueUrl($project))
                ->visible(fn (): bool => SeoAccessControl::canManageContentProjectWorkflow()),
            Actions\Action::make('edit_project')
                ->label(__('seo-content-ai::filament.projects.edit_project'))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(fn (): string => SeoProjectResource::getUrl('edit', ['record' => $project])),
            Actions\Action::make('toggle_settings')
                ->label(__('seo-content-ai::filament.projects.project_settings_toggle'))
                ->icon('heroicon-o-information-circle')
                ->color('gray')
                ->action(fn () => $this->settingsOpen = ! $this->settingsOpen),
            Actions\ActionGroup::make([
                Actions\Action::make('archive_project')
                    ->label(__('seo-content-ai::filament.projects.archive_project'))
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => SeoAccessControl::canArchiveContentProjects())
                    ->disabled(function () use ($project): bool {
                        if ($project->isProjectArchived()) {
                            return true;
                        }

                        $gate = app(ArchiveContentProjectService::class)->archiveGate($project);

                        return ! (bool) ($gate['can_archive'] ?? false);
                    })
                    ->tooltip(function () use ($project): ?string {
                        if ($project->isProjectArchived()) {
                            return 'Project already archived.';
                        }

                        $gate = app(ArchiveContentProjectService::class)->archiveGate($project);

                        return (bool) ($gate['can_archive'] ?? false) ? null : (string) ($gate['blocked_reason'] ?? '');
                    })
                    ->modalHeading(fn (): string => __('seo-content-ai::filament.projects.archive_project_heading_named', [
                        'name' => (string) $project->name,
                    ]))
                    ->modalDescription(function () use ($project): HtmlString {
                        $summary = app(ArchiveContentProjectService::class)->buildSummary($project);
                        $gate = app(ArchiveContentProjectService::class)->archiveGate($project);

                        return new HtmlString(view('seo-content-ai::filament.resources.seo-project-resource.partials.archive-project-modal-summary', [
                            'summary' => $summary,
                            'gate' => $gate,
                        ])->render());
                    })
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.projects.archive_project_submit'))
                    ->form(function () use ($project): array {
                        $gate = app(ArchiveContentProjectService::class)->archiveGate($project);
                        $fields = [
                            Forms\Components\Textarea::make('note')
                                ->label(__('seo-content-ai::filament.projects.archive_note'))
                                ->placeholder(__('seo-content-ai::filament.projects.archive_note_placeholder'))
                                ->rows(2)
                                ->maxLength(500),
                        ];

                        if ($gate['requires_waiting_publish_confirm']) {
                            $fields[] = Forms\Components\Checkbox::make('confirm_waiting_publish')
                                ->label(__('seo-content-ai::filament.projects.archive_waiting_publish_confirm', [
                                    'count' => $gate['waiting_publish'],
                                ]))
                                ->rule('accepted')
                                ->required();
                        }

                        return $fields;
                    })
                    ->action(function (array $data) use ($project): void {
                        try {
                            abort_unless(SeoAccessControl::canArchiveContentProjects(), 403);
                            abort_unless(SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0)), 403);

                            $result = app(ContentProjectCommandBus::class)->dispatch(
                                new ArchiveContentProjectCommand(
                                    (int) $project->getKey(),
                                    isset($data['note']) ? (string) $data['note'] : null,
                                    (bool) ($data['confirm_waiting_publish'] ?? false),
                                ),
                                ActorContext::user(
                                    auth()->id() !== null ? (int) auth()->id() : null,
                                    (int) ($project->site_id ?? 0) ?: null,
                                ),
                            );

                            app(ContentProjectActionResultNotifier::class)->send($result);
                            $this->project = null;
                            $this->cachedOperationsPayload = null;
                            $this->cachedOperationsKey = '';
                            $this->opsTableEpoch++;
                        } catch (Throwable $exception) {
                            RuntimeLogger::report($exception, [
                                'endpoint' => 'content_project.archive',
                                'project_id' => (int) $project->getKey(),
                            ]);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                SeoProjectResource::makeDevTestGeneratePendingItemsAction($project),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button()
                ->visible(fn (): bool => (
                    SeoAccessControl::canArchiveContentProjects()
                ) || SeoProjectResource::allowsDevTestGenerateUi()),
        ];
    }

    public function applySummaryFilter(string $card): void
    {
        $this->resetPage();
        $this->clearFilters(false);
        $this->resetClientOptimisticHints();

        match ($card) {
            // Normal = full working set — clearFilters(false) above already reset
            // workflowFilter to '' so every working-set row shows. 'draft' kept as
            // legacy alias (same clears-filters behavior).
            'normal', 'draft' => null,
            'pending' => $this->workflowFilter = 'pending',
            'recently_completed' => $this->workflowFilter = ContentProjectRecentlyCompletedDefinition::FILTER,
            'failed' => $this->failedOnly = true,
            'review' => $this->workflowFilter = ContentProjectInReviewReportingDefinition::FILTER,
            'scheduled' => $this->workflowFilter = 'waiting_publish',
            'published' => $this->workflowFilter = 'published',
            default => null,
        };
    }

    public function applyFailureTypeFilter(string $type): void
    {
        $this->resetPage();
        $this->resetClientOptimisticHints();
        $this->failedOnly = true;
        $this->failureTypeFilter = strtolower(trim($type));
        $this->workflowFilter = '';
        $this->lifecycleFilter = '';
        $this->generationFilter = '';
        $this->reportingFilter = '';
    }

    public function clearFilters(bool $resetPage = true): void
    {
        $this->search = '';
        $this->typeFilter = '';
        $this->generationFilter = '';
        $this->lifecycleFilter = '';
        $this->workflowFilter = '';
        $this->reportingFilter = '';
        $this->queueFilter = '';
        $this->scheduledFilter = '';
        $this->failedOnly = false;
        $this->failureTypeFilter = '';
        $this->clearSelection();
        $this->resetClientOptimisticHints();
        if ($resetPage) {
            $this->resetPage();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedGenerationFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedLifecycleFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedWorkflowFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
        if ($this->workflowFilter === 'failed') {
            $this->failedOnly = true;
        }
        if ($this->workflowFilter !== '' && $this->workflowFilter !== 'failed') {
            $this->failedOnly = false;
            $this->failureTypeFilter = '';
        }
    }

    public function updatedReportingFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedFailureTypeFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
        if ($this->failureTypeFilter !== '') {
            $this->failedOnly = true;
        }
    }

    public function updatedQueueFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedScheduledFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedFailedOnly(): void
    {
        $this->resetPage();
        $this->clearSelection();
        $this->resetClientOptimisticHints();
    }

    public function updatedPage(): void
    {
        $this->resetClientOptimisticHints();
    }

    public function updatedSelectedTaskIds(): void
    {
        $this->selectedTaskIds = $this->normalizeSelectedIds($this->selectedTaskIds);
    }

    public function toggleSelect(int $taskId): void
    {
        $ids = $this->normalizeSelectedIds($this->selectedTaskIds);
        if (in_array($taskId, $ids, true)) {
            $this->selectedTaskIds = array_values(array_filter(
                $ids,
                static fn (int $id): bool => $id !== $taskId,
            ));

            return;
        }

        $ids[] = $taskId;
        $this->selectedTaskIds = $ids;
    }

    public function selectPage(): void
    {
        $ids = $this->normalizeSelectedIds($this->selectedTaskIds);
        foreach ($this->operationsPayload['rows'] ?? [] as $row) {
            $id = (int) ($row['task_id'] ?? 0);
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $this->selectedTaskIds = $ids;
    }

    public function clearSelection(): void
    {
        $this->selectedTaskIds = [];
    }

    public function getHasSelectionProperty(): bool
    {
        return count($this->normalizeSelectedIds($this->selectedTaskIds)) > 0;
    }

    public function getSelectedCountProperty(): int
    {
        return count($this->normalizeSelectedIds($this->selectedTaskIds));
    }

    /** @return list<int> */
    protected function selectedItemIds(): array
    {
        return $this->normalizeSelectedIds($this->selectedTaskIds);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function normalizeSelectedIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    public function archiveSelected(): void
    {
        $this->dispatchArchive($this->selectedItemIds());
    }

    public function archiveOne(int $taskId): void
    {
        $this->dispatchArchive([$taskId]);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchArchive(array $taskIds): void
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
        $this->bulkRunning = true;

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
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.items.archive',
                'project_id' => (int) $project->getKey(),
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->bulkRunning = false;
        }
    }

    public function openExecutionDetails(int $taskId): void
    {
        $this->markGenerationResultViewed($taskId);
        $this->executionDetailsTaskId = $taskId;
        $this->executionDetailsOpen = true;
    }

    public function markGenerationResultViewed(int $taskId): bool
    {
        $userId = (int) (auth()->id() ?? 0);
        $project = $this->requireProject();
        $projectId = (int) $project->getKey();
        if ($userId <= 0 || $taskId <= 0) {
            return false;
        }

        $completedAt = $this->resolveLatestSuccessfulGenerationCompletedAt($projectId, $taskId);
        if ($completedAt === null) {
            return false;
        }

        return app(ContentProjectGenerationReadStateStore::class)->markViewed(
            $userId,
            $projectId,
            $taskId,
            $completedAt,
        );
    }

    /**
     * Optimistic Needs Review open: mark viewed, return editor URL (no Livewire redirect).
     *
     * @return array{ok: bool, url?: string}
     */
    public function claimNeedsReviewItem(int $taskId, bool $expectNeedsReviewMark = false): array
    {
        $taskId = max(0, $taskId);
        if ($taskId <= 0) {
            return ['ok' => false];
        }

        $completedAt = $this->resolveLatestSuccessfulGenerationCompletedAt(
            (int) $this->requireProject()->getKey(),
            $taskId,
        );
        $needsMark = $expectNeedsReviewMark || $completedAt !== null;

        if ($needsMark) {
            $marked = $this->markGenerationResultViewed($taskId);
            if ($expectNeedsReviewMark && ! $marked) {
                $this->skipRender();

                return ['ok' => false];
            }
        }

        $projectId = (int) $this->requireProject()->getKey();
        $task = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereKey($taskId)
            ->first(['id', 'article_id']);
        $articleId = (int) ($task?->article_id ?? 0);
        if ($articleId <= 0) {
            $this->skipRender();

            return ['ok' => false];
        }

        $this->skipRender();

        return [
            'ok' => true,
            'url' => ArticleResource::getUrl('edit', ['record' => $articleId]),
        ];
    }

    /** @deprecated Use claimNeedsReviewItem — alias for older Alpine callers. */
    public function claimAiInboxItem(int $taskId, bool $expectInboxMark = false): array
    {
        return $this->claimNeedsReviewItem($taskId, $expectInboxMark);
    }

    public function openArticleEditor(int $taskId): void
    {
        $result = $this->claimNeedsReviewItem($taskId, expectNeedsReviewMark: false);
        $url = (string) ($result['url'] ?? '');
        if ($url === '') {
            return;
        }

        $this->redirect($url);
    }

    public function markAllRecentlyCompletedViewed(): void
    {
        $userId = (int) (auth()->id() ?? 0);
        $project = $this->requireProject();
        if ($userId <= 0) {
            return;
        }

        $unread = app(ContentProjectItemOperationsReadModel::class)
            ->unreadSuccessfulCompletions($project, $userId);
        $marked = app(ContentProjectGenerationReadStateStore::class)
            ->markManyViewed($userId, (int) $project->getKey(), $unread);

        $this->invalidateOpsCache();
        $this->resetClientOptimisticHints();

        if ($marked <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.ops_optimistic_update_failed'))
                ->danger()
                ->send();
        }
        // Success: no toast — motion / list refresh is the feedback.
    }

    public function notifyOptimisticFailure(?string $message = null): void
    {
        $this->skipRender();
        Notification::make()
            ->title($message ?: __('seo-content-ai::filament.projects.ops_optimistic_update_failed'))
            ->danger()
            ->send();
    }

    /**
     * Row-side finalize only — never reload summary / clear optimistic counters.
     * Command accepted ≠ canonical read-model reconciled.
     *
     * @deprecated Prefer persistOptimisticRemovals after client exit animation.
     */
    public function finalizeOpsAfterOptimistic(): void
    {
        $this->skipRender();
    }

    /**
     * Summary-only fetch for Alpine merge (no table remorph).
     *
     * @return array{summary: array<string, int>, request_id: int}
     */
    public function fetchOpsSummaryOnly(int $requestId = 0): array
    {
        $this->skipRender();
        $summary = $this->fetchOpsSummary();
        $this->summarySnapshot = $summary;

        // Drop hides for items that are no longer in the failed/needs_review sense
        // once pending grace will prune separately on client. Keep hides here.
        return [
            'summary' => $summary,
            'request_id' => max(0, $requestId),
        ];
    }

    private function resolveLatestSuccessfulGenerationCompletedAt(int $projectId, int $taskId): ?Carbon
    {
        $task = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereKey($taskId)
            ->first(['id']);
        if ($task === null) {
            return null;
        }

        $item = SeoProjectRunItem::query()
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->first(['id', 'status', 'finished_at']);
        if ($item === null || $item->finished_at === null) {
            return null;
        }

        $status = strtolower((string) ($item->status ?? ''));
        if (! in_array($status, ['success', 'completed'], true)) {
            return null;
        }

        return Carbon::parse($item->finished_at);
    }

    public function closeExecutionDetails(): void
    {
        $this->executionDetailsOpen = false;
        $this->executionDetailsTaskId = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExecutionDetailsRowsProperty(): array
    {
        if ($this->executionDetailsTaskId === null) {
            return [];
        }

        return SeoProjectRunItem::query()
            ->where('task_id', $this->executionDetailsTaskId)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'run_id', 'status', 'action', 'error_message', 'started_at', 'finished_at'])
            ->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'run_id' => (int) $item->run_id,
                'status' => (string) $item->status,
                'action' => (string) ($item->action ?? '—'),
                'error' => (string) ($item->error_message ?? ''),
                'started_at' => $item->started_at?->format('d/m/Y H:i'),
                'finished_at' => $item->finished_at?->format('d/m/Y H:i'),
            ])
            ->all();
    }

    public function generateSelected(): void
    {
        $this->dispatchGenerate($this->selectedTaskIds);
    }

    public function generateOne(int $taskId): void
    {
        $this->createOrRerunOne($taskId);
    }

    /**
     * ONE smart generation action: heal stale runtime then Generate or Rerun via CommandBus.
     */
    public function createOrRerunOne(int $taskId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey((int) $taskId)
            ->first();
        if (! $task instanceof SeoProjectTask) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body('Item not found.')
                ->danger()
                ->send();

            return;
        }

        $plan = app(ContentProjectItemGenerationLaunchPlanner::class)->plan($project, $task);
        if ($plan['action'] === ContentProjectItemGenerationLaunchPlanner::ACTION_BLOCKED_ACTIVE) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.item_action_generation_active'))
                ->warning()
                ->send();

            return;
        }

        if ($plan['action'] === ContentProjectItemGenerationLaunchPlanner::ACTION_BLOCKED_NONE) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body((string) ($plan['message'] ?? 'Generation action not executable.'))
                ->warning()
                ->send();

            return;
        }

        if ($plan['action'] === ContentProjectItemGenerationLaunchPlanner::ACTION_RERUN) {
            $this->dispatchBus(new RerunProjectItemsCommand(
                (int) $project->id,
                [(int) $taskId],
                SeoProjectRun::MODE_FULL,
            ));

            return;
        }

        $this->dispatchGenerate([(int) $taskId]);
    }

    public function rerunOne(int $taskId): void
    {
        // Compat: same smart path as row CTA (heal + generate/rerun).
        $this->createOrRerunOne($taskId);
    }

    /**
     * Primary Failed-row action: resume from first retryable failed step (reuse upstream artifacts).
     */
    public function resumeFromFailedStep(int $taskId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $this->dispatchBus(new ResumeProjectItemFromFailedStepCommand(
            (int) $project->id,
            [(int) $taskId],
            SeoProjectRun::MODE_FULL,
        ));
    }

    /**
     * Soft-clear stale generation Failed overlay — keep article content, no AI re-run.
     */
    public function acknowledgeGenerationError(int $taskId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $this->dispatchBus(new AcknowledgeProjectItemGenerationErrorCommand(
            (int) $project->id,
            [(int) $taskId],
        ));
        $this->resetPage();
    }

    public function openSelectExistingArticle(int $taskId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $this->selectExistingArticleOpen = true;
        $this->selectExistingArticleTaskId = (int) $taskId;
        $this->selectExistingArticleQuery = '';
        $this->selectExistingArticleDirect = '';
        $this->selectExistingArticleError = '';
        $this->selectExistingArticleLoading = true;
        $this->selectExistingArticleResults = [];
        $this->searchSelectExistingArticles();
    }

    public function closeSelectExistingArticle(): void
    {
        $this->selectExistingArticleOpen = false;
        $this->selectExistingArticleTaskId = null;
        $this->selectExistingArticleQuery = '';
        $this->selectExistingArticleDirect = '';
        $this->selectExistingArticleError = '';
        $this->selectExistingArticleLoading = false;
        $this->selectExistingArticleResults = [];
        $this->dispatch('close-select-existing-article');
    }

    public function searchSelectExistingArticles(): void
    {
        $project = $this->requireProject();
        $siteId = (int) ($project->site_id ?? 0);
        $this->selectExistingArticleLoading = true;
        $this->selectExistingArticleError = '';
        try {
            $this->selectExistingArticleResults = app(ContentProjectExistingArticlePickerService::class)
                ->search($siteId, $this->selectExistingArticleQuery, 12);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.select_existing_article_search']);
            $this->selectExistingArticleResults = [];
            $this->selectExistingArticleError = 'Search failed.';
        }
        $this->selectExistingArticleLoading = false;
    }

    public function updatedSelectExistingArticleQuery(): void
    {
        if (! $this->selectExistingArticleOpen || $this->selectExistingArticleTaskId === null) {
            return;
        }
        $this->searchSelectExistingArticles();
    }

    public function resolveSelectExistingArticleDirect(): void
    {
        $project = $this->requireProject();
        $siteId = (int) ($project->site_id ?? 0);
        $this->selectExistingArticleLoading = true;
        $this->selectExistingArticleError = '';
        try {
            $resolved = app(ContentProjectExistingArticlePickerService::class)
                ->resolveDirect($siteId, $this->selectExistingArticleDirect);
            if (! ($resolved['ok'] ?? false) || (int) ($resolved['article_id'] ?? 0) <= 0) {
                $this->selectExistingArticleError = match ((string) ($resolved['reason'] ?? '')) {
                    'ambiguous' => __('seo-content-ai::filament.projects.select_existing_article_ambiguous'),
                    'empty_input' => __('seo-content-ai::filament.projects.select_existing_article_direct_required'),
                    default => __('seo-content-ai::filament.projects.select_existing_article_not_found'),
                };
                $this->selectExistingArticleLoading = false;

                return;
            }
            $this->confirmSelectExistingArticle((int) $resolved['article_id']);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.select_existing_article_resolve']);
            $this->selectExistingArticleError = 'Resolve failed.';
            $this->selectExistingArticleLoading = false;
        }
    }

    public function confirmSelectExistingArticle(int $articleId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $taskId = (int) ($this->selectExistingArticleTaskId ?? 0);
        if ($taskId <= 0 || $articleId <= 0) {
            $this->selectExistingArticleError = __('seo-content-ai::filament.projects.select_existing_article_direct_required');

            return;
        }

        $this->selectExistingArticleLoading = true;
        $this->selectExistingArticleError = '';

        $result = app(ContentProjectCommandBus::class)->dispatch(
            new SelectExistingArticleForProjectItemCommand(
                (int) $project->id,
                $taskId,
                $articleId,
            ),
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($project->site_id ?? 0) ?: null,
            ),
        );

        if (! $result->success) {
            $this->selectExistingArticleError = (string) $result->message;
            $this->selectExistingArticleLoading = false;
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.select_existing_article_failed'))
                ->body((string) $result->message)
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.select_existing_article_success'))
            ->body((string) $result->message)
            ->success()
            ->send();

        $this->closeSelectExistingArticle();
        $this->invalidateOpsCache();
        $this->resetPage();
    }

    /**
     * Operator skip — durable exclude from Generate / Retry / resume selection.
     */
    public function skipGenerationOne(int $taskId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $this->dispatchBus(new BlockProjectItemGenerationCommand(
            (int) $project->id,
            [(int) $taskId],
            'operator_skip_generation',
        ));
        $this->resetPage();
    }

    /**
     * Clear generation block — allow Generate / Retry again.
     */
    public function allowGenerationOne(int $taskId): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $this->dispatchBus(new UnblockProjectItemGenerationCommand(
            (int) $project->id,
            [(int) $taskId],
        ));
        $this->resetPage();
    }

    public function bulkRegenOutline(): void
    {
        $this->dispatchBulkStep($this->selectedTaskIds, ContentProjectRerunFromStep::Outline->value);
    }

    public function bulkRegenArticle(): void
    {
        $this->dispatchBulkStep($this->selectedTaskIds, ContentProjectRerunFromStep::Article->value);
    }

    public function regenOutline(int $taskId): void
    {
        $this->dispatchBulkStep([$taskId], ContentProjectRerunFromStep::Outline->value);
    }

    public function regenArticle(int $taskId): void
    {
        $this->dispatchBulkStep([$taskId], ContentProjectRerunFromStep::Article->value);
    }

    public function startReviewSelected(): void
    {
        $this->dispatchBus(new StartReviewCommand((int) $this->requireProject()->id, $this->selectedTaskIds));
    }

    public function startReviewOne(int $taskId): void
    {
        $this->dispatchBus(new StartReviewCommand((int) $this->requireProject()->id, [$taskId]));
    }

    public function approveSelected(): void
    {
        abort_unless(SeoAccessControl::canApproveArticleReview(), 403);
        $this->dispatchBus(new ApproveProjectItemsCommand((int) $this->requireProject()->id, $this->selectedTaskIds));
    }

    public function approveOne(int $taskId): void
    {
        abort_unless(SeoAccessControl::canApproveArticleReview(), 403);
        $this->dispatchBus(new ApproveProjectItemsCommand((int) $this->requireProject()->id, [$taskId]));
    }

    /**
     * Feature-flagged debug/recovery — no WordPress side effects.
     */
    public function debugLifecycleOne(int $taskId, string $to, ?string $scheduledAt = null): void
    {
        abort_unless(SeoAccessControl::canDebugContentProjectLifecycle(), 403);

        $at = null;
        if (is_string($scheduledAt) && trim($scheduledAt) !== '') {
            try {
                $at = Carbon::parse($scheduledAt);
            } catch (Throwable) {
                Notification::make()
                    ->title('Failed')
                    ->body('Invalid schedule datetime.')
                    ->danger()
                    ->send();

                return;
            }
        }

        $this->dispatchBus(new DebugOverrideProjectItemLifecycleCommand(
            (int) $this->requireProject()->id,
            [$taskId],
            $to,
            $at,
            note: 'ui.debug_lifecycle_one',
        ));
    }

    public function debugLifecycleBulkToScheduled(?string $scheduledAt = null): void
    {
        abort_unless(SeoAccessControl::canDebugContentProjectLifecycle(), 403);

        $ids = $this->selectedItemIds();
        if ($ids === []) {
            Notification::make()
                ->title('Failed')
                ->body((string) __('seo-content-ai::filament.projects.queue_select_required'))
                ->danger()
                ->send();

            return;
        }

        $at = null;
        if (is_string($scheduledAt) && trim($scheduledAt) !== '') {
            try {
                $at = Carbon::parse($scheduledAt);
            } catch (Throwable) {
                Notification::make()
                    ->title('Failed')
                    ->body('Invalid schedule datetime.')
                    ->danger()
                    ->send();

                return;
            }
        }

        $this->dispatchBus(new DebugOverrideProjectItemLifecycleCommand(
            (int) $this->requireProject()->id,
            $ids,
            ContentProjectDebugLifecycleOverrideService::TO_SCHEDULED,
            $at,
            note: 'ui.debug_lifecycle_bulk_scheduled',
        ));
    }

    /**
     * @param  list<int>  $taskIds
     * @return array{valid: int, skipped: list<array{task_id: int, reason: string}>}
     */
    public function previewBulkGenerate(array $taskIds = []): array
    {
        $ids = $taskIds !== [] ? $taskIds : $this->selectedTaskIds;
        $preview = app(ContentProjectItemGenerationClassifier::class)->preview($this->requireProject());
        $allowed = array_flip($preview->runnableTaskIds());
        $valid = 0;
        $skipped = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($allowed[$id])) {
                $valid++;
                continue;
            }
            $reason = 'not_eligible';
            foreach ($preview->decisions as $d) {
                if ($d->taskId === $id) {
                    $reason = $d->reason;
                    break;
                }
            }
            $skipped[] = ['task_id' => $id, 'reason' => $reason];
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchGenerate(array $taskIds): void
    {
        $project = $this->requireProject();
        if (! SeoAccessControl::canAccessContentProjectRun($project)) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $preview = $this->previewBulkGenerate($taskIds);
        if ($preview['valid'] <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body(__('seo-content-ai::filament.projects.run_items_empty'))
                ->danger()
                ->send();

            return;
        }

        $skippedMap = [];
        foreach ($preview['skipped'] as $row) {
            $skippedMap[(int) $row['task_id']] = true;
        }
        $eligible = array_values(array_filter(
            $taskIds,
            static fn (int $id): bool => ! isset($skippedMap[$id]),
        ));

        try {
            SeoProjectResource::startGeneratePendingItems(
                $project,
                SeoProjectRun::MODE_FULL,
                [
                    'task_ids' => $eligible,
                    'use_php_engine' => true,
                ],
            );
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_started'))
                ->body(__('seo-content-ai::filament.projects.generate_pending_started_body'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.operations.generate_engine']);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.run_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function dispatchBulkStep(array $taskIds, string $action): void
    {
        $project = $this->requireProject();
        $filtered = [];
        foreach ($taskIds as $id) {
            $task = SeoProjectTask::query()->find((int) $id);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            if (SeoProjectTask::normalizeType($task->type) === SeoProjectTask::TYPE_IMPROVE) {
                continue;
            }
            $filtered[] = (int) $task->id;
        }

        if ($filtered === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.item_improve_blocked'))
                ->warning()
                ->send();

            return;
        }

        $fromStep = ContentProjectRerunFromStep::tryFromMixed($action);
        if (! $fromStep instanceof ContentProjectRerunFromStep) {
            Notification::make()
                ->title('Failed')
                ->body('Unsupported step action.')
                ->danger()
                ->send();

            return;
        }

        $this->dispatchBus(new RerunProjectItemStepCommand(
            (int) $project->id,
            $filtered,
            $fromStep,
            includeDownstream: false,
        ));
    }

    private function dispatchBus(object $command): void
    {
        $project = $this->requireProject();
        $result = app(ContentProjectCommandBus::class)->dispatch(
            $command,
            ActorContext::user(
                auth()->id() !== null ? (int) auth()->id() : null,
                (int) ($project->site_id ?? 0) ?: null,
            ),
        );

        if (! $result->success) {
            Notification::make()
                ->title('Failed')
                ->body($result->message)
                ->danger()
                ->send();

            return;
        }

        $action = $this->resolveCounterActionForCommand($command, $result);
        $taskIds = $this->resolveTaskIdsForCommand($command);
        if ($action !== null && $taskIds !== []) {
            $operationId = 'cp-op-'.bin2hex(random_bytes(8));
            $this->dispatch(
                'cp-ops-item-transition',
                operationId: $operationId,
                action: $action,
                taskIds: $taskIds,
                deltas: ContentProjectOpsCounterTransitionMap::deltas($action),
            );
            $this->skipRender();

            return;
        }

        $this->invalidateOpsCache();
    }

    private function resolveCounterActionForCommand(object $command, mixed $result = null): ?string
    {
        return match (true) {
            $command instanceof RerunProjectItemsCommand,
            $command instanceof RerunProjectItemStepCommand,
            $command instanceof ResumeProjectItemFromFailedStepCommand => ContentProjectOpsCounterTransitionMap::ACTION_RETRY,
            $command instanceof ApproveProjectItemsCommand => $this->resolveApproveCounterAction(
                $this->resolveTaskIdsForCommand($command),
            ),
            $command instanceof DebugOverrideProjectItemLifecycleCommand => $this->resolveDebugCounterAction($command, $result),
            default => null,
        };
    }

    private function resolveDebugCounterAction(DebugOverrideProjectItemLifecycleCommand $command, mixed $result = null): ?string
    {
        $transitions = is_object($result) && isset($result->metadata['transitions']) && is_array($result->metadata['transitions'])
            ? $result->metadata['transitions']
            : [];

        if ($transitions !== [] && isset($transitions[0]['from'], $transitions[0]['to'])) {
            return ContentProjectOpsCounterTransitionMap::debugAction(
                (string) $transitions[0]['from'],
                (string) $transitions[0]['to'],
            );
        }

        $to = strtolower(trim($command->toLifecycle));

        return ContentProjectOpsCounterTransitionMap::debugAction('published', $to)
            ?? ContentProjectOpsCounterTransitionMap::debugAction('approved', $to)
            ?? ContentProjectOpsCounterTransitionMap::debugAction('scheduled', $to);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function resolveApproveCounterAction(array $taskIds): string
    {
        if ($taskIds === []) {
            return ContentProjectOpsCounterTransitionMap::ACTION_APPROVE;
        }

        $project = $this->requireProject();
        $projectId = (int) $project->getKey();
        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereIn('id', $taskIds)
            ->get();

        $userId = auth()->id() !== null ? (int) auth()->id() : 0;
        $viewedMap = $userId > 0
            ? app(ContentProjectGenerationReadStateStore::class)
                ->viewedCompletedAtByItemIds($userId, $projectId, $taskIds)
            : [];

        $fromInReview = 0;
        $fromNeedsReviewUnread = 0;
        $fromSelfEdit = 0;

        foreach ($tasks as $task) {
            $tid = (int) $task->id;
            if ($task->content_manager_reviewed_at !== null) {
                $fromInReview++;
                continue;
            }

            $taskStatus = strtolower(trim((string) ($task->status ?? '')));
            // Legacy handoff residue.
            if ($taskStatus === SeoProjectTask::STATUS_REVIEWING || $taskStatus === 'reviewing') {
                $fromInReview++;
                continue;
            }

            if (isset($viewedMap[$tid])) {
                $fromSelfEdit++;
            } else {
                $fromNeedsReviewUnread++;
            }
        }

        if ($fromInReview > 0 && $fromNeedsReviewUnread === 0 && $fromSelfEdit === 0) {
            return ContentProjectOpsCounterTransitionMap::ACTION_APPROVE;
        }

        if ($fromNeedsReviewUnread > 0 && $fromInReview === 0 && $fromSelfEdit === 0) {
            return ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_FROM_NEEDS_REVIEW;
        }

        if ($fromSelfEdit > 0 && $fromInReview === 0 && $fromNeedsReviewUnread === 0) {
            return ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_SELF_EDIT;
        }

        // Mixed batch — prefer In Review / Needs Review atomic pairs over bare +approved.
        if ($fromInReview >= $fromNeedsReviewUnread && $fromInReview >= $fromSelfEdit) {
            return ContentProjectOpsCounterTransitionMap::ACTION_APPROVE;
        }

        if ($fromNeedsReviewUnread >= $fromSelfEdit) {
            return ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_FROM_NEEDS_REVIEW;
        }

        return ContentProjectOpsCounterTransitionMap::ACTION_APPROVE_SELF_EDIT;
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function afterPublishingCommandSuccess(string $op, array $taskIds, mixed $result = null): void
    {
        if ($op === 'send_to_publishing_queue' && $taskIds !== []) {
            $this->persistOptimisticRemovals($taskIds);
            $this->invalidateOpsCache();
            $this->dispatch(
                'cp-ops-view-publishing-queue',
                url: SeoProjectResource::getPublishingQueueUrl($this->requireProject()),
            );

            return;
        }

        if (! in_array($op, ['schedule', 'unschedule', 'auto_schedule'], true) || $taskIds === []) {
            $this->invalidateOpsCache();

            return;
        }

        $action = match ($op) {
            'unschedule' => ContentProjectOpsCounterTransitionMap::ACTION_UNSCHEDULE,
            'schedule', 'auto_schedule' => $this->resolveScheduleCounterAction($taskIds),
            default => null,
        };

        if ($action === null) {
            $this->invalidateOpsCache();

            return;
        }

        $operationId = 'cp-op-'.bin2hex(random_bytes(8));
        $this->dispatch(
            'cp-ops-item-transition',
            operationId: $operationId,
            action: $action,
            taskIds: $taskIds,
            deltas: ContentProjectOpsCounterTransitionMap::deltas($action),
        );
        $this->skipRender();
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function resolveScheduleCounterAction(array $taskIds): string
    {
        if ($taskIds === []) {
            return ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE;
        }

        $project = $this->requireProject();
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $taskIds)
            ->with(['article:id,review_status'])
            ->get();

        $votes = [];
        foreach ($tasks as $task) {
            $article = $task->article;
            $reviewStatus = $article instanceof SeoArticle
                ? strtolower(trim((string) ($article->review_status ?? '')))
                : '';
            $votes[] = ContentProjectOpsCounterTransitionMap::scheduleActionForRow([
                'lifecycle' => $reviewStatus === 'approved' ? 'approved' : 'review',
                'is_content_manager_reviewed' => $task->content_manager_reviewed_at !== null,
                'review_status' => $reviewStatus,
                'is_recently_completed' => $task->content_manager_reviewed_at === null
                    && $reviewStatus !== 'approved'
                    && $reviewStatus !== 'pending_review',
            ]);
        }

        if ($votes === []) {
            return ContentProjectOpsCounterTransitionMap::ACTION_SCHEDULE;
        }

        $counts = array_count_values($votes);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @return list<int>
     */
    private function resolveTaskIdsForCommand(object $command): array
    {
        if (property_exists($command, 'itemRefs') && is_array($command->itemRefs)) {
            return $this->normalizeSelectedIds($command->itemRefs);
        }

        return [];
    }

    private function resolveProject(int|string $key): SeoProject
    {
        $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()
            ->with(['user', 'site'])
            ->find($key);
        abort_unless($project instanceof SeoProject, 404);

        return $project;
    }

    private function requireProject(): SeoProject
    {
        if (! $this->project instanceof SeoProject) {
            $this->project = $this->resolveProject($this->record);
        }

        return $this->project;
    }
}
