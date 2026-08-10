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
 * Idempotent hydrate từ execution lịch sử — không AI, không dispatch job.
 * Không ghi đè reviewing / completed / archived / cancelled.
 */
final class ContentProjectLegacyExecutionHydrateService
{
    /**
     * @return array{
     *     project_id: int,
     *     dry_run: bool,
     *     scanned: int,
     *     would_update: int,
     *     updated: int,
     *     skipped: int,
     *     rows: list<array<string, mixed>>,
     * }
     */
    public function hydrate(SeoProject $project, bool $dryRun = true): array
    {
        $projectId = (int) $project->getKey();
        $successByTask = $this->latestSuccessfulExecutionByTask($projectId);

        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->active()
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->orderBy('id')
            ->get();

        $rows = [];
        $wouldUpdate = 0;
        $updated = 0;
        $skipped = 0;

        $apply = function () use (
            $tasks,
            $successByTask,
            $dryRun,
            &$rows,
            &$wouldUpdate,
            &$updated,
            &$skipped
        ): void {
            foreach ($tasks as $task) {
                if (! $task instanceof SeoProjectTask) {
                    continue;
                }

                $tid = (int) $task->id;
                $status = (string) $task->status;
                $success = $successByTask[$tid] ?? null;

                if ($success === null) {
                    $skipped++;
                    $rows[] = [
                        'task_id' => $tid,
                        'action' => 'skip',
                        'reason' => 'no_successful_execution',
                        'from_status' => $status,
                    ];
                    continue;
                }

                if (in_array($status, [
                    SeoProjectTask::STATUS_REVIEWING,
                    SeoProjectTask::STATUS_COMPLETED,
                    SeoProjectTask::STATUS_ARCHIVED,
                    SeoProjectTask::STATUS_CANCELLED,
                ], true)) {
                    $skipped++;
                    $rows[] = [
                        'task_id' => $tid,
                        'action' => 'skip',
                        'reason' => 'manual_or_lifecycle_status_preserved',
                        'from_status' => $status,
                        'article_id' => $success['article_id'],
                    ];
                    continue;
                }

                if (! in_array($status, [
                    SeoProjectTask::STATUS_PENDING,
                    SeoProjectTask::STATUS_WRITING,
                    SeoProjectTask::STATUS_FAILED,
                ], true)) {
                    $skipped++;
                    $rows[] = [
                        'task_id' => $tid,
                        'action' => 'skip',
                        'reason' => 'unexpected_status',
                        'from_status' => $status,
                    ];
                    continue;
                }

                $payload = [
                    'status' => SeoProjectTask::STATUS_COMPLETED,
                ];
                $articleId = (int) ($success['article_id'] ?? 0);
                if ($articleId > 0 && (int) ($task->article_id ?? 0) !== $articleId) {
                    $payload['article_id'] = $articleId;
                    if ($task->connected_at === null) {
                        $payload['connected_at'] = now();
                    }
                }
                if ($task->completed_at === null) {
                    $payload['completed_at'] = now();
                }

                $wouldUpdate++;
                $rows[] = [
                    'task_id' => $tid,
                    'action' => $dryRun ? 'would_update' : 'updated',
                    'reason' => 'hydrate_from_successful_execution',
                    'from_status' => $status,
                    'to_status' => SeoProjectTask::STATUS_COMPLETED,
                    'article_id' => $articleId > 0 ? $articleId : (int) ($task->article_id ?? 0) ?: null,
                    'run_item_id' => $success['run_item_id'],
                ];

                if (! $dryRun) {
                    SeoProjectTask::query()->whereKey($tid)->update($payload);
                    $updated++;
                }
            }
        };

        if ($dryRun) {
            $apply();
        } else {
            DB::connection('omi_seo_ai')->transaction(static function () use ($apply): void {
                $apply();
            });
        }

        return [
            'project_id' => $projectId,
            'dry_run' => $dryRun,
            'scanned' => $tasks->count(),
            'would_update' => $wouldUpdate,
            'updated' => $updated,
            'skipped' => $skipped,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array{run_item_id: int, article_id: int|null}>
     */
    private function latestSuccessfulExecutionByTask(int $projectId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
            return [];
        }

        $items = SeoProjectRunItem::query()
            ->whereIn('run_id', SeoProjectRun::query()->where('project_id', $projectId)->select('id'))
            ->whereIn('status', [SeoProjectRunItemStatus::Success->value, 'completed'])
            ->whereNotNull('task_id')
            ->orderByDesc('id')
            ->get(['id', 'task_id', 'article_id']);

        $map = [];
        foreach ($items as $item) {
            $tid = (int) $item->task_id;
            if ($tid <= 0 || isset($map[$tid])) {
                continue;
            }
            $map[$tid] = [
                'run_item_id' => (int) $item->id,
                'article_id' => (int) ($item->article_id ?? 0) ?: null,
            ];
        }

        return $map;
    }
}
