<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningMetaStore;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Move ALL active items of an execution Content Project to the next project month.
 * Source keeps its month and ends with 0 items. Writer unchanged. Packing reused.
 */
final class MoveContentProjectToNextMonthService
{
    public function __construct(
        private readonly ContentProjectExecutionPackingService $packing,
        private readonly McpPlanningMetaStore $mcpMeta,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
        private readonly SplitDraftContentProjectService $splitNaming,
    ) {}

    /**
     * @return array{
     *     can_move: bool,
     *     blocked_reason: string|null,
     *     item_count: int,
     *     source_month: string,
     *     source_month_label: string,
     *     target_month: string,
     *     target_month_label: string,
     *     writer_id: int|null,
     *     writer_name: string,
     *     by_domain: list<array{site_id: int, domain: string, count: int}>
     * }
     */
    public function preview(SeoProject $project): array
    {
        $sourceMonth = Carbon::parse($project->month)->startOfMonth();
        $targetMonth = $sourceMonth->copy()->addMonthNoOverflow()->startOfMonth();
        $tasks = $this->activeTasks($project);
        $gate = $this->gate($project, $tasks);

        $byDomain = [];
        foreach ($tasks as $task) {
            $siteId = (int) ($task->site_id ?? 0);
            if ($siteId <= 0) {
                $siteId = 0;
                $domain = '(no domain)';
            } else {
                $domain = trim((string) ($task->site?->domain ?? ''));
                if ($domain === '') {
                    $domain = '#'.$siteId;
                }
            }
            $key = (string) $siteId;
            if (! isset($byDomain[$key])) {
                $byDomain[$key] = [
                    'site_id' => $siteId,
                    'domain' => $domain,
                    'count' => 0,
                ];
            }
            $byDomain[$key]['count']++;
        }

        usort(
            $byDomain,
            static fn (array $a, array $b): int => ($b['count'] <=> $a['count'])
                ?: strcmp((string) $a['domain'], (string) $b['domain']),
        );

        $writerId = (int) ($project->user_id ?? 0);
        $writerName = '';
        if ($writerId > 0) {
            $user = $project->relationLoaded('user') ? $project->user : User::query()->find($writerId);
            if ($user instanceof User) {
                $writerName = trim((string) ($user->name ?? '')) !== ''
                    ? (string) $user->name
                    : (string) ($user->email ?? '#'.$writerId);
            } else {
                $writerName = '#'.$writerId;
            }
        }

        return [
            'can_move' => $gate['can_move'],
            'blocked_reason' => $gate['blocked_reason'],
            'item_count' => $tasks->count(),
            'source_month' => $sourceMonth->format('Y-m-d'),
            'source_month_label' => $sourceMonth->format('m/Y'),
            'target_month' => $targetMonth->format('Y-m-d'),
            'target_month_label' => $targetMonth->format('m/Y'),
            'writer_id' => $writerId > 0 ? $writerId : null,
            'writer_name' => $writerName,
            'by_domain' => array_values($byDomain),
        ];
    }

    /**
     * @return array{
     *     source_project_id: int,
     *     target_month: string,
     *     target_month_label: string,
     *     moved_count: int,
     *     destination_project_ids: list<int>,
     *     destinations: list<array{project_id: int, reused: bool, moved_count: int, name: string}>
     * }
     */
    public function move(SeoProject $project): array
    {
        if ($project->isDraftPlanning()) {
            throw new InvalidArgumentException('Cannot move Planning Draft to next month.');
        }
        if ($project->isArchive() || $project->isProjectArchived()) {
            throw new InvalidArgumentException('Cannot move an archived project.');
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($project): array {
            /** @var SeoProject|null $locked */
            $locked = SeoProject::query()
                ->whereKey((int) $project->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProject) {
                throw new RuntimeException('Project disappeared.');
            }

            $tasks = $this->activeTasks($locked, lock: true);
            $gate = $this->gate($locked, $tasks);
            if (! $gate['can_move']) {
                throw new RuntimeException((string) $gate['blocked_reason']);
            }

            $writerId = (int) ($locked->user_id ?? 0);
            if ($writerId <= 0) {
                throw new InvalidArgumentException('Project has no writer.');
            }

            $sourceMonth = Carbon::parse($locked->month)->startOfMonth();
            $targetMonth = $sourceMonth->copy()->addMonthNoOverflow()->startOfMonth();
            $taskIds = $tasks->map(static fn (SeoProjectTask $t): int => (int) $t->getKey())->values()->all();

            // Lock reusable destinations for writer+target month.
            SeoProject::query()
                ->activeProjects()
                ->where('user_id', $writerId)
                ->whereDate('month', $targetMonth->format('Y-m-d'))
                ->where('status', '!=', SeoProject::STATUS_DRAFT)
                ->lockForUpdate()
                ->get(['id']);

            $bins = $this->packing->planPack($writerId, $targetMonth, $taskIds);
            if ($bins === []) {
                throw new RuntimeException('Nothing to move.');
            }

            $planningEntries = $this->mcpMeta->items($locked);
            $planningByItem = [];
            foreach ($planningEntries as $entry) {
                $planningByItem[(int) $entry['project_item_id']] = $entry;
            }

            $destinations = [];
            $allMoved = [];
            /** @var list<string> $reservedNames */
            $reservedNames = [];

            foreach ($bins as $bin) {
                $chunkIds = array_values(array_map('intval', $bin['task_ids'] ?? []));
                if ($chunkIds === []) {
                    continue;
                }

                $projectId = isset($bin['project_id']) ? (int) $bin['project_id'] : 0;
                $reused = false;
                if ($projectId > 0) {
                    $execution = SeoProject::query()->whereKey($projectId)->lockForUpdate()->first();
                    if (! $execution instanceof SeoProject || ! $this->packing->isReusable($execution)) {
                        throw new RuntimeException('Reusable destination disappeared: '.$projectId);
                    }
                    $reused = true;
                } else {
                    $name = $this->splitNaming->nextExecutionProjectName($writerId, $targetMonth, $reservedNames);
                    $reservedNames[] = $name;
                    $execution = SeoProject::query()->create([
                        'name' => $name,
                        'site_id' => null,
                        'month' => $targetMonth->format('Y-m-d'),
                        'status' => SeoProject::STATUS_PENDING,
                        'kind' => SeoProject::KIND_MONTHLY,
                        'user_id' => $writerId,
                        'total_tasks' => 0,
                        'description' => null,
                        'source_draft_project_id' => (int) ($locked->source_draft_project_id ?? 0) ?: null,
                        'meta' => null,
                    ]);
                }

                $this->moveTasksPreservingState($execution, $chunkIds, $targetMonth);
                $execution->syncTotalTasksCounter();
                $this->articleOwnerSync->syncProjectArticles($execution->fresh() ?? $execution);

                $chunkMeta = [];
                foreach ($chunkIds as $tid) {
                    if (isset($planningByItem[$tid])) {
                        $chunkMeta[] = $planningByItem[$tid];
                    }
                }
                if ($chunkMeta !== []) {
                    $this->mcpMeta->upsertItems($execution, $chunkMeta);
                }

                $destinations[] = [
                    'project_id' => (int) $execution->getKey(),
                    'reused' => $reused,
                    'moved_count' => count($chunkIds),
                    'name' => (string) $execution->name,
                ];
                foreach ($chunkIds as $tid) {
                    $allMoved[] = $tid;
                }
            }

            // Source: remove moved planning meta + sync empty counters. Month unchanged.
            $this->mcpMeta->removeItems($locked, $allMoved);
            $locked->syncTotalTasksCounter();

            return [
                'source_project_id' => (int) $locked->getKey(),
                'target_month' => $targetMonth->format('Y-m-d'),
                'target_month_label' => $targetMonth->format('m/Y'),
                'moved_count' => count($allMoved),
                'destination_project_ids' => array_map(
                    static fn (array $row): int => (int) $row['project_id'],
                    $destinations,
                ),
                'destinations' => $destinations,
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectTask>  $tasks
     * @return array{can_move: bool, blocked_reason: string|null}
     */
    private function gate(SeoProject $project, $tasks): array
    {
        if ($project->isDraftPlanning() || $project->isArchive() || $project->isProjectArchived()) {
            return [
                'can_move' => false,
                'blocked_reason' => (string) __('seo-content-ai::filament.projects.move_next_month_blocked_invalid_project'),
            ];
        }

        if ($tasks->isEmpty()) {
            return [
                'can_move' => false,
                'blocked_reason' => (string) __('seo-content-ai::filament.projects.move_next_month_blocked_empty'),
            ];
        }

        if ((int) ($project->user_id ?? 0) <= 0) {
            return [
                'can_move' => false,
                'blocked_reason' => (string) __('seo-content-ai::filament.projects.move_next_month_blocked_no_writer'),
            ];
        }

        $projectId = (int) $project->getKey();
        $aiRunning = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->notConsolidated()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->exists();
        if ($aiRunning) {
            return [
                'can_move' => false,
                'blocked_reason' => (string) __('seo-content-ai::filament.projects.move_next_month_blocked_ai_running'),
            ];
        }

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $queueProcessing = $tasks->contains(
                static fn (SeoProjectTask $task): bool => (string) ($task->publish_queue_status ?? '')
                    === ContentProjectPublishQueueStatus::Processing->value,
            );
            if ($queueProcessing) {
                return [
                    'can_move' => false,
                    'blocked_reason' => (string) __('seo-content-ai::filament.projects.move_next_month_blocked_queue_processing'),
                ];
            }

            $published = $tasks->contains(function (SeoProjectTask $task): bool {
                if ((string) ($task->publish_queue_status ?? '') === ContentProjectPublishQueueStatus::Published->value) {
                    return true;
                }

                return $task->publish_published_at !== null;
            });
            if ($published) {
                return [
                    'can_move' => false,
                    'blocked_reason' => (string) __('seo-content-ai::filament.projects.move_next_month_blocked_published'),
                ];
            }
        } elseif ($tasks->contains(static fn (SeoProjectTask $task): bool => $task->publish_published_at !== null)) {
            return [
                'can_move' => false,
                'blocked_reason' => (string) __('seo-content-ai::filament.projects.move_next_month_blocked_published'),
            ];
        }

        return ['can_move' => true, 'blocked_reason' => null];
    }

    /**
     * Move task rows to destination without resetting generation/publish state.
     *
     * @param  list<int>  $taskIds
     */
    private function moveTasksPreservingState(SeoProject $execution, array $taskIds, Carbon $month): void
    {
        $executionId = (int) $execution->getKey();
        $tasks = SeoProjectTask::query()
            ->whereIn('id', $taskIds)
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->id);

        $monthStart = $month->copy()->startOfMonth();
        $dayIndex = $this->packing->activeItemCount($execution);

        foreach ($taskIds as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                throw new RuntimeException('Task missing during move: '.$taskId);
            }

            $payload = [
                'project_id' => $executionId,
                'target_date' => $monthStart->copy()->addDays(min($dayIndex, 27))->format('Y-m-d'),
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
     * @return \Illuminate\Support\Collection<int, SeoProjectTask>
     */
    private function activeTasks(SeoProject $project, bool $lock = false)
    {
        $query = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->with(['site'])
            ->orderBy('id');

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }
}
