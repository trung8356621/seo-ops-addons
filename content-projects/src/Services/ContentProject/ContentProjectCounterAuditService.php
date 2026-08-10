<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * So sánh Run OK vs list “Completed” (status=completed) — không mutate data.
 */
final class ContentProjectCounterAuditService
{
    /**
     * @return array{
     *     project_id: int,
     *     total_active_items: int,
     *     status_completed_count: int,
     *     latest_run_id: int|null,
     *     latest_run_total: int,
     *     latest_run_succeeded: int,
     *     latest_run_failed: int,
     *     latest_run_status: string|null,
     *     mismatch_items: list<array<string, mixed>>,
     *     root_cause: string,
     * }
     */
    public function audit(SeoProject $project): array
    {
        $projectId = (int) $project->getKey();
        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->active()
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->with(['article:id,title,review_status,status,last_ai_content_at'])
            ->orderBy('id')
            ->get();

        $completedCount = $tasks->where('status', SeoProjectTask::STATUS_COMPLETED)->count();

        $latestRun = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->first();

        $successTaskIds = $this->successfulTaskIds($projectId);

        $mismatches = [];
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $tid = (int) $task->id;
            $status = (string) $task->status;
            $hasSuccess = isset($successTaskIds[$tid]);
            $isCompleted = $status === SeoProjectTask::STATUS_COMPLETED;

            if ($hasSuccess && ! $isCompleted) {
                $mismatches[] = [
                    'task_id' => $tid,
                    'keyword' => $task->keyword !== null ? (string) $task->keyword : ($task->title !== null ? (string) $task->title : null),
                    'type' => (string) ($task->type ?? ''),
                    'current_status' => $status,
                    'run_success' => true,
                    'article_id' => (int) ($task->article_id ?? 0) ?: null,
                    'article_title' => $task->article?->title !== null ? (string) $task->article->title : null,
                    'why' => 'run_ok_but_task_status_not_completed',
                ];
            }
        }

        return [
            'project_id' => $projectId,
            'total_active_items' => $tasks->count(),
            'status_completed_count' => $completedCount,
            'latest_run_id' => $latestRun instanceof SeoProjectRun ? (int) $latestRun->id : null,
            'latest_run_total' => $latestRun instanceof SeoProjectRun ? (int) ($latestRun->total ?? 0) : 0,
            'latest_run_succeeded' => $latestRun instanceof SeoProjectRun ? (int) ($latestRun->succeeded ?? 0) : 0,
            'latest_run_failed' => $latestRun instanceof SeoProjectRun ? (int) ($latestRun->failed ?? 0) : 0,
            'latest_run_status' => $latestRun instanceof SeoProjectRun ? (string) $latestRun->status : null,
            'mismatch_items' => $mismatches,
            'root_cause' => 'list_completed_counts_task_status_completed_only_while_run_ok_counts_successful_executions',
        ];
    }

    /**
     * @return array<int, true>
     */
    private function successfulTaskIds(int $projectId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
            return [];
        }

        $ids = SeoProjectRunItem::query()
            ->whereIn('run_id', SeoProjectRun::query()->where('project_id', $projectId)->select('id'))
            ->whereIn('status', [SeoProjectRunItemStatus::Success->value, 'completed'])
            ->whereNotNull('task_id')
            ->pluck('task_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $map = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $map[$id] = true;
            }
        }

        return $map;
    }
}
