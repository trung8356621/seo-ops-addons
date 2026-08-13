<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskEventRecorder;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Detect / clear task.article_id (and run_item mirrors) pointing at deleted articles.
 * Used when «Chạy lại» on create-type items after backup/data loss.
 */
final class ContentProjectStaleArticleLinkService
{
    /**
     * Create-type item whose article_id points to a non-existent article.
     */
    public function isStaleMissingCreateArticle(SeoProjectTask $task): bool
    {
        if (! SeoProjectTask::isNewArticleType($task->type)) {
            return false;
        }

        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId <= 0) {
            return $this->hasStaleRunItemArticleId((int) $task->getKey());
        }

        return ! SeoArticle::query()->whereKey($articleId)->exists();
    }

    /**
     * Clear stale article links so article.create can start from scratch.
     *
     * @return array{cleared: bool, previous_article_id: int|null}
     */
    public function clearForFreshCreate(SeoProjectTask $task): array
    {
        $taskId = (int) $task->getKey();
        $previous = (int) ($task->article_id ?? 0);
        $previousId = $previous > 0 ? $previous : null;

        if (! $this->isStaleMissingCreateArticle($task)) {
            return ['cleared' => false, 'previous_article_id' => $previousId];
        }

        try {
            DB::connection('omi_seo_ai')->transaction(function () use ($taskId, $previousId): void {
                SeoProjectTask::query()->whereKey($taskId)->update([
                    'article_id' => null,
                ]);

                if (Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
                    SeoProjectRunItem::query()
                        ->where('task_id', $taskId)
                        ->whereNotNull('article_id')
                        ->where('article_id', '>', 0)
                        ->update(['article_id' => null]);
                }
            });
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.clear_stale_article_link',
                'task_id' => $taskId,
                'previous_article_id' => $previousId,
            ]);

            return ['cleared' => false, 'previous_article_id' => $previousId];
        }

        $task->refresh();

        try {
            app(SeoProjectTaskEventRecorder::class)->record(
                $task,
                SeoProjectTaskEventType::ArticleRelationMissing,
                (string) $task->status,
                (string) $task->status,
                [
                    'cleared_for_fresh_create' => true,
                    'previous_article_id' => $previousId,
                ],
            );
        } catch (Throwable) {
            // Event log is best-effort.
        }

        RuntimeLogger::info('content_project.stale_article_link_cleared', [
            'task_id' => $taskId,
            'previous_article_id' => $previousId,
        ]);

        return ['cleared' => true, 'previous_article_id' => $previousId];
    }

    private function hasStaleRunItemArticleId(int $taskId): bool
    {
        if ($taskId <= 0) {
            return false;
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $itemArticleId = (int) (SeoProjectRunItem::query()
            ->where('task_id', $taskId)
            ->whereNotNull('article_id')
            ->where('article_id', '>', 0)
            ->orderByDesc('id')
            ->value('article_id') ?? 0);

        if ($itemArticleId <= 0) {
            return false;
        }

        return ! SeoArticle::query()->whereKey($itemArticleId)->exists();
    }
}
