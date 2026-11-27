<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use App\Models\Site;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationReadStateStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectVirtualMonthCommandPartitioner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectVirtualMonthTaskResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Virtual Content Project workspace: site × planning_month (no seo_projects shell row).
 */
final class ViewDomainMonthlyProject extends ViewSeoProject
{
    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-seo-project-operations';

    protected static bool $shouldRegisterNavigation = false;

    public bool $virtualDomainMonth = true;

    public function mount(int|string|null $record = null): void
    {
        // Read site/month from the route — do NOT add them as mount() params.
        // Mixing missing $record (numeric key) with named route params breaks Livewire
        // trait mount unpacking: "Cannot use positional argument after named argument".
        $site = (int) (request()->route('site') ?? 0);
        $month = (string) (request()->route('month') ?? '');
        abort_unless($site > 0 && $month !== '', 404);
        abort_unless(SeoAccessControl::canAccessContentFeatures(), 403);
        abort_unless(SeoAccessControl::canAccessSite($site), 403);

        $this->virtualSiteId = $site;
        $this->virtualPlanningMonth = ContentProjectMonthContext::normalize($month);
        $this->record = 0;
        $this->project = null;
        $this->searchInput = $this->search;

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

        $this->syncArticleGenerationModePreference();
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('seo-content-ai::filament.projects.virtual_domain_month_title');
    }

    public function getHeading(): string|Htmlable
    {
        $domain = $this->resolveDomainLabel();
        $monthLabel = Carbon::createFromFormat('Y-m', $this->virtualPlanningMonth)?->format('m/Y')
            ?? $this->virtualPlanningMonth;
        $total = (int) ($this->summarySnapshot['planning_total'] ?? $this->summarySnapshot['total_items'] ?? 0);
        $label = $domain.' · '.$monthLabel.' · '.$total.' '.__('seo-content-ai::filament.projects.virtual_domain_month_items_suffix');

        return new HtmlString('<span class="inline-flex flex-wrap items-center gap-2">'.e($label).'</span>');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return (string) __('seo-content-ai::filament.projects.virtual_domain_month_hint');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_projects')
                ->label(__('seo-content-ai::filament.projects.virtual_back_to_list'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('index')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOperationsPayloadProperty(): array
    {
        $cacheKey = $this->buildOpsCacheKey().'|vmonth:'.$this->virtualSiteId.':'.$this->virtualPlanningMonth;
        if ($this->cachedOperationsPayload !== null && $this->cachedOperationsKey === $cacheKey) {
            return $this->applyOptimisticRowHides($this->cachedOperationsPayload);
        }

        $payload = app(ContentProjectItemOperationsReadModel::class)->forSiteMonth(
            $this->virtualSiteId,
            $this->virtualPlanningMonth,
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
                'reconcile_stale' => false,
                'editor_return_url' => $this->buildVirtualReturnUrl(),
            ],
        );

        $this->cachedOperationsPayload = $payload;
        $this->cachedOperationsKey = $cacheKey;
        $this->summarySnapshot = ContentProjectItemOperationsReadModel::normalizeSummaryStats(
            is_array($payload['stats'] ?? null) ? $payload['stats'] : [],
        );

        return $this->applyOptimisticRowHides($payload);
    }

    public function fetchOpsSummary(): array
    {
        $payload = $this->operationsPayload;

        return ContentProjectItemOperationsReadModel::normalizeSummaryStats(
            is_array($payload['stats'] ?? null) ? $payload['stats'] : [],
        );
    }

    /**
     * @return array{task: SeoProjectTask, project: SeoProject}
     */
    public function resolveOwnedTask(int $taskId): array
    {
        return app(ContentProjectVirtualMonthTaskResolver::class)->resolveOwnedTask(
            $this->virtualSiteId,
            $this->virtualPlanningMonth,
            $taskId,
        );
    }

    /**
     * @param  list<int>  $itemRefs
     */
    protected function commandProjectRefForItemRefs(array $itemRefs): int|string
    {
        $normalized = array_values(array_filter(array_map('intval', $itemRefs), static fn (int $id): bool => $id > 0));
        if ($normalized === []) {
            abort(403, (string) __('seo-content-ai::filament.projects.virtual_whole_project_disabled'));
        }

        return (int) $this->resolveOwnedTask((int) $normalized[0])['project']->getKey();
    }

    protected function requireProject(): SeoProject
    {
        if ($this->projectContextForCommand instanceof SeoProject) {
            return $this->projectContextForCommand;
        }

        abort(403, (string) __('seo-content-ai::filament.projects.virtual_whole_project_disabled'));
    }

    /**
     * Soft planner resolve — must not abort the virtual page during trait mount/hydrate.
     */
    protected function resolvePlannerProject(): ?SeoProject
    {
        return $this->projectContextForCommand instanceof SeoProject
            ? $this->projectContextForCommand
            : null;
    }

    protected function dispatchBus(object $command): void
    {
        $taskIds = $this->resolveTaskIdsForCommand($command);
        if ($taskIds === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.virtual_whole_project_disabled'))
                ->warning()
                ->send();

            return;
        }

        $partitioner = app(ContentProjectVirtualMonthCommandPartitioner::class);
        $groups = $partitioner->partitionTaskIdsByProject($taskIds);
        if ($groups === []) {
            Notification::make()->title(__('Not found'))->danger()->send();

            return;
        }

        $failures = 0;
        foreach ($groups as $projectId => $ids) {
            $project = SeoProject::query()->with('user')->find((int) $projectId);
            if (! $project instanceof SeoProject) {
                $failures++;
                continue;
            }
            $this->projectContextForCommand = $project;
            $scoped = $partitioner->rescopeCommand($command, (int) $projectId, $ids);
            try {
                parent::dispatchBus($scoped);
            } catch (\Throwable) {
                $failures++;
            } finally {
                $this->projectContextForCommand = null;
            }
        }

        if ($failures > 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.virtual_partition_partial_fail', ['count' => $failures]))
                ->warning()
                ->send();
        }
    }

    protected function dispatchPublishingCommand(object $command, string $op): void
    {
        $embedded = property_exists($command, 'itemRefs') && is_array($command->itemRefs)
            ? array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                $command->itemRefs,
            ), static fn (int $id): bool => $id > 0))
            : [];

        if ($embedded === [] && $op !== 'auto_schedule') {
            parent::dispatchPublishingCommand($command, $op);

            return;
        }

        $partitioner = app(ContentProjectVirtualMonthCommandPartitioner::class);
        $groups = $partitioner->partitionTaskIdsByProject($embedded !== [] ? $embedded : $this->selectedItemIds());
        foreach ($groups as $projectId => $ids) {
            $project = SeoProject::query()->find((int) $projectId);
            if (! $project instanceof SeoProject) {
                continue;
            }
            $this->projectContextForCommand = $project;
            $scoped = $partitioner->rescopeCommand($command, (int) $projectId, $ids);
            try {
                parent::dispatchPublishingCommand($scoped, $op);
            } finally {
                $this->projectContextForCommand = null;
            }
        }
    }

    protected function dispatchGenerate(array $taskIds): void
    {
        $partitioner = app(ContentProjectVirtualMonthCommandPartitioner::class);
        foreach ($partitioner->partitionTaskIdsByProject($taskIds) as $projectId => $ids) {
            $project = SeoProject::query()->find((int) $projectId);
            if (! $project instanceof SeoProject) {
                continue;
            }
            $this->projectContextForCommand = $project;
            try {
                parent::dispatchGenerate($ids);
            } finally {
                $this->projectContextForCommand = null;
            }
        }
    }

    protected function dispatchBulkStep(array $taskIds, string $action): void
    {
        $partitioner = app(ContentProjectVirtualMonthCommandPartitioner::class);
        foreach ($partitioner->partitionTaskIdsByProject($taskIds) as $projectId => $ids) {
            $project = SeoProject::query()->find((int) $projectId);
            if (! $project instanceof SeoProject) {
                continue;
            }
            $this->projectContextForCommand = $project;
            try {
                parent::dispatchBulkStep($ids, $action);
            } finally {
                $this->projectContextForCommand = null;
            }
        }
    }

    public function pollRestartWithKeywordStatus(int $taskId, ?string $executionRef = null): array
    {
        $this->projectContextForCommand = $this->resolveOwnedTask($taskId)['project'];
        try {
            return parent::pollRestartWithKeywordStatus($taskId, $executionRef);
        } finally {
            $this->projectContextForCommand = null;
        }
    }

    public function openSelectExistingArticle(int $taskId): void
    {
        $this->runWithOwnedTask($taskId, fn () => parent::openSelectExistingArticle($taskId));
    }

    public function skipGenerationOne(int $taskId): void
    {
        $this->runWithOwnedTask($taskId, fn () => parent::skipGenerationOne($taskId));
    }

    public function acknowledgeGenerationError(int $taskId): void
    {
        $this->runWithOwnedTask($taskId, fn () => parent::acknowledgeGenerationError($taskId));
    }

    public function allowGenerationOne(int $taskId): void
    {
        $this->runWithOwnedTask($taskId, fn () => parent::allowGenerationOne($taskId));
    }

    public function resumeFromFailedStep(int $taskId): void
    {
        $this->runWithOwnedTask($taskId, fn () => parent::resumeFromFailedStep($taskId));
    }

    public function changeItemStatus(int $taskId, string $status): void
    {
        $this->runWithOwnedTask($taskId, fn () => parent::changeItemStatus($taskId, $status));
    }

    public function openRestartWithKeyword(int|SeoProjectTask $taskOrId): void
    {
        $taskId = $taskOrId instanceof SeoProjectTask ? (int) $taskOrId->getKey() : (int) $taskOrId;
        $this->runWithOwnedTask($taskId, fn () => parent::openRestartWithKeyword($taskOrId));
    }

    public function confirmRestartWithKeyword(?int $taskId = null, ?string $keyword = null): array
    {
        $resolved = (int) ($taskId ?? $this->restartWithKeywordTaskId ?? 0);
        if ($resolved <= 0) {
            return parent::confirmRestartWithKeyword($taskId, $keyword);
        }

        return $this->runWithOwnedTask(
            $resolved,
            fn (): array => parent::confirmRestartWithKeyword($taskId, $keyword),
        );
    }

    public function openMissingArticleConfirm(int|SeoProjectTask $taskOrId): void
    {
        $taskId = $taskOrId instanceof SeoProjectTask ? (int) $taskOrId->getKey() : (int) $taskOrId;
        $this->runWithOwnedTask($taskId, fn () => parent::openMissingArticleConfirm($taskOrId));
    }

    public function confirmRecreateMissingArticle(?int $taskId = null): void
    {
        $resolved = (int) ($taskId ?? $this->missingArticleConfirmTaskId ?? 0);
        if ($resolved <= 0) {
            parent::confirmRecreateMissingArticle($taskId);

            return;
        }
        $this->runWithOwnedTask($resolved, fn () => parent::confirmRecreateMissingArticle($taskId));
    }

    public function skipGenerationSelected(): void
    {
        $ids = $this->selectedItemIds();
        if ($ids === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.queue_select_required'))
                ->warning()
                ->send();

            return;
        }

        $this->dispatchBus(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BlockProjectItemGenerationCommand(
            $this->commandProjectRefForItemRefs($ids),
            $ids,
            'operator_skip_generation',
        ));
        $this->resetPage();
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function runWithOwnedTask(int $taskId, callable $callback): mixed
    {
        $this->projectContextForCommand = $this->resolveOwnedTask($taskId)['project'];
        try {
            return $callback();
        } finally {
            $this->projectContextForCommand = null;
        }
    }

    public function markAllRecentlyCompletedViewed(): void
    {
        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            return;
        }

        $rows = is_array($this->operationsPayload['rows'] ?? null) ? $this->operationsPayload['rows'] : [];
        $byProject = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['is_recently_completed'])) {
                continue;
            }
            $taskId = (int) ($row['task_id'] ?? 0);
            $projectId = (int) ($row['project_id'] ?? 0);
            $completedAt = ContentProjectRecentlyCompletedDefinition::parseTimestamp(
                $row['generation_completed_at'] ?? null,
            );
            if ($taskId <= 0 || $projectId <= 0 || $completedAt === null) {
                continue;
            }
            $byProject[$projectId][$taskId] = $completedAt;
        }

        if ($byProject === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.ops_optimistic_update_failed'))
                ->danger()
                ->send();

            return;
        }

        $store = app(ContentProjectGenerationReadStateStore::class);
        $marked = 0;
        foreach ($byProject as $projectId => $unread) {
            $marked += $store->markManyViewed($userId, (int) $projectId, $unread);
        }

        $this->invalidateOpsCache();
        $this->resetClientOptimisticHints();

        if ($marked <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.ops_optimistic_update_failed'))
                ->danger()
                ->send();
        }
    }

    /**
     * @return list<array{site_id: int, domain: string, url: string}>
     */
    public function getVirtualDomainOptions(): array
    {
        $out = [];
        $current = $this->virtualSiteId;
        foreach (SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->get(['id', 'domain']) as $site) {
            if (! $site instanceof Site) {
                continue;
            }
            $siteId = (int) $site->getKey();
            $out[] = [
                'site_id' => $siteId,
                'domain' => (string) ($site->domain ?? '#'.$siteId),
                'url' => SeoProjectResource::getDomainMonthlyVirtualUrl(
                    $siteId,
                    $this->virtualPlanningMonth,
                    request()->query(),
                ),
                'is_current' => $siteId === $current,
            ];
        }

        return $out;
    }

    /**
     * @return array{prev: ?string, next: ?string, current: string}
     */
    public function getVirtualMonthNavigation(): array
    {
        $current = Carbon::createFromFormat('Y-m', $this->virtualPlanningMonth)?->startOfMonth();
        if (! $current instanceof Carbon) {
            return ['prev' => null, 'next' => null, 'current' => $this->virtualPlanningMonth];
        }

        $prev = $current->copy()->subMonth()->format('Y-m');
        $next = $current->copy()->addMonth()->format('Y-m');
        $query = request()->query();

        return [
            'prev' => SeoProjectResource::getDomainMonthlyVirtualUrl($this->virtualSiteId, $prev, $query),
            'next' => SeoProjectResource::getDomainMonthlyVirtualUrl($this->virtualSiteId, $next, $query),
            'current' => $this->virtualPlanningMonth,
        ];
    }

    private function resolveDomainLabel(): string
    {
        $domain = trim((string) (Site::query()->whereKey($this->virtualSiteId)->value('domain') ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        return '#'.$this->virtualSiteId;
    }

    private function buildVirtualReturnUrl(): string
    {
        return SeoProjectResource::getDomainMonthlyVirtualUrl(
            $this->virtualSiteId,
            $this->virtualPlanningMonth,
            request()->query(),
        );
    }
}