<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-off + re-runnable repair cho dữ liệu cũ trước khi `ArticleReviewService::performAction(archive)`
 * tự detach task khỏi Content Project (xem `ArticleReviewService::archiveAndDetachProjectTasks()`):
 * bài viết đã archived nhưng `seo_project_tasks.archived_at` vẫn null → project "Total items"/xóa
 * project vẫn đếm nhầm task này (`SeoProjectTaskMoveService::deleteProject()` chỉ nhìn `tasks()->active()`).
 *
 * Idempotent: chạy lại lần 2 sẽ không tìm thấy task nào (mọi task active đã bị archive ở lần 1).
 */
final class RepairArchivedArticleActiveTasksCommand extends Command
{
    protected $signature = 'seo:repair-archived-article-active-tasks
        {--dry-run : Chỉ in kế hoạch, không ghi DB (mặc định khi không truyền --apply)}
        {--apply : Thực sự archive các task active còn sót lại}';

    protected $description = 'Archive các seo_project_tasks còn active của bài viết đã review_status=archived (data cũ trước khi ArticleReviewService tự detach).';

    public function handle(SeoProjectTaskLifecycleService $taskLifecycle): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;

        $this->info($dryRun ? 'DRY-RUN — không ghi DB.' : 'APPLY — sẽ archive task active còn sót.');

        $archivedArticleIds = $this->resolveArchivedArticleIds();

        if ($archivedArticleIds->isEmpty()) {
            $this->info('Không có bài viết nào ở trạng thái archived.');

            return self::SUCCESS;
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('article_id', $archivedArticleIds)
            ->active()
            ->orderBy('article_id')
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('Không có task active nào cần archive — dữ liệu sạch.');

            return self::SUCCESS;
        }

        $articles = SeoArticle::query()
            ->whereIn('id', $tasks->pluck('article_id')->unique())
            ->get(['id', 'title', 'review_status'])
            ->keyBy('id');

        $rows = [];
        foreach ($tasks as $task) {
            /** @var SeoArticle|null $article */
            $article = $articles->get((int) $task->article_id);

            $rows[] = [
                (int) $task->article_id,
                $article?->title ?? '(không tìm thấy)',
                (string) ($article?->review_status ?? ''),
                $task->project_id !== null ? (int) $task->project_id : '-',
                (int) $task->id,
                (string) $task->status,
                'archive_task',
            ];
        }

        $this->table(
            ['article_id', 'title', 'review_status', 'project_id', 'task_id', 'task_status', 'planned_action'],
            $rows,
        );

        $repaired = 0;

        if (! $dryRun) {
            foreach ($tasks as $task) {
                $taskLifecycle->archive($task, null, ['from_repair_command' => true]);
                $repaired++;
            }
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['articles_archived_scanned', (string) $archivedArticleIds->count()],
            ['active_tasks_found', (string) $tasks->count()],
            ['tasks_archived', $dryRun ? '0' : (string) $repaired],
            ['tasks_planned', $dryRun ? (string) $tasks->count() : '0'],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, int>
     */
    private function resolveArchivedArticleIds(): Collection
    {
        return SeoArticle::query()
            ->where(function ($query): void {
                $query
                    ->where('review_status', ArticleReviewStatus::Archived->value)
                    ->orWhere(function ($nested): void {
                        $nested
                            ->whereExists(function ($sub): void {
                                $sub->selectRaw('1')
                                    ->from('seo_content_archive_items')
                                    ->whereColumn('seo_content_archive_items.article_id', 'articles.id');
                            })
                            ->where(function ($statusNested): void {
                                $statusNested
                                    ->whereNull('review_status')
                                    ->orWhere('review_status', ArticleReviewStatus::Archived->value);
                            });
                    });
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();
    }
}
