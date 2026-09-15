<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;

/**
 * Targeted data repair for false-success generation rows and stale run ownership.
 * Never calls AI. Never deletes valid article body. Never mutates other projects.
 */
final class ContentProjectFalseSuccessRepairService
{
    public const REPAIR_MESSAGE = 'Legacy repair: item was marked generated but no article content exists.';

    public function __construct(
        private readonly SeoProjectRunItemService $runItemService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diagnose(int $projectId): array
    {
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return ['ok' => false, 'error' => 'project_not_found', 'project_id' => $projectId];
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->planned()
            ->orderBy('id')
            ->with(['article:id,title,body,status,last_ai_content_at,editor_document_status'])
            ->get();

        $runs = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $taskIds = $tasks->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();
        $latestItemsByTask = $this->latestRunItemsByTaskIds($taskIds);

        $rows = [];
        $falseSuccessIds = [];
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $tid = (int) $task->id;
            $article = $task->article;
            $hasBody = $this->articleHasBody($article instanceof SeoArticle ? $article : null);
            $latest = $latestItemsByTask[$tid] ?? null;
            $falseSuccess = $this->isFalseSuccess($task, $hasBody, $latest);
            if ($falseSuccess) {
                $falseSuccessIds[] = $tid;
            }

            $rows[] = [
                'task_id' => $tid,
                'article_id' => (int) ($task->article_id ?? 0),
                'keyword' => ContentProjectGenerationKeyword::effective($task),
                'title' => trim((string) ($task->title ?? '')),
                'task_status' => (string) ($task->status ?? ''),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'scheduled_publish_at' => $task->scheduled_publish_at?->toIso8601String(),
                'publish_published_at' => $task->publish_published_at?->toIso8601String(),
                'article_has_body' => $hasBody,
                'article_title' => $article instanceof SeoArticle ? trim((string) ($article->title ?? '')) : null,
                'latest_run_item_id' => $latest['id'] ?? null,
                'latest_run_id' => $latest['run_id'] ?? null,
                'latest_run_item_status' => $latest['status'] ?? null,
                'latest_run_item_message' => $latest['message'] ?? null,
                'latest_run_item_error' => $latest['error_message'] ?? null,
                'false_success' => $falseSuccess,
            ];
        }

        $runSnapshots = [];
        foreach ($runs as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }
            $settings = is_array($run->settings) ? $run->settings : [];
            $engine = is_array($settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY]
                : [];
            $runSnapshots[] = [
                'run_id' => (int) $run->id,
                'status' => (string) $run->status,
                'finished_at' => $run->finished_at?->toIso8601String(),
                'total' => (int) ($run->total ?? 0),
                'succeeded' => (int) ($run->succeeded ?? 0),
                'failed' => (int) ($run->failed ?? 0),
                'active_dispatch' => $engine['active_dispatch'] ?? null,
                'recoverable' => $engine[ContentProjectRunRecoverableState::SETTINGS_KEY] ?? null,
                'worker_lost' => $engine[ContentProjectRunRecoverableState::WORKER_LOST_KEY] ?? null,
                'circuit_breaker' => $engine['circuit_breaker'] ?? null,
                'stop_reason' => $engine['stop_reason'] ?? null,
                'final_status' => $engine['final_status'] ?? null,
                'finalized_at' => $engine['finalized_at'] ?? null,
            ];
        }

        $statusCounts = [];
        foreach ($rows as $row) {
            $key = (string) ($row['task_status'] ?? '');
            $statusCounts[$key] = ($statusCounts[$key] ?? 0) + 1;
        }

        return [
            'ok' => true,
            'project' => [
                'id' => (int) $project->id,
                'name' => (string) ($project->name ?? ''),
                'month' => $project->month !== null ? (string) $project->month : null,
                'site_id' => (int) ($project->site_id ?? 0),
            ],
            'task_count' => count($rows),
            'status_counts' => $statusCounts,
            'false_success_task_ids' => $falseSuccessIds,
            'tasks' => $rows,
            'runs' => $runSnapshots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(
        int $projectId,
        bool $apply = false,
        bool $fixEmptySuccess = true,
        ?int $onlyTaskId = null,
    ): array {
        $before = $this->diagnose($projectId);
        if (! ($before['ok'] ?? false)) {
            return $before + ['apply' => $apply, 'changes' => []];
        }

        $changes = [];
        if ($fixEmptySuccess) {
            $changes = array_merge(
                $changes,
                $this->repairFalseSuccessTasks($projectId, $apply, $onlyTaskId),
            );
        }

        $changes = array_merge(
            $changes,
            $this->repairStaleRuntime($projectId, $apply),
        );

        if ($apply) {
            $this->syncLatestRuns($projectId);
        }

        $after = $this->diagnose($projectId);

        return [
            'ok' => true,
            'apply' => $apply,
            'project_id' => $projectId,
            'before' => [
                'status_counts' => $before['status_counts'] ?? [],
                'false_success_task_ids' => $before['false_success_task_ids'] ?? [],
                'runs' => $before['runs'] ?? [],
            ],
            'after' => [
                'status_counts' => $after['status_counts'] ?? [],
                'false_success_task_ids' => $after['false_success_task_ids'] ?? [],
                'runs' => $after['runs'] ?? [],
            ],
            'changes' => $changes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function repairFalseSuccessTasks(int $projectId, bool $apply, ?int $onlyTaskId): array
    {
        $query = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->planned()
            ->with(['article:id,title,body,status,last_ai_content_at,editor_document_status']);
        if ($onlyTaskId !== null && $onlyTaskId > 0) {
            $query->whereKey($onlyTaskId);
        }

        $taskIds = (clone $query)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $latestItemsByTask = $this->latestRunItemsByTaskIds($taskIds);

        $changes = [];
        foreach ($query->orderBy('id')->get() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $article = $task->article;
            $hasBody = $this->articleHasBody($article instanceof SeoArticle ? $article : null);
            $latest = $latestItemsByTask[(int) $task->id] ?? null;
            if (! $this->isFalseSuccess($task, $hasBody, $latest)) {
                continue;
            }

            $before = [
                'task_id' => (int) $task->id,
                'status' => (string) $task->status,
                'completed_at' => $task->completed_at?->toIso8601String(),
                'article_id' => (int) ($task->article_id ?? 0),
                'article_has_body' => $hasBody,
                'latest_run_item_status' => $latest['status'] ?? null,
            ];

            if ($apply) {
                DB::connection('omi_seo_ai')->transaction(function () use ($task): void {
                    /** @var SeoProjectTask|null $locked */
                    $locked = SeoProjectTask::query()->whereKey((int) $task->id)->lockForUpdate()->first();
                    if (! $locked instanceof SeoProjectTask) {
                        return;
                    }
                    $locked->loadMissing('article');
                    $hasBody = $this->articleHasBody(
                        $locked->article instanceof SeoArticle ? $locked->article : null,
                    );
                    $latestLocked = $this->latestRunItemsByTaskIds([(int) $locked->id])[(int) $locked->id] ?? null;
                    if (! $this->isFalseSuccess($locked, $hasBody, $latestLocked)) {
                        return;
                    }

                    $locked->update([
                        'status' => SeoProjectTask::STATUS_FAILED,
                        'completed_at' => null,
                        'content_manager_reviewed_at' => null,
                        'content_manager_reviewed_by' => null,
                    ]);

                    // Clear stale AI-success marker on hollow article (keep row / body null).
                    if ($locked->article instanceof SeoArticle && ! $hasBody) {
                        $articlePayload = [];
                        if ($locked->article->last_ai_content_at !== null) {
                            $articlePayload['last_ai_content_at'] = null;
                        }
                        if ($articlePayload !== []) {
                            $locked->article->update($articlePayload);
                        }
                    }

                    // Demote latest success/completed/skipped execution row — keep history id.
                    $latestSuccess = SeoProjectRunItem::query()
                        ->where('task_id', (int) $locked->id)
                        ->articleExecution()
                        ->whereIn('status', [
                            SeoProjectRunItemStatus::Success->value,
                            'completed',
                            SeoProjectRunItemStatus::Skipped->value,
                        ])
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();
                    if ($latestSuccess instanceof SeoProjectRunItem) {
                        $latestSuccess->update([
                            'status' => SeoProjectRunItemStatus::Failed->value,
                            'message' => self::REPAIR_MESSAGE,
                            'error_message' => self::REPAIR_MESSAGE,
                            'finished_at' => $latestSuccess->finished_at ?? now(),
                        ]);
                    }
                });

                RuntimeLogger::info('content_project.false_success_repaired', [
                    'project_id' => $projectId,
                    'task_id' => (int) $task->id,
                    'before' => $before,
                ]);
            }

            $task->refresh();
            $changes[] = [
                'action' => 'fix_empty_success',
                'applied' => $apply,
                'before' => $before,
                'after' => [
                    'task_id' => (int) $task->id,
                    'status' => $apply ? SeoProjectTask::STATUS_FAILED : (string) $task->status,
                    'message' => self::REPAIR_MESSAGE,
                ],
            ];
        }

        return $changes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function repairStaleRuntime(int $projectId, bool $apply): array
    {
        $changes = [];
        $runs = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($runs as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }
            $settings = is_array($run->settings) ? $run->settings : [];
            $engine = is_array($settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY]
                : [];
            $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            $status = (string) $run->status;
            $terminal = in_array($status, [
                SeoProjectRun::STATUS_COMPLETED,
                SeoProjectRun::STATUS_CANCELLED,
                SeoProjectRun::STATUS_FAILED,
            ], true);

            $clearDispatch = false;
            $finalizeRun = false;
            $reason = null;

            if ($active !== null && $terminal) {
                $clearDispatch = true;
                $reason = 'terminal_with_active_dispatch';
            } elseif ($active !== null && ! $terminal) {
                $runItemId = (int) ($active['run_item_id'] ?? 0);
                $item = $runItemId > 0 ? SeoProjectRunItem::query()->find($runItemId) : null;
                $itemLive = $item instanceof SeoProjectRunItem
                    && (int) $item->run_id === (int) $run->id
                    && in_array((string) $item->status, [
                        SeoProjectRunItemStatus::Pending->value,
                        SeoProjectRunItemStatus::Processing->value,
                    ], true);
                if (! $itemLive) {
                    $clearDispatch = true;
                    $reason = 'active_dispatch_without_live_item';
                    // No live worker evidence — finalize non-terminal run consistently as failed.
                    if (in_array($status, [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING], true)) {
                        $finalizeRun = true;
                    }
                }
            } elseif ($active === null
                && in_array($status, [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING], true)
                && $run->finished_at === null
            ) {
                $processing = SeoProjectRunItem::query()
                    ->where('run_id', (int) $run->id)
                    ->articleExecution()
                    ->where('status', SeoProjectRunItemStatus::Processing->value)
                    ->count();
                if ($processing === 0) {
                    $finalizeRun = true;
                    $reason = 'running_without_dispatch_or_processing';
                }
            }

            if (! $clearDispatch && ! $finalizeRun) {
                continue;
            }

            $before = [
                'run_id' => (int) $run->id,
                'status' => $status,
                'active_dispatch' => $active,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ];

            if ($apply) {
                DB::connection('omi_seo_ai')->transaction(function () use ($run, $clearDispatch, $finalizeRun, $status): void {
                    /** @var SeoProjectRun|null $locked */
                    $locked = SeoProjectRun::query()->whereKey((int) $run->id)->lockForUpdate()->first();
                    if (! $locked instanceof SeoProjectRun) {
                        return;
                    }
                    $settings = is_array($locked->settings) ? $locked->settings : [];
                    $engine = is_array($settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] ?? null)
                        ? $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY]
                        : [];

                    if ($clearDispatch) {
                        unset($engine['active_dispatch']);
                    }

                    $payload = ['settings' => $settings];
                    $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] = $engine;
                    $payload['settings'] = $settings;

                    if ($finalizeRun) {
                        $engine['finalized_at'] = $engine['finalized_at'] ?? now()->toIso8601String();
                        $engine['final_status'] = $engine['final_status']
                            ?? ($status === SeoProjectRun::STATUS_STOPPING ? 'cancelled' : 'failed_stale_runtime_repair');
                        $engine['intentional_unvisited_pending'] = true;
                        $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] = $engine;
                        $payload['settings'] = $settings;
                        $payload['status'] = $status === SeoProjectRun::STATUS_STOPPING
                            ? SeoProjectRun::STATUS_CANCELLED
                            : SeoProjectRun::STATUS_FAILED;
                        $payload['finished_at'] = $locked->finished_at ?? now();
                    }

                    $locked->update($payload);
                });

                RuntimeLogger::info('content_project.stale_runtime_repaired', [
                    'project_id' => $projectId,
                    'run_id' => (int) $run->id,
                    'reason' => $reason,
                ]);
            }

            $run->refresh();
            $changes[] = [
                'action' => 'repair_stale_runtime',
                'applied' => $apply,
                'reason' => $reason,
                'before' => $before,
                'after' => [
                    'run_id' => (int) $run->id,
                    'status' => (string) $run->status,
                    'clear_dispatch' => $clearDispatch,
                    'finalize_run' => $finalizeRun,
                ],
            ];
        }

        return $changes;
    }

    private function syncLatestRuns(int $projectId): void
    {
        $runs = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->limit(3)
            ->get();
        foreach ($runs as $run) {
            if ($run instanceof SeoProjectRun) {
                $this->runItemService->syncMirrorAndCounters($run, false);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $latestRunItem
     */
    private function isFalseSuccess(SeoProjectTask $task, bool $hasBody, ?array $latestRunItem = null): bool
    {
        if ($hasBody) {
            return false;
        }

        $status = strtolower(trim((string) ($task->status ?? '')));
        if (in_array($status, [
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_REVIEWING,
            'completed',
            'reviewing',
            'success',
            'generated',
        ], true)) {
            return true;
        }

        $exec = strtolower(trim((string) ($latestRunItem['status'] ?? '')));

        return in_array($exec, [
            SeoProjectRunItemStatus::Success->value,
            'completed',
            SeoProjectRunItemStatus::Skipped->value,
        ], true);
    }

    private function articleHasBody(?SeoArticle $article): bool
    {
        if (! $article instanceof SeoArticle) {
            return false;
        }

        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return true;
        }

        // Some legacy rows mirrored body into `content` attribute when present.
        if ($article->offsetExists('content')) {
            return trim((string) ($article->getAttribute('content') ?? '')) !== '';
        }

        return false;
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>>
     */
    private function latestRunItemsByTaskIds(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $items = SeoProjectRunItem::query()
            ->whereIn('task_id', $taskIds)
            ->articleExecution()
            ->orderByDesc('id')
            ->get(['id', 'task_id', 'run_id', 'status', 'message', 'error_message']);

        $map = [];
        foreach ($items as $item) {
            $tid = (int) $item->task_id;
            if ($tid <= 0 || isset($map[$tid])) {
                continue;
            }
            $map[$tid] = [
                'id' => (int) $item->id,
                'run_id' => (int) $item->run_id,
                'status' => (string) $item->status,
                'message' => $item->message !== null ? (string) $item->message : null,
                'error_message' => $item->error_message !== null ? (string) $item->error_message : null,
            ];
        }

        return $map;
    }
}
