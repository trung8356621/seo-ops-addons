<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningMetaStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Preview + apply horizontal “Balance months” for one site across selected planning months.
 *
 * Ownership boundary (strict):
 * - Balance Months = NOT-generated (pre-generation) workload only.
 * - Compact done articles = generator_done compaction.
 * - Generated tasks are OUTSIDE the Balance domain (excluded from current/fixed/target/moves).
 *
 * Invariant after apply for movable execution tasks:
 *   task.planning_month == destination month
 *   AND task.project.month == destination month (when in a monthly execution project)
 *
 * Draft-planning tasks: planning_month only (stay in draft container).
 *
 * Relocate packing uses {@see ContentProjectExecutionPackingService::planPackForBalanceMonth}
 * (month-wide free capacity; writer affinity ignored for Balance only).
 */
final class ContentProjectMonthBalanceService
{
    public function __construct(
        private readonly ContentProjectMonthBalancePlanner $planner = new ContentProjectMonthBalancePlanner,
        private readonly ContentProjectMonthBalanceEligibility $eligibility = new ContentProjectMonthBalanceEligibility,
        private readonly ?ContentProjectItemStateResolver $stateResolver = null,
        private readonly ?ContentProjectExecutionPackingService $packing = null,
        private readonly ?SplitDraftContentProjectService $splitNaming = null,
        private readonly ?SeoProjectArticleOwnerSyncService $articleOwnerSync = null,
        private readonly ?McpPlanningMetaStore $mcpMeta = null,
    ) {}

    public function lockKey(int $siteId, array $months): string
    {
        $normalized = $this->normalizeMonthList($months);

        return 'content_project_month_balance:'.$siteId.':'.implode(',', $normalized);
    }

    /**
     * Domains that have tasks whose resolved planning_month is in the given months.
     *
     * @param  list<string>  $months
     * @return array<int, string> site_id => domain
     */
    public function domainOptionsForMonths(array $months): array
    {
        $normalized = $this->normalizeMonthList($months);
        if ($normalized === []) {
            return [];
        }

        $siteIds = [];
        foreach ($this->candidateTaskRows($normalized) as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            if ($siteId > 0) {
                $siteIds[$siteId] = true;
            }
        }

        $ids = array_map('intval', array_keys($siteIds));
        if ($ids === []) {
            return [];
        }

        return Site::query()
            ->whereIn('id', $ids)
            ->orderBy('domain')
            ->pluck('domain', 'id')
            ->mapWithKeys(static fn (mixed $domain, mixed $id): array => [
                (int) $id => (string) $domain,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    public function preview(
        int $siteId,
        array $months,
        string $mode = ContentProjectMonthBalancePlanner::DEFAULT_MODE,
    ): array {
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.balance_months_domain_required'),
            ]);
        }

        $normalized = $this->normalizeMonthList($months);
        if (count($normalized) < 2) {
            throw ValidationException::withMessages([
                'months' => __('seo-content-ai::filament.projects.balance_months_months_required'),
            ]);
        }

        $mode = $this->planner->normalizeMode($mode);
        $snapshot = $this->buildSnapshot($siteId, $normalized, lock: false);
        $plan = $this->planner->plan(
            $normalized,
            $snapshot['fixed_by_month'],
            $snapshot['movable'],
            $mode,
        );

        return $this->presentPreview($siteId, $snapshot, $plan);
    }

    /**
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    public function apply(
        int $siteId,
        array $months,
        ?string $expectedFingerprint = null,
        ?int $actorUserId = null,
        string $mode = ContentProjectMonthBalancePlanner::DEFAULT_MODE,
    ): array {
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.balance_months_domain_required'),
            ]);
        }

        $normalized = $this->normalizeMonthList($months);
        if (count($normalized) < 2) {
            throw ValidationException::withMessages([
                'months' => __('seo-content-ai::filament.projects.balance_months_months_required'),
            ]);
        }

        $mode = $this->planner->normalizeMode($mode);
        $lock = Cache::lock($this->lockKey($siteId, $normalized), 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'balance' => __('seo-content-ai::filament.projects.balance_months_locked'),
            ]);
        }

        try {
            return $this->applyLocked($siteId, $normalized, $expectedFingerprint, $actorUserId, $mode);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    private function applyLocked(
        int $siteId,
        array $months,
        ?string $expectedFingerprint,
        ?int $actorUserId,
        string $mode,
    ): array {
        $connection = (new SeoProjectTask)->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $siteId,
            $months,
            $expectedFingerprint,
            $mode,
        ): array {
            $snapshot = $this->buildSnapshot($siteId, $months, lock: true);
            $plan = $this->planner->plan(
                $months,
                $snapshot['fixed_by_month'],
                $snapshot['movable'],
                $mode,
            );

            if ($expectedFingerprint !== null && $expectedFingerprint !== ''
                && ! hash_equals($expectedFingerprint, (string) $plan['fingerprint'])
            ) {
                throw ValidationException::withMessages([
                    'balance' => __('seo-content-ai::filament.projects.balance_months_preview_stale'),
                ]);
            }

            $moved = $this->applyAllocation($snapshot, $plan);

            return [
                'site_id' => $siteId,
                'domain' => $snapshot['domain'],
                'months' => $months,
                'mode' => $mode,
                'moved_count' => $moved,
                'fixed_changed' => 0,
                'fingerprint' => $plan['fingerprint'],
                'target_by_month' => $plan['target_by_month'],
                'operation_id' => (string) Str::uuid(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $plan
     */
    private function applyAllocation(array $snapshot, array $plan): int
    {
        $allocation = is_array($plan['allocation'] ?? null) ? $plan['allocation'] : [];
        $tasksById = $snapshot['tasks_by_id'] ?? [];
        $packing = $this->packing ?? app(ContentProjectExecutionPackingService::class);
        $naming = $this->splitNaming ?? app(SplitDraftContentProjectService::class);
        $ownerSync = $this->articleOwnerSync ?? app(SeoProjectArticleOwnerSyncService::class);
        $mcp = $this->mcpMeta ?? app(McpPlanningMetaStore::class);

        /** @var array<string, list<int>> $relocateByMonth YYYY-MM => task ids */
        $relocateByMonth = [];
        $planningOnly = [];
        $touchedProjectIds = [];

        foreach ($allocation as $taskId => $destMonth) {
            $taskId = (int) $taskId;
            $destMonth = ContentProjectMonthContext::normalize((string) $destMonth);
            $meta = $tasksById[$taskId] ?? null;
            if (! is_array($meta)) {
                continue;
            }
            if (! ($meta['in_domain'] ?? true) || ! ($meta['movable'] ?? false)) {
                continue;
            }

            /** @var SeoProjectTask $task */
            $task = $meta['task'];
            $project = $meta['project'] instanceof SeoProject ? $meta['project'] : null;
            $fromMonth = (string) $meta['planning_month'];

            $destDate = ContentProjectMonthContext::toDateString($destMonth);
            $needsPlanningStamp = ContentProjectMonthContext::normalize((string) ($task->planning_month ?? '')) !== $destMonth
                || $task->planning_month === null;

            if ($project instanceof SeoProject && $project->isDraftPlanning()) {
                if ($needsPlanningStamp || $fromMonth !== $destMonth) {
                    $task->forceFill(['planning_month' => $destDate])->save();
                    $planningOnly[] = $taskId;
                }
                continue;
            }

            $projectMonth = $project instanceof SeoProject
                ? ContentProjectMonthContext::parseOrNull($project->month)
                : null;

            $needsRelocate = $project instanceof SeoProject
                && ! $project->isDraftPlanning()
                && $projectMonth !== null
                && $projectMonth !== $destMonth;

            if ($needsRelocate) {
                $relocateByMonth[$destMonth] ??= [];
                $relocateByMonth[$destMonth][] = $taskId;
                continue;
            }

            if ($needsPlanningStamp || $fromMonth !== $destMonth) {
                $payload = ['planning_month' => $destDate];
                if ($projectMonth === $destMonth && $project instanceof SeoProject) {
                    $day = Carbon::parse((string) ($task->target_date ?? $destDate))->day;
                    $payload['target_date'] = Carbon::parse($destDate)->startOfMonth()
                        ->addDays(min(max($day, 1), 28) - 1)
                        ->format('Y-m-d');
                }
                $task->forceFill($payload)->save();
                $planningOnly[] = $taskId;
                if ($project instanceof SeoProject) {
                    $touchedProjectIds[(int) $project->getKey()] = true;
                }
            }
        }

        $movedCount = count($planningOnly);

        foreach ($relocateByMonth as $destMonth => $taskIds) {
            $destMonth = ContentProjectMonthContext::normalize((string) $destMonth);
            $destCarbon = Carbon::parse(ContentProjectMonthContext::toDateString($destMonth));
            $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
            $newProjectUserId = $this->preferWriterForNewBalanceProject($taskIds, $tasksById, $destCarbon);

            SeoProject::query()
                ->activeProjects()
                ->whereDate('month', $destCarbon->format('Y-m-d'))
                ->where('status', '!=', SeoProject::STATUS_DRAFT)
                ->lockForUpdate()
                ->get(['id']);

            $bins = $packing->planPackForBalanceMonth($destCarbon, $taskIds, $newProjectUserId);
            /** @var list<string> $reservedNames */
            $reservedNames = [];
            $sourceMetaByTask = [];

            foreach ($taskIds as $tid) {
                $meta = $tasksById[$tid] ?? null;
                $srcProject = is_array($meta) && ($meta['project'] ?? null) instanceof SeoProject
                    ? $meta['project']
                    : null;
                if ($srcProject instanceof SeoProject) {
                    foreach ($mcp->items($srcProject) as $entry) {
                        if ((int) ($entry['project_item_id'] ?? 0) === $tid) {
                            $sourceMetaByTask[$tid] = $entry;
                        }
                    }
                }
            }

            foreach ($bins as $bin) {
                $chunkIds = array_values(array_map('intval', $bin['task_ids'] ?? []));
                if ($chunkIds === []) {
                    continue;
                }

                $projectId = isset($bin['project_id']) ? (int) $bin['project_id'] : 0;
                $binUserId = (int) ($bin['user_id'] ?? 0);
                if ($projectId > 0) {
                    $execution = SeoProject::query()->whereKey($projectId)->lockForUpdate()->first();
                    if (! $execution instanceof SeoProject || ! $packing->canAcceptMoreItems($execution)) {
                        throw ValidationException::withMessages([
                            'balance' => __('seo-content-ai::filament.projects.balance_months_pack_failed'),
                        ]);
                    }
                } else {
                    $createUserId = $binUserId > 0 ? $binUserId : $newProjectUserId;
                    if ($createUserId <= 0) {
                        throw ValidationException::withMessages([
                            'balance' => __('seo-content-ai::filament.projects.balance_months_pack_failed'),
                        ]);
                    }
                    $name = $naming->nextExecutionProjectName($createUserId, $destCarbon, $reservedNames);
                    $reservedNames[] = $name;
                    $sourceDraftId = null;
                    foreach ($chunkIds as $tid) {
                        $meta = $tasksById[$tid] ?? null;
                        $src = is_array($meta) ? ($meta['project'] ?? null) : null;
                        if ($src instanceof SeoProject && (int) ($src->source_draft_project_id ?? 0) > 0) {
                            $sourceDraftId = (int) $src->source_draft_project_id;
                            break;
                        }
                    }
                    $execution = SeoProject::query()->create([
                        'name' => $name,
                        'site_id' => null,
                        'month' => $destCarbon->format('Y-m-d'),
                        'status' => SeoProject::STATUS_PENDING,
                        'kind' => SeoProject::KIND_MONTHLY,
                        'user_id' => $createUserId,
                        'total_tasks' => 0,
                        'description' => null,
                        'source_draft_project_id' => $sourceDraftId,
                        'meta' => null,
                    ]);
                }

                $this->relocateTasksPreservingState($execution, $chunkIds, $destCarbon);
                $execution->syncTotalTasksCounter();
                $ownerSync->syncProjectArticles($execution->fresh() ?? $execution);

                $chunkMeta = [];
                foreach ($chunkIds as $tid) {
                    if (isset($sourceMetaByTask[$tid])) {
                        $chunkMeta[] = $sourceMetaByTask[$tid];
                    }
                }
                if ($chunkMeta !== []) {
                    $mcp->upsertItems($execution, $chunkMeta);
                }

                $touchedProjectIds[(int) $execution->getKey()] = true;
                foreach ($chunkIds as $tid) {
                    $meta = $tasksById[$tid] ?? null;
                    $src = is_array($meta) ? ($meta['project'] ?? null) : null;
                    if ($src instanceof SeoProject) {
                        $touchedProjectIds[(int) $src->getKey()] = true;
                        $mcp->removeItems($src, [$tid]);
                    }
                }

                $movedCount += count($chunkIds);
            }
        }

        foreach (array_keys($touchedProjectIds) as $projectId) {
            $project = SeoProject::query()->whereKey((int) $projectId)->first();
            if ($project instanceof SeoProject) {
                $project->syncTotalTasksCounter();
            }
        }

        return $movedCount;
    }

    /**
     * Prefer a writer already present among relocating tasks; else any writer with
     * projects in the destination month (for rare empty-month creates).
     *
     * @param  list<int>  $taskIds
     * @param  array<int, array<string, mixed>>  $tasksById
     */
    private function preferWriterForNewBalanceProject(array $taskIds, array $tasksById, Carbon $destMonth): int
    {
        foreach ($taskIds as $taskId) {
            $meta = $tasksById[$taskId] ?? null;
            $project = is_array($meta) ? ($meta['project'] ?? null) : null;
            if ($project instanceof SeoProject) {
                $writerId = (int) ($project->user_id ?? 0);
                if ($writerId > 0) {
                    return $writerId;
                }
            }
        }

        $existing = SeoProject::query()
            ->activeProjects()
            ->whereDate('month', $destMonth->format('Y-m-d'))
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->where('user_id', '>', 0)
            ->orderBy('id')
            ->value('user_id');

        return (int) ($existing ?? 0);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function relocateTasksPreservingState(SeoProject $execution, array $taskIds, Carbon $month): void
    {
        $executionId = (int) $execution->getKey();
        $packing = $this->packing ?? app(ContentProjectExecutionPackingService::class);
        $tasks = SeoProjectTask::query()
            ->whereIn('id', $taskIds)
            ->with(['article', 'project'])
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->id);

        $monthStart = $month->copy()->startOfMonth();
        $dayIndex = $packing->activeItemCount($execution);
        $planningDate = $monthStart->format('Y-m-d');

        foreach ($taskIds as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                throw ValidationException::withMessages([
                    'balance' => __('seo-content-ai::filament.projects.balance_months_pack_failed'),
                ]);
            }

            $sourceProject = $task->relationLoaded('project')
                ? $task->project
                : SeoProject::query()->whereKey((int) ($task->project_id ?? 0))->first();
            $article = $task->relationLoaded('article') && $task->article instanceof \Omnichannel\Addons\Content\Models\SeoArticle
                ? $task->article
                : null;
            $state = ($this->stateResolver ?? app(ContentProjectItemStateResolver::class))->resolve($task, $article);
            $gate = $this->eligibility->classify(
                $task,
                $sourceProject instanceof SeoProject ? $sourceProject : null,
                $state,
                $article,
            );
            if (! ($gate['in_domain'] ?? false) || ! ($gate['movable'] ?? false)) {
                throw ValidationException::withMessages([
                    'balance' => __('seo-content-ai::filament.projects.balance_months_preview_stale'),
                ]);
            }

            $payload = [
                'project_id' => $executionId,
                'target_date' => $monthStart->copy()->addDays(min($dayIndex, 27))->format('Y-m-d'),
                'planning_month' => $planningDate,
            ];
            $siteId = (int) ($task->site_id ?? 0);
            if ($siteId > 0) {
                $payload['site_id'] = $siteId;
            }

            $task->forceFill($payload)->save();

            SeoContentProjectItemOrigin::query()
                ->where('project_task_id', $taskId)
                ->update(['project_id' => $executionId]);

            $dayIndex++;
        }
    }

    /**
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    private function buildSnapshot(int $siteId, array $months, bool $lock): array
    {
        $monthSet = array_fill_keys($months, true);
        $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '#'.$siteId);

        $fixedByMonth = [];
        $movable = [];
        $tasksById = [];
        $fixedReasons = [];
        $excludedReasons = [];
        $excludedTotal = 0;
        foreach ($months as $month) {
            $fixedByMonth[$month] = 0;
        }

        $query = SeoProjectTask::query()
            ->where('site_id', $siteId)
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->with(['project', 'article']);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $tasks = $query->orderBy('id')->get();
        $stateResolver = $this->stateResolver ?? app(ContentProjectItemStateResolver::class);

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $project = $task->relationLoaded('project') ? $task->project : null;
            $projectIsDraft = $project instanceof SeoProject && $project->isDraftPlanning();
            $planningMonth = PlanningMonthBackfill::resolve([
                'planning_month' => $task->planning_month ?? null,
                'created_at' => $task->created_at,
                'target_date' => $task->target_date,
                'project_month' => $project instanceof SeoProject ? $project->month : null,
                'project_is_draft' => $projectIsDraft,
            ]);

            if (! isset($monthSet[$planningMonth])) {
                continue;
            }

            $article = $task->relationLoaded('article')
                && $task->article instanceof \Omnichannel\Addons\Content\Models\SeoArticle
                ? $task->article
                : null;
            $state = $stateResolver->resolve($task, $article);
            $gate = $this->eligibility->classify(
                $task,
                $project instanceof SeoProject ? $project : null,
                $state,
                $article,
            );

            // Generated / out-of-domain: outside Balance pool entirely (not fixed load).
            if (! ($gate['in_domain'] ?? true)) {
                $excludedTotal++;
                $reason = (string) ($gate['reason'] ?? 'excluded');
                $excludedReasons[$reason] = ($excludedReasons[$reason] ?? 0) + 1;
                continue;
            }

            $tasksById[(int) $task->getKey()] = [
                'task' => $task,
                'project' => $project instanceof SeoProject ? $project : null,
                'planning_month' => $planningMonth,
                'in_domain' => true,
                'movable' => $gate['movable'],
                'fixed' => $gate['fixed'],
                'reason' => $gate['reason'],
            ];

            if ($gate['movable']) {
                $movable[] = [
                    'id' => (int) $task->getKey(),
                    'month' => $planningMonth,
                ];
            } else {
                $fixedByMonth[$planningMonth]++;
                $reason = (string) ($gate['reason'] ?? 'fixed');
                $fixedReasons[$reason] = ($fixedReasons[$reason] ?? 0) + 1;
            }
        }

        return [
            'site_id' => $siteId,
            'domain' => $domain,
            'months' => $months,
            'fixed_by_month' => $fixedByMonth,
            'movable' => $movable,
            'tasks_by_id' => $tasksById,
            'fixed_reasons' => $fixedReasons,
            'excluded_reasons' => $excludedReasons,
            'excluded_total' => $excludedTotal,
            'fixed_total' => array_sum($fixedByMonth),
            'movable_total' => count($movable),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function presentPreview(int $siteId, array $snapshot, array $plan): array
    {
        $rows = [];
        foreach ($plan['months'] as $month) {
            $current = (int) ($plan['current_by_month'][$month] ?? 0);
            $fixed = (int) ($plan['fixed_by_month'][$month] ?? 0);
            $movable = (int) ($plan['movable_by_month'][$month] ?? 0);
            $after = (int) ($plan['target_by_month'][$month] ?? 0);
            $rows[] = [
                'month' => $month,
                'month_label' => ContentProjectMonthContext::display($month),
                'current' => $current,
                'fixed' => $fixed,
                'movable' => $movable,
                'after' => $after,
                'incoming' => max(0, $after - $current),
                'outgoing' => max(0, $current - $after),
            ];
        }

        return [
            'site_id' => $siteId,
            'domain' => $snapshot['domain'],
            'months' => $plan['months'],
            'mode' => (string) ($plan['mode'] ?? ContentProjectMonthBalancePlanner::DEFAULT_MODE),
            'rows' => $rows,
            'movable_total' => (int) $snapshot['movable_total'],
            'fixed_total' => (int) $snapshot['fixed_total'],
            'excluded_total' => (int) ($snapshot['excluded_total'] ?? 0),
            'move_count' => (int) $plan['move_count'],
            'fixed_changed' => 0,
            'fixed_reasons' => $snapshot['fixed_reasons'],
            'excluded_reasons' => $snapshot['excluded_reasons'] ?? [],
            'fingerprint' => $plan['fingerprint'],
            'allocation' => $plan['allocation'],
            'moves' => $plan['moves'],
            'imbalance_before' => $plan['imbalance_before'],
            'imbalance_after' => $plan['imbalance_after'],
            'can_execute' => true,
            'operation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * @param  list<string>  $months
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function candidateTaskRows(array $months)
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')) {
            return collect();
        }

        $query = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->whereNotNull('t.site_id')
            ->where('t.site_id', '>', 0)
            ->select([
                't.id',
                't.site_id',
                't.planning_month',
                't.created_at',
                't.target_date',
                'p.month as project_month',
                'p.status as project_status',
            ]);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }

        $monthSet = array_fill_keys($months, true);

        return $query->get()->filter(function (object $row) use ($monthSet): bool {
            $resolved = PlanningMonthBackfill::resolve([
                'planning_month' => $row->planning_month ?? null,
                'created_at' => $row->created_at ?? null,
                'target_date' => $row->target_date ?? null,
                'project_month' => $row->project_month ?? null,
                'project_is_draft' => strtolower(trim((string) ($row->project_status ?? ''))) === SeoProject::STATUS_DRAFT,
            ]);

            return isset($monthSet[$resolved]);
        })->values();
    }

    /**
     * @param  list<string>|array<int, mixed>  $months
     * @return list<string>
     */
    private function normalizeMonthList(array $months): array
    {
        $out = [];
        foreach ($months as $month) {
            $normalized = ContentProjectMonthContext::parseOrNull(is_string($month) ? $month : null);
            if ($normalized !== null) {
                $out[$normalized] = $normalized;
            }
        }
        $list = array_values($out);
        sort($list);

        return $list;
    }
}
