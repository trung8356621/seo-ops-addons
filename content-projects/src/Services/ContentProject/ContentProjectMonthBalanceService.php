<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Preview + apply horizontal “Balance months” for one site across selected planning months.
 *
 * Ownership boundary:
 * - Balance Months redistributes planning_month for all non-archived items.
 * - Generate / Review / Publishing status does not exclude items.
 * - Project / writer / article / publish schedule ownership stays unchanged.
 * - Apply stamps planning_month (+ attribution sync); never relocates project_id.
 *
 * Concurrent worker safety: apply uses lockForUpdate; eligibility does not permanently
 * freeze items that happen to be mid-worker at preview time.
 */
final class ContentProjectMonthBalanceService
{
    public function __construct(
        private readonly ContentProjectMonthBalancePlanner $planner = new ContentProjectMonthBalancePlanner,
        private readonly ContentProjectMonthBalanceEligibility $eligibility = new ContentProjectMonthBalanceEligibility,
        private readonly ?ContentProjectItemStateResolver $stateResolver = null,
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

            $reconciliation = $this->validatePlanReconciliation($plan);
            if (! $reconciliation['reconciled']) {
                throw ValidationException::withMessages([
                    'balance' => $reconciliation['error'] ?? __('seo-content-ai::filament.projects.balance_months_preview_stale'),
                ]);
            }

            $moved = $this->applyAllocation($snapshot, $plan);
            if ($moved !== (int) ($plan['move_count'] ?? 0)) {
                throw ValidationException::withMessages([
                    'balance' => __('seo-content-ai::filament.projects.balance_months_preview_stale'),
                ]);
            }

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
     * Stamp planning_month only — never relocate project_id / article / publish fields.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $plan
     */
    private function applyAllocation(array $snapshot, array $plan): int
    {
        $allocation = is_array($plan['allocation'] ?? null) ? $plan['allocation'] : [];
        $tasksById = $snapshot['tasks_by_id'] ?? [];
        $movedCount = 0;
        $attributionReady = Schema::connection('omi_seo_ai')
            ->hasTable('seo_content_project_task_planning_attributions');

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
            $fromMonth = ContentProjectMonthContext::normalize((string) ($meta['planning_month'] ?? ''));
            if ($destMonth === null || $fromMonth === $destMonth) {
                continue;
            }

            // Re-check eligibility under row lock (concurrency; not a permanent gate).
            $article = $task->relationLoaded('article')
                && $task->article instanceof \Omnichannel\Addons\Content\Models\SeoArticle
                ? $task->article
                : null;
            $state = ($this->stateResolver ?? app(ContentProjectItemStateResolver::class))->resolve($task, $article);
            $gate = $this->eligibility->classify(
                $task,
                $project instanceof SeoProject ? $project : null,
                $state,
                $article,
            );
            if (! ($gate['in_domain'] ?? false) || ! ($gate['movable'] ?? false)) {
                throw ValidationException::withMessages([
                    'balance' => __('seo-content-ai::filament.projects.balance_months_preview_stale'),
                ]);
            }

            $destDate = ContentProjectMonthContext::toDateString($destMonth);
            $task->forceFill(['planning_month' => $destDate])->save();

            if ($attributionReady) {
                SeoContentProjectTaskPlanningAttribution::query()
                    ->where('project_task_id', $taskId)
                    ->update(['planning_month' => $destMonth]);
            }

            $movedCount++;
        }

        return $movedCount;
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

            // Archived / out-of-domain: outside Balance pool entirely.
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

        $reconciliation = $this->validatePlanReconciliation($plan);
        $fingerprint = (string) ($plan['fingerprint'] ?? '');
        $canExecute = $reconciliation['reconciled']
            && $fingerprint !== ''
            && (int) ($plan['move_count'] ?? 0) > 0;

        return [
            'site_id' => $siteId,
            'domain' => $snapshot['domain'],
            'months' => $plan['months'],
            'mode' => (string) ($plan['mode'] ?? ContentProjectMonthBalancePlanner::DEFAULT_MODE),
            'rows' => $rows,
            'total_before' => $reconciliation['total_before'],
            'total_after' => $reconciliation['total_after'],
            'reconciled' => $reconciliation['reconciled'],
            'reconciliation_error' => $reconciliation['error'],
            'transfer_summary' => $reconciliation['transfer_summary'],
            'movable_total' => (int) $snapshot['movable_total'],
            'fixed_total' => (int) $snapshot['fixed_total'],
            'excluded_total' => (int) ($snapshot['excluded_total'] ?? 0),
            'move_count' => (int) $plan['move_count'],
            'capacity_blocked' => (int) ($plan['capacity_blocked'] ?? 0),
            'capacity_blocked_reasons' => $plan['capacity_blocked_reasons'] ?? [],
            'fixed_changed' => 0,
            'fixed_reasons' => $snapshot['fixed_reasons'],
            'excluded_reasons' => $snapshot['excluded_reasons'] ?? [],
            'fingerprint' => $fingerprint,
            'allocation' => $plan['allocation'],
            'moves' => $plan['moves'],
            'imbalance_before' => $plan['imbalance_before'],
            'imbalance_after' => $plan['imbalance_after'],
            'can_execute' => $canExecute,
            'operation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * Validate pure-move conservation (Total Before === Total After), unique task IDs,
     * and resolved source/destination months.
     *
     * @param  array<string, mixed>  $plan
     * @return array{
     *     reconciled: bool,
     *     total_before: int,
     *     total_after: int,
     *     transfer_summary: list<array{from: string, to: string, count: int, from_label: string, to_label: string}>,
     *     error: ?string
     * }
     */
    public function validatePlanReconciliation(array $plan): array
    {
        $months = is_array($plan['months'] ?? null) ? $plan['months'] : [];
        $monthSet = array_fill_keys($months, true);
        $currentByMonth = is_array($plan['current_by_month'] ?? null) ? $plan['current_by_month'] : [];
        $targetByMonth = is_array($plan['target_by_month'] ?? null) ? $plan['target_by_month'] : [];
        $moves = is_array($plan['moves'] ?? null) ? $plan['moves'] : [];

        $totalBefore = array_sum(array_map('intval', $currentByMonth));
        $totalAfter = array_sum(array_map('intval', $targetByMonth));

        if ($totalBefore !== $totalAfter) {
            return [
                'reconciled' => false,
                'total_before' => $totalBefore,
                'total_after' => $totalAfter,
                'transfer_summary' => [],
                'error' => sprintf('Preview totals do not reconcile: before=%d, after=%d.', $totalBefore, $totalAfter),
            ];
        }

        $seenTaskIds = [];
        $flows = [];
        foreach ($moves as $move) {
            $taskId = (int) ($move['task_id'] ?? 0);
            $from = (string) ($move['from'] ?? '');
            $to = (string) ($move['to'] ?? '');
            if ($taskId <= 0 || ! isset($monthSet[$from]) || ! isset($monthSet[$to]) || $from === $to) {
                return [
                    'reconciled' => false,
                    'total_before' => $totalBefore,
                    'total_after' => $totalAfter,
                    'transfer_summary' => [],
                    'error' => 'Preview contains an invalid or unresolved task transfer.',
                ];
            }
            if (isset($seenTaskIds[$taskId])) {
                return [
                    'reconciled' => false,
                    'total_before' => $totalBefore,
                    'total_after' => $totalAfter,
                    'transfer_summary' => [],
                    'error' => sprintf('Duplicate task transfer detected for task #%d.', $taskId),
                ];
            }
            $seenTaskIds[$taskId] = true;
            $key = $from.'->'.$to;
            if (! isset($flows[$key])) {
                $flows[$key] = [
                    'from' => $from,
                    'to' => $to,
                    'count' => 0,
                    'from_label' => ContentProjectMonthContext::display($from),
                    'to_label' => ContentProjectMonthContext::display($to),
                ];
            }
            $flows[$key]['count']++;
        }

        if (count($moves) !== (int) ($plan['move_count'] ?? 0)) {
            return [
                'reconciled' => false,
                'total_before' => $totalBefore,
                'total_after' => $totalAfter,
                'transfer_summary' => [],
                'error' => 'Move count mismatch in balance preview.',
            ];
        }

        return [
            'reconciled' => true,
            'total_before' => $totalBefore,
            'total_after' => $totalAfter,
            'transfer_summary' => array_values($flows),
            'error' => null,
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

        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($query, 'p');

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
