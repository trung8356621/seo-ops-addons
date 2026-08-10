<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Illuminate\Support\Facades\DB;

/**
 * Legacy multi-run consolidator.
 *
 * @deprecated Phase 3B+ — không dùng cho write path run mới.
 * Phase 3C3: mark consolidated_into_run_id — không hard-delete run.
 * Relink run items sang keeper qua SeoProjectRunItemMergeService; UI list notConsolidatedRuns().
 * Input qua SeoProjectRunItemsReader (DB XOR legacy JSON).
 */
final class SeoProjectRunConsolidationService
{
    public function __construct(
        private readonly SeoProjectRunItemsReader $runItemsReader,
        private readonly SeoProjectRunItemMergeService $runItemMerger,
    ) {}

    public function hasRunnablePendingTasks(SeoProject $project): bool
    {
        $this->syncObsoleteTaskStatuses($project);

        $successfulKeys = $this->successfulIdentityKeysFromRuns($project);

        foreach (
            $project->tasks()
                ->where('status', SeoProjectTask::STATUS_PENDING)
                ->planned()
                ->get() as $task
        ) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            if (! isset($successfulKeys[$this->taskIdentityKeyFromTask($task)])) {
                return true;
            }
        }

        return false;
    }

    public function isProjectFullyCompleted(SeoProject $project): bool
    {
        $this->syncObsoleteTaskStatuses($project);

        $identityKeys = $this->uniqueTaskIdentityKeys($project);
        if ($identityKeys === []) {
            return false;
        }

        $successfulKeys = $this->successfulIdentityKeysFromRuns($project);

        foreach ($identityKeys as $key) {
            if (! isset($successfulKeys[$key])) {
                return false;
            }
        }

        return ! $this->hasRunnablePendingTasks($project);
    }

    public function hasSuccessfulRunItem(SeoProject $project, array $item): bool
    {
        $key = $this->itemIdentityKey($item);

        return isset($this->successfulIdentityKeysFromRuns($project)[$key]);
    }

    public function maybeConsolidate(SeoProject $project): ?SeoProjectRun
    {
        $this->syncObsoleteTaskStatuses($project);
        $project->refresh();

        if (! $this->isProjectFullyCompleted($project)) {
            return null;
        }

        $runs = $project->notConsolidatedRuns()->orderBy('id')->get();
        if ($runs->count() <= 1) {
            $single = $runs->first();
            if ($single instanceof SeoProjectRun) {
                $this->normalizeKeeperRun(
                    $single,
                    $this->collectMergedItems($runs),
                    $single->started_at,
                );

                return $single->fresh();
            }

            return null;
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($runs): SeoProjectRun {
            $mergedItems = $this->collectMergedItems($runs);
            /** @var SeoProjectRun $keeper */
            $keeper = $runs->last();
            $removedIds = $runs
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0 && $id !== (int) $keeper->id)
                ->values()
                ->all();

            $this->normalizeKeeperRun($keeper, $mergedItems, $runs->min('started_at'));

            if ($removedIds !== []) {
                SeoPromptResultLink::query()
                    ->whereIn('project_run_id', $removedIds)
                    ->update(['project_run_id' => (int) $keeper->id]);

                foreach ($removedIds as $fromRunId) {
                    $this->runItemMerger->relinkRun((int) $fromRunId, (int) $keeper->id);
                }

                // Phase 3C3: không hard-delete run (CASCADE sẽ mất run items).
                // Mark consolidated; items đã relink sang keeper.
                SeoProjectRun::query()
                    ->whereIn('id', $removedIds)
                    ->update([
                        'consolidated_into_run_id' => (int) $keeper->id,
                        'consolidated_at' => now(),
                    ]);
            }

            $this->syncArticleRunMeta($keeper, $mergedItems);

            return $keeper->fresh() ?? $keeper;
        });
    }

    private function syncObsoleteTaskStatuses(SeoProject $project): void
    {
        $successfulKeys = $this->successfulIdentityKeysFromRuns($project);

        if ($successfulKeys === []) {
            return;
        }

        $obsoleteStatuses = [
            SeoProjectTask::STATUS_FAILED,
            SeoProjectTask::STATUS_PENDING,
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_REVIEWING,
        ];

        $successfulArticleIds = $this->successfulArticleIdsByIdentity($project);

        foreach ($project->tasks()->whereIn('status', $obsoleteStatuses)->get() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $identityKey = $this->taskIdentityKeyFromTask($task);
            if (! isset($successfulKeys[$identityKey])) {
                continue;
            }

            $payload = [
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'completed_at' => $task->completed_at ?? now(),
            ];

            $resolvedArticleId = (int) ($successfulArticleIds[$identityKey] ?? 0);
            if ($resolvedArticleId > 0 && (int) ($task->article_id ?? 0) !== $resolvedArticleId) {
                SeoProjectTask::query()
                    ->where('article_id', $resolvedArticleId)
                    ->whereKeyNot((int) $task->id)
                    ->update(['article_id' => null]);

                $payload['article_id'] = $resolvedArticleId;
                if ($task->connected_at === null) {
                    $payload['connected_at'] = now();
                }
            }

            $task->update($payload);
        }
    }

    /**
     * @return array<string, true>
     */
    private function successfulIdentityKeysFromRuns(SeoProject $project): array
    {
        $runs = $project->relationLoaded('runs')
            ? $project->runs
            : $project->runs()->orderBy('id')->get();

        $keys = [];

        foreach ($this->collectMergedItems($runs) as $item) {
            if ((string) ($item['status'] ?? '') === 'success') {
                $keys[$this->itemIdentityKey($item)] = true;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, int> identity key => article_id
     */
    private function successfulArticleIdsByIdentity(SeoProject $project): array
    {
        $runs = $project->relationLoaded('runs')
            ? $project->runs
            : $project->runs()->orderBy('id')->get();

        /** @var array<string, int> $map */
        $map = [];

        foreach ($this->collectMergedItems($runs) as $item) {
            if ((string) ($item['status'] ?? '') !== 'success') {
                continue;
            }

            $key = $this->itemIdentityKey($item);
            $articleId = (int) ($item['article_id'] ?? 0);
            if ($key === '' || $articleId <= 0) {
                continue;
            }

            if (! isset($map[$key])) {
                $map[$key] = $articleId;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function uniqueTaskIdentityKeys(SeoProject $project): array
    {
        $keys = [];

        $tasks = $project->relationLoaded('tasks')
            ? $project->tasks
            : $project->tasks()->active()->get();

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            if ($task->archived_at !== null) {
                continue;
            }

            $keys[$this->taskIdentityKeyFromTask($task)] = true;
        }

        return array_keys($keys);
    }

    private function taskIdentityKeyFromTask(SeoProjectTask $task): string
    {
        return $this->itemIdentityKey([
            'type' => $task->type,
            'post_type' => $task->post_type,
            'source_content' => $task->source_content,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectRun>  $runs
     * @return list<array<string, mixed>>
     */
    private function collectMergedItems($runs): array
    {
        /** @var array<string, array{priority: int, item: array<string, mixed>}> $bucket */
        $bucket = [];

        foreach ($runs as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }

            // XOR theo run: DB items nếu có, không thì legacy JSON — không bao giờ union.
            $items = $this->runItemsReader->forRunAsArrays($run);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $item['source_run_id'] = (int) $run->id;
                $key = $this->mergeBucketKey($item);
                $priority = $this->itemPriority($item, (int) $run->id);

                if (! isset($bucket[$key]) || $priority >= $bucket[$key]['priority']) {
                    $bucket[$key] = [
                        'priority' => $priority,
                        'item' => $item,
                    ];
                }
            }
        }

        return array_values(array_map(
            static fn (array $entry): array => $entry['item'],
            $bucket,
        ));
    }

    /**
     * Bucket key: ưu tiên task_id (không collapse hai task khác ID cùng source).
     *
     * @param  array<string, mixed>  $item
     */
    private function mergeBucketKey(array $item): string
    {
        $runItemId = (int) ($item['run_item_id'] ?? 0);
        if ($runItemId > 0) {
            // Giữ identity theo run_item khi gộp nhiều run; ưu tiên bản mới hơn qua priority.
            $taskId = (int) ($item['task_id'] ?? 0);
            $action = trim((string) ($item['action'] ?? ''));
            if ($taskId > 0) {
                return 'task:'.$taskId.($action !== '' ? '|'.$action : '');
            }

            return 'ri:'.$runItemId;
        }

        $taskId = (int) ($item['task_id'] ?? 0);
        if ($taskId > 0) {
            $action = trim((string) ($item['action'] ?? ''));

            return 'task:'.$taskId.($action !== '' ? '|'.$action : '');
        }

        return 'legacy:'.$this->itemIdentityKey($item);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function normalizeKeeperRun(SeoProjectRun $keeper, array $items, mixed $startedAt = null): void
    {
        $succeeded = collect($items)->where('status', 'success')->count();
        $failed = collect($items)->where('status', 'failed')->count();

        // Phase 3C3: không ghi full JSON item list — chỉ counters + meta.
        $keeper->update([
            'mode' => SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => count($items),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'started_at' => $startedAt ?? $keeper->started_at,
            'finished_at' => $keeper->finished_at ?? now(),
            'consolidated_into_run_id' => null,
            'consolidated_at' => null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncArticleRunMeta(SeoProjectRun $keeper, array $items): void
    {
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') !== 'success') {
                continue;
            }

            $articleId = (int) ($item['article_id'] ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'content_project_run'],
                [
                    'meta_value' => json_encode([
                        'run_id' => (int) $keeper->id,
                        'project_id' => (int) $keeper->project_id,
                        'task_id' => (int) ($item['task_id'] ?? 0),
                        'ran_at' => ($keeper->finished_at ?? now())->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE),
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemIdentityKey(array $item): string
    {
        $type = (string) ($item['type'] ?? SeoProjectTask::TYPE_CREATE);
        $source = mb_strtolower(trim((string) ($item['source_content'] ?? '')));
        $postType = SeoProjectTask::isNewArticleType($type)
            ? SeoProjectTask::normalizePostType($item['post_type'] ?? null)
            : '';

        return $type.'|'.$postType.'|'.$source;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemPriority(array $item, int $runId): int
    {
        $statusScore = (string) ($item['status'] ?? '') === 'success' ? 100 : 0;

        return $statusScore + min(99, max(0, $runId));
    }
}
