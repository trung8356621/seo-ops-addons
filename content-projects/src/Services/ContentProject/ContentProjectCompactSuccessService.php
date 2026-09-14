<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskEventRecorder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectAuditSuccessClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Preview + execute “Sắp xếp bài thành công” trong cùng domain + month.
 * Không tạo project mới, không chạy AI, không publish, không archive tự động.
 */
final class ContentProjectCompactSuccessService
{
    public function __construct(
        private readonly ContentProjectCompactSuccessPlanner $planner = new ContentProjectCompactSuccessPlanner,
        private readonly ContentProjectCompactSuccessSafetyGuard $safety = new ContentProjectCompactSuccessSafetyGuard,
        private readonly ContentProjectAuditSuccessClassifier $classifier = new ContentProjectAuditSuccessClassifier,
        private readonly ContentProjectItemStateResolver $stateResolver = new ContentProjectItemStateResolver,
        private readonly ?SeoProjectArticleOwnerSyncService $articleOwnerSync = null,
        private readonly ?SeoProjectTaskEventRecorder $eventRecorder = null,
    ) {}

    public function lockKey(int $siteId, string $monthYyyyMm): string
    {
        $month = ContentProjectMonthContext::normalize($monthYyyyMm);

        return 'content_project_compact_success:'.$siteId.':'.$month;
    }

    /**
     * @return array<int, string> site_id => domain
     */
    public function domainOptionsForMonth(string $monthYyyyMm): array
    {
        $monthDate = ContentProjectMonthContext::toDateString($monthYyyyMm);
        $siteIds = SeoProjectTask::query()
            ->from('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.archived_at')
            ->whereNull('p.archived_at')
            ->whereDate('p.month', $monthDate)
            ->where(function ($q): void {
                $q->where('p.kind', SeoProject::KIND_MONTHLY)->orWhereNull('p.kind');
            })
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->whereNotNull('t.site_id')
            ->where('t.site_id', '>', 0)
            ->distinct()
            ->pluck('t.site_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($siteIds === []) {
            return [];
        }

        return Site::query()
            ->whereIn('id', $siteIds)
            ->orderBy('domain')
            ->pluck('domain', 'id')
            ->mapWithKeys(static fn (mixed $domain, mixed $id): array => [
                (int) $id => (string) $domain,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(int $siteId, string $monthYyyyMm): array
    {
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.compact_success_domain_required'),
            ]);
        }

        $scope = $this->buildScopeSnapshot($siteId, $monthYyyyMm);
        $plan = $this->planner->plan($scope);
        $plan['operation_id'] = (string) Str::uuid();
        $plan['domain'] = $scope['domain'];
        $plan['month_label'] = ContentProjectMonthContext::display($monthYyyyMm);
        $plan['message'] = (string) __('seo-content-ai::filament.projects.compact_success_no_new_project');

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(int $siteId, string $monthYyyyMm, ?int $actorUserId = null): array
    {
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.compact_success_domain_required'),
            ]);
        }

        $lock = Cache::lock($this->lockKey($siteId, $monthYyyyMm), 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'compact' => __('seo-content-ai::filament.projects.compact_success_locked'),
            ]);
        }

        try {
            return $this->executeLocked($siteId, $monthYyyyMm, $actorUserId);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function executeLocked(int $siteId, string $monthYyyyMm, ?int $actorUserId): array
    {
        $operationId = (string) Str::uuid();
        $connection = (new SeoProjectTask)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($siteId, $monthYyyyMm, $actorUserId, $operationId): array {
            $scope = $this->buildScopeSnapshot($siteId, $monthYyyyMm, lockProjects: true);
            $plan = $this->planner->plan($scope);
            $plan['operation_id'] = $operationId;
            $plan['domain'] = $scope['domain'];
            $plan['month_label'] = ContentProjectMonthContext::display($monthYyyyMm);

            if (! empty($plan['already_compacted']) && (int) ($plan['totals']['moves'] ?? 0) === 0) {
                $plan['executed'] = false;
                $plan['moved'] = 0;
                $plan['creates_project'] = false;
                $plan['archives_project'] = false;

                return $plan;
            }

            if (! ($plan['can_execute'] ?? false)) {
                throw ValidationException::withMessages([
                    'compact' => __('seo-content-ai::filament.projects.compact_success_preview_stale'),
                ]);
            }

            $moves = is_array($plan['moves'] ?? null) ? $plan['moves'] : [];
            if ($moves === []) {
                $plan['executed'] = false;
                $plan['moved'] = 0;

                return $plan;
            }

            // JIT re-check safety for every moved task + involved projects.
            $projectIds = [];
            foreach ($moves as $move) {
                $projectIds[(int) $move['from_project_id']] = true;
                $projectIds[(int) $move['to_project_id']] = true;
            }

            foreach (array_keys($projectIds) as $projectId) {
                $project = SeoProject::query()->whereKey($projectId)->lockForUpdate()->first();
                if (! $project instanceof SeoProject) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_preview_stale'),
                    ]);
                }
                $gate = $this->safety->assessProject($project);
                if (! $gate['ok']) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_unsafe', [
                            'reason' => (string) $gate['reason'],
                        ]),
                    ]);
                }
            }

            $taskIds = array_map(static fn (array $m): int => (int) $m['task_id'], $moves);
            $tasks = SeoProjectTask::query()
                ->whereIn('id', $taskIds)
                ->with(['article', 'project'])
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->id);

            if ($tasks->count() !== count(array_unique($taskIds))) {
                throw ValidationException::withMessages([
                    'compact' => __('seo-content-ai::filament.projects.compact_success_preview_stale'),
                ]);
            }

            $monthStart = Carbon::parse(ContentProjectMonthContext::toDateString($monthYyyyMm))->startOfMonth();
            $touched = [];

            foreach ($moves as $move) {
                $taskId = (int) $move['task_id'];
                $fromId = (int) $move['from_project_id'];
                $toId = (int) $move['to_project_id'];
                $task = $tasks->get($taskId);
                if (! $task instanceof SeoProjectTask) {
                    throw new RuntimeException('Missing task during compact: '.$taskId);
                }

                if ((int) ($task->project_id ?? 0) !== $fromId) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_preview_stale'),
                    ]);
                }

                if ((int) ($task->site_id ?? 0) !== $siteId) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_cross_domain'),
                    ]);
                }

                $itemGate = $this->safety->assessItem($task, $task->project);
                if (! $itemGate['movable']) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_unsafe', [
                            'reason' => (string) $itemGate['reason'],
                        ]),
                    ]);
                }

                $target = SeoProject::query()->whereKey($toId)->first();
                if (! $target instanceof SeoProject) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_preview_stale'),
                    ]);
                }

                // Month isolation.
                if ($target->monthCarbon()->format('Y-m') !== ContentProjectMonthContext::normalize($monthYyyyMm)) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_cross_month'),
                    ]);
                }

                $dayIndex = $target->registeredTaskCount();
                $task->forceFill([
                    'project_id' => $toId,
                    'site_id' => $siteId,
                    'target_date' => $monthStart->copy()->addDays(min($dayIndex, 27))->format('Y-m-d'),
                ])->save();

                if ($this->hasOriginTable()) {
                    SeoContentProjectItemOrigin::query()
                        ->where('project_task_id', $taskId)
                        ->update(['project_id' => $toId]);
                }

                $this->recordMoveEvent($task, $fromId, $toId, $operationId, $actorUserId, (string) ($move['reason'] ?? ''));

                $touched[$fromId] = true;
                $touched[$toId] = true;
            }

            $sync = $this->articleOwnerSync ?? app(SeoProjectArticleOwnerSyncService::class);
            foreach (array_keys($touched) as $projectId) {
                $project = SeoProject::query()->whereKey($projectId)->first();
                if (! $project instanceof SeoProject) {
                    continue;
                }
                $project->syncTotalTasksCounter();
                $sync->syncProjectArticles($project->fresh() ?? $project);
            }

            // Re-summarize after moves for UI.
            $fresh = $this->preview($siteId, $monthYyyyMm);
            $fresh['operation_id'] = $operationId;
            $fresh['executed'] = true;
            $fresh['moved'] = count($moves);
            $fresh['moves_applied'] = $moves;
            $fresh['creates_project'] = false;
            $fresh['archives_project'] = false;
            $fresh['audit_ready_project_ids'] = array_values(array_map(
                static fn (array $row): int => (int) $row['project_id'],
                array_filter(
                    is_array($fresh['projects_after'] ?? null) ? $fresh['projects_after'] : [],
                    static fn (array $row): bool => ! empty($row['audit_ready']),
                ),
            ));

            return $fresh;
        });
    }

    /**
     * @return array{
     *     site_id: int,
     *     domain: string,
     *     month: string,
     *     projects: list<array<string, mixed>>
     * }
     */
    private function buildScopeSnapshot(int $siteId, string $monthYyyyMm, bool $lockProjects = false): array
    {
        $month = ContentProjectMonthContext::normalize($monthYyyyMm);
        $monthDate = ContentProjectMonthContext::toDateString($month);
        $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? ('#'.$siteId));

        $projectQuery = SeoProject::query()
            ->whereDate('month', $monthDate)
            ->where(function ($q): void {
                $q->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            })
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->whereNull('archived_at')
            ->whereIn('id', function ($sub) use ($siteId, $monthDate): void {
                $sub->select('t.project_id')
                    ->from('seo_project_tasks as t')
                    ->join('seo_projects as p', 'p.id', '=', 't.project_id')
                    ->where('t.site_id', $siteId)
                    ->whereNull('t.archived_at')
                    ->whereDate('p.month', $monthDate);
            })
            ->with(['user'])
            ->orderBy('id');

        if ($lockProjects) {
            $projectQuery->lockForUpdate();
        }

        $projects = $projectQuery->get();
        $snapshots = [];

        foreach ($projects as $project) {
            if (! $project instanceof SeoProject || $project->isArchive() || $project->isProjectArchived()) {
                continue;
            }

            $projectGate = $this->safety->assessProject($project);
            $writerId = (int) ($project->user_id ?? 0);
            $writerName = $this->writerName($project, $writerId);

            $tasks = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->active()
                ->with(['article'])
                ->orderBy('id')
                ->get();

            $items = [];
            foreach ($tasks as $task) {
                if (! $task instanceof SeoProjectTask) {
                    continue;
                }
                $taskSiteId = (int) ($task->site_id ?? 0);
                // Keep foreign-domain items for capacity / audit-ready math; classifier only for scope site.
                $state = $this->stateResolver->resolve($task, $task->article);
                $success = $taskSiteId === $siteId && $this->classifier->isAuditSuccess($state);

                $movable = true;
                $skipReason = null;
                if (! $projectGate['ok']) {
                    $movable = false;
                    $skipReason = (string) $projectGate['reason'];
                } else {
                    $itemGate = $this->safety->assessItem($task, $project);
                    $movable = $itemGate['movable'];
                    $skipReason = $itemGate['reason'];
                }

                // Writer mismatch: never move across writers in phase 1 (handled by planner grouping).
                $items[] = [
                    'task_id' => (int) $task->getKey(),
                    'site_id' => $taskSiteId,
                    'success' => $success,
                    'movable' => $movable,
                    'skip_reason' => $skipReason,
                ];
            }

            $snapshots[] = [
                'project_id' => (int) $project->getKey(),
                'name' => (string) ($project->name ?? ('#'.$project->getKey())),
                'writer_id' => $writerId,
                'writer_name' => $writerName,
                'archived' => false,
                'items' => $items,
            ];
        }

        return [
            'site_id' => $siteId,
            'domain' => $domain,
            'month' => $month,
            'projects' => $snapshots,
        ];
    }

    private function writerName(SeoProject $project, int $writerId): string
    {
        if ($writerId <= 0) {
            return '';
        }
        $user = $project->relationLoaded('user') ? $project->user : User::query()->find($writerId);
        if (! $user instanceof User) {
            return '#'.$writerId;
        }
        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : (string) ($user->email ?? '#'.$writerId);
    }

    private function hasOriginTable(): bool
    {
        try {
            return Schema::connection((new SeoContentProjectItemOrigin)->getConnectionName())
                ->hasTable((new SeoContentProjectItemOrigin)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    private function recordMoveEvent(
        SeoProjectTask $task,
        int $fromProjectId,
        int $toProjectId,
        string $operationId,
        ?int $actorUserId,
        string $reason,
    ): void {
        try {
            $recorder = $this->eventRecorder ?? app(SeoProjectTaskEventRecorder::class);
            $recorder->record(
                $task,
                SeoProjectTaskEventType::TaskUpdated,
                null,
                null,
                [
                    'compact_operation_id' => $operationId,
                    'moved_from_project_id' => $fromProjectId,
                    'moved_to_project_id' => $toProjectId,
                    'compacted_at' => now()->toIso8601String(),
                    'compacted_by' => $actorUserId,
                    'compact_reason' => $reason,
                    'event' => 'task.compact_moved',
                ],
                null,
                $actorUserId,
            );
        } catch (Throwable) {
            // Audit log best-effort — move already committed in same transaction; don't hide failure mid-loop.
        }
    }
}
