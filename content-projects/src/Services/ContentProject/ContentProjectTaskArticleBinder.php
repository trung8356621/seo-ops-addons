<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Illuminate\Support\Facades\DB;

/**
 * Direct project task ↔ article attach/complete (legacy migration bridge bypass).
 */
final class ContentProjectTaskArticleBinder
{
    public function __construct(
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
    ) {}

    public function attachArticle(SeoProjectTask $task, int $articleId, ?int $actorId = null, ?int $siteId = null): void
    {
        unset($actorId);

        $taskId = (int) $task->id;
        $task->loadMissing('project');
        $canonicalSiteId = (int) ($task->site_id ?? 0);
        if ($canonicalSiteId <= 0) {
            $canonicalSiteId = (int) ($siteId ?? $task->project?->site_id ?? 0);
        }
        if ($canonicalSiteId <= 0
            || LocalArticleAssociationGuard::resolveLocalArticleId($articleId, $canonicalSiteId) === null
        ) {
            throw new \InvalidArgumentException(
                'Article must belong to the same site as this item (task.site_id).',
            );
        }

        $alreadyAttached = (int) ($task->article_id ?? 0) === $articleId && $articleId > 0;

        if (! $alreadyAttached) {
            DB::connection($task->getConnectionName())->transaction(function () use ($taskId, $articleId): void {
                SeoProjectTask::query()
                    ->where('article_id', $articleId)
                    ->whereKeyNot($taskId)
                    ->update(['article_id' => null]);

                $payload = ['article_id' => $articleId];
                $fresh = SeoProjectTask::query()->find($taskId);
                if ($fresh instanceof SeoProjectTask && $fresh->connected_at === null) {
                    $payload['connected_at'] = now();
                }
                SeoProjectTask::query()->whereKey($taskId)->update($payload);
            });
        }

        $task->refresh();
    }

    public function markCompleted(
        SeoProjectTask $task,
        int $articleId,
        ?int $actorId = null,
        ?int $siteId = null,
        string $origin = 'migration.project_task_complete',
    ): void {
        unset($actorId, $siteId, $origin);

        $taskId = (int) $task->id;
        $alreadyCompleted = (string) ($task->status ?? '') === SeoProjectTask::STATUS_COMPLETED
            && (int) ($task->article_id ?? 0) === ($articleId > 0 ? $articleId : (int) ($task->article_id ?? 0));

        if (! $alreadyCompleted) {
            DB::connection($task->getConnectionName())->transaction(function () use ($task, $taskId, $articleId): void {
                if ($articleId > 0) {
                    SeoProjectTask::query()
                        ->where('article_id', $articleId)
                        ->whereKeyNot($taskId)
                        ->update(['article_id' => null]);
                }

                $payload = [
                    'status' => SeoProjectTask::STATUS_COMPLETED,
                    'article_id' => $articleId > 0 ? $articleId : null,
                ];
                if ($articleId > 0 && $task->connected_at === null) {
                    $payload['connected_at'] = now();
                }
                if ($task->completed_at === null) {
                    $payload['completed_at'] = now();
                }
                SeoProjectTask::query()->whereKey($taskId)->update($payload);

                if ($articleId > 0) {
                    $task->loadMissing('project');
                    if ($task->project instanceof SeoProject) {
                        $this->articleOwnerSync->assignWriterToArticle($task->project, $articleId);
                    }
                }
            });
        }

        $task->refresh();
    }
}
