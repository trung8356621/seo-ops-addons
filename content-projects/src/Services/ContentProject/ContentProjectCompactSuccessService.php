<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskEventRecorder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Preview + execute “Gom bài đã xong” — auto-partition generator_done vs not_done.
 * Không chọn project đích thủ công. Không tạo project mới. Không chạy AI.
 */
final class ContentProjectCompactSuccessService
{
    public function __construct(
        private readonly ContentProjectCompactSuccessPlanner $planner = new ContentProjectCompactSuccessPlanner,
        private readonly ContentProjectCompactSuccessSafetyGuard $safety = new ContentProjectCompactSuccessSafetyGuard,
        private readonly ContentProjectItemStateResolver $stateResolver = new ContentProjectItemStateResolver,
        private readonly ?SeoProjectArticleOwnerSyncService $articleOwnerSync = null,
        private readonly ?SeoProjectTaskEventRecorder $eventRecorder = null,
    ) {}

    public function lockKey(int $siteId, string $monthYyyyMm): string
    {
        $month = ContentProjectMonthContext::normalize($monthYyyyMm);
        $scope = $siteId > 0 ? (string) $siteId : 'all';

        return 'content_project_compact_success:'.$scope.':'.$month;
    }

    /**
     * @return array<int, string> site_id => domain
     */
    public function domainOptionsForMonth(string $monthYyyyMm): array
    {
        $monthDate = ContentProjectMonthContext::toDateString($monthYyyyMm);

        $siteIds = DB::connection((new SeoProjectTask)->getConnectionName())
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.archived_at')
            ->whereNull('p.archived_at')
            ->whereNull('t.deleted_at')
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
        if ($siteId < 0) {
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
        if ($siteId < 0) {
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

        return DB::connection($connection)->transaction(function () use (
            $siteId,
            $monthYyyyMm,
            $actorUserId,
            $operationId,
        ): array {
            $scope = $this->buildScopeSnapshot($siteId, $monthYyyyMm, lockProjects: true);

            if (! empty($scope['blocked'])) {
                throw ValidationException::withMessages([
                    'compact' => __('seo-content-ai::filament.projects.compact_success_unsafe', [
                        'reason' => (string) ($scope['block_reason'] ?? 'active_running'),
                    ]),
                ]);
            }

            $plan = $this->planner->plan($scope);
            $plan['operation_id'] = $operationId;
            $plan['domain'] = $scope['domain'];
            $plan['month_label'] = ContentProjectMonthContext::display($monthYyyyMm);

            if (! empty($plan['already_partitioned']) && (int) ($plan['totals']['moves'] ?? 0) === 0) {
                $plan['executed'] = false;
                $plan['moved'] = 0;
                $plan['moved_generator_done'] = 0;
                $plan['moved_not_done'] = 0;
                $plan['skipped_count'] = (int) ($plan['totals']['skipped'] ?? 0);
                $plan['creates_project'] = false;
                $plan['archives_project'] = false;

                return $plan;
            }

            if (! ($plan['can_execute'] ?? false) && (int) ($plan['totals']['moves'] ?? 0) === 0) {
                throw ValidationException::withMessages([
                    'compact' => __('seo-content-ai::filament.projects.compact_success_cannot_execute'),
                ]);
            }

            $moves = is_array($plan['moves'] ?? null) ? $plan['moves'] : [];
            if ($moves === []) {
                $plan['executed'] = false;
                $plan['moved'] = 0;
                $plan['moved_generator_done'] = 0;
                $plan['moved_not_done'] = 0;
                $plan['skipped_count'] = (int) ($plan['totals']['skipped'] ?? 0);

                return $plan;
            }

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
                if ($project->monthCarbon()->format('Y-m') !== ContentProjectMonthContext::normalize($monthYyyyMm)) {
                    throw ValidationException::withMessages([
                        'compact' => __('seo-content-ai::filament.projects.compact_success_cross_month'),
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

            $moved = [];
            $movedGeneratorDone = 0;
            $movedNotDone = 0;
            $jitSkipped = [];
            $touched = [];

            foreach ($moves as $move) {
                $taskId = (int) $move['task_id'];
                $fromId = (int) $move['from_project_id'];
                $toId = (int) $move['to_project_id'];
                $reason = (string) ($move['reason'] ?? '');
                $task = $tasks->get($taskId);

                if (! $task instanceof SeoProjectTask) {
                    $jitSkipped[] = ['task_id' => $taskId, 'reason' => 'missing_task'];

                    continue;
                }

                if ((int) ($task->project_id ?? 0) !== $fromId) {
                    $jitSkipped[] = ['task_id' => $taskId, 'reason' => 'project_changed'];

                    continue;
                }

                if ($siteId > 0 && (int) ($task->site_id ?? 0) !== $siteId) {
                    $jitSkipped[] = [
                        'task_id' => $taskId,
                        'reason' => ContentProjectCompactSuccessPlanner::SKIP_WRONG_DOMAIN_MONTH,
                    ];

                    continue;
                }

                $article = $task->article instanceof SeoArticle ? $task->article : null;
                $state = $this->stateResolver->resolve($task, $article);
                $classification = $this->safety->classifyItem(
                    $task,
                    $state,
                    $article,
                    $task->project instanceof SeoProject ? $task->project : null,
                    $siteId,
                );

                if (! $classification['movable']) {
                    $jitSkipped[] = [
                        'task_id' => $taskId,
                        'reason' => (string) ($classification['skip_reason'] ?? ContentProjectCompactSuccessPlanner::SKIP_LOCKED),
                    ];

                    continue;
                }

                // Direction sanity: pack only generator_done; clean only not_done.
                if ($reason === ContentProjectCompactSuccessPlanner::REASON_PACK_GENERATOR_DONE
                    && ! $classification['generator_done']
                ) {
                    $jitSkipped[] = [
                        'task_id' => $taskId,
                        'reason' => ContentProjectCompactSuccessPlanner::SKIP_MISSING_CONTENT,
                    ];

                    continue;
                }
                if ($reason === ContentProjectCompactSuccessPlanner::REASON_CLEAN_NOT_DONE
                    && $classification['generator_done']
                ) {
                    $jitSkipped[] = [
                        'task_id' => $taskId,
                        'reason' => 'reclassified_generator_done',
                    ];

                    continue;
                }

                $target = SeoProject::query()->whereKey($toId)->first();
                if (! $target instanceof SeoProject) {
                    $jitSkipped[] = ['task_id' => $taskId, 'reason' => 'destination_missing'];

                    continue;
                }

                $task->forceFill(['project_id' => $toId])->save();

                if ($this->hasOriginTable()) {
                    SeoContentProjectItemOrigin::query()
                        ->where('project_task_id', $taskId)
                        ->update(['project_id' => $toId]);
                }

                $this->recordMoveEvent($task, $fromId, $toId, $operationId, $actorUserId, $reason);

                $moved[] = $move;
                if ($reason === ContentProjectCompactSuccessPlanner::REASON_PACK_GENERATOR_DONE) {
                    $movedGeneratorDone++;
                } elseif ($reason === ContentProjectCompactSuccessPlanner::REASON_CLEAN_NOT_DONE) {
                    $movedNotDone++;
                }
                $touched[$fromId] = true;
                $touched[$toId] = true;
            }

            foreach (array_keys($touched) as $projectId) {
                $project = SeoProject::query()->whereKey($projectId)->first();
                if (! $project instanceof SeoProject) {
                    continue;
                }
                $project->syncTotalTasksCounter();
                if ($siteId > 0) {
                    $sync = $this->articleOwnerSync ?? app(SeoProjectArticleOwnerSyncService::class);
                    $sync->syncProjectArticles($project->fresh() ?? $project);
                }
            }

            $fresh = $this->preview($siteId, $monthYyyyMm);
            $fresh['operation_id'] = $operationId;
            $fresh['executed'] = true;
            $fresh['moved'] = count($moved);
            $fresh['moved_generator_done'] = $movedGeneratorDone;
            $fresh['moved_not_done'] = $movedNotDone;
            $fresh['moves_applied'] = $moved;
            $fresh['jit_skipped'] = $jitSkipped;
            $fresh['skipped_count'] = (int) ($fresh['totals']['skipped'] ?? 0) + count($jitSkipped);
            $fresh['creates_project'] = false;
            $fresh['archives_project'] = false;

            return $fresh;
        });
    }

    /**
     * @return array{
     *     site_id: int,
     *     domain: string,
     *     month: string,
     *     blocked: bool,
     *     block_reason: string|null,
     *     projects: list<array<string, mixed>>
     * }
     */
    private function buildScopeSnapshot(
        int $siteId,
        string $monthYyyyMm,
        bool $lockProjects = false,
    ): array {
        $month = ContentProjectMonthContext::normalize($monthYyyyMm);
        $monthDate = ContentProjectMonthContext::toDateString($month);
        $domain = $siteId > 0
            ? (string) (Site::query()->whereKey($siteId)->value('domain') ?? ('#'.$siteId))
            : (string) __('seo-content-ai::filament.projects.compact_success_all_domains');

        $projectQuery = SeoProject::query()
            ->whereDate('month', $monthDate)
            ->where(function ($q): void {
                $q->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            })
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->whereNull('archived_at')
            ->with(['user'])
            ->orderBy('id');

        if ($siteId > 0) {
            $projectQuery->whereIn('id', function ($sub) use ($siteId, $monthDate): void {
                $sub->select('t.project_id')
                    ->from('seo_project_tasks as t')
                    ->join('seo_projects as p', 'p.id', '=', 't.project_id')
                    ->where('t.site_id', $siteId)
                    ->whereNull('t.archived_at')
                    ->whereNull('t.deleted_at')
                    ->whereDate('p.month', $monthDate);
            });
        }

        if ($lockProjects) {
            $projectQuery->lockForUpdate();
        }

        $projects = $projectQuery->get();
        $snapshots = [];
        $blocked = false;
        $blockReason = null;

        foreach ($projects as $project) {
            if (! $project instanceof SeoProject || $project->isArchive() || $project->isProjectArchived()) {
                continue;
            }

            $projectGate = $this->safety->assessProject($project);
            if (! $projectGate['ok'] && in_array((string) $projectGate['reason'], [
                ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING,
                'bulk_generation_active',
                'project_ai_running',
            ], true)) {
                $blocked = true;
                $blockReason = (string) $projectGate['reason'];
            }

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
                $article = $task->article instanceof SeoArticle ? $task->article : null;
                $state = $this->stateResolver->resolve($task, $article);
                $classification = $this->safety->classifyItem(
                    $task,
                    $state,
                    $article,
                    $project,
                    $siteId,
                );

                $items[] = [
                    'task_id' => (int) $task->getKey(),
                    'site_id' => (int) ($task->site_id ?? 0),
                    'kind' => (string) $classification['kind'],
                    'generator_done' => (bool) $classification['generator_done'],
                    'movable' => (bool) $classification['movable'],
                    'skip_reason' => $classification['skip_reason'],
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
            'blocked' => $blocked,
            'block_reason' => $blockReason,
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
            // Audit log best-effort.
        }
    }
}
