<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Console\Command;

/**
 * Phase 3C1 — diagnose only, không auto-repair production.
 */
final class DiagnoseContentProjectArchiveCommand extends Command
{
    protected $signature = 'content-project:diagnose-archive
        {--site-id= : Lọc theo site_id}
        {--limit=50 : Số dòng mẫu mỗi nhóm}';

    protected $description = 'Diagnose inconsistency giữa task lifecycle và seo_content_archive_items mirror.';

    public function handle(): int
    {
        $siteId = (int) ($this->option('site-id') ?? 0);
        $limit = max(1, (int) ($this->option('limit') ?? 50));

        $stats = [
            'warehouse_missing_task_id' => 0,
            'warehouse_points_active_task' => 0,
            'task_archived_missing_warehouse' => 0,
            'article_archived_task_active' => 0,
            'task_archived_article_flag_null' => 0,
            'task_active_article_flag_set' => 0,
            'duplicate_warehouse_by_article' => 0,
            'warehouse_missing_article' => 0,
        ];

        $warehouseQuery = SeoContentArchiveItem::query()->orderBy('id');
        if ($siteId > 0) {
            $warehouseQuery->where('site_id', $siteId);
        }

        foreach ($warehouseQuery->cursor() as $item) {
            if (! $item instanceof SeoContentArchiveItem) {
                continue;
            }

            $taskId = (int) ($item->task_id ?? 0);
            $articleId = (int) ($item->article_id ?? 0);

            if ($taskId <= 0) {
                $stats['warehouse_missing_task_id']++;
            } else {
                $task = SeoProjectTask::withTrashed()->find($taskId);
                if ($task instanceof SeoProjectTask
                    && $task->deleted_at === null
                    && $task->archived_at === null
                ) {
                    $stats['warehouse_points_active_task']++;
                    $this->line(sprintf(
                        '[%s] warehouse#%d task#%d still active',
                        ContentProjectErrorCode::ArchiveStateMismatch->value,
                        (int) $item->id,
                        $taskId,
                    ));
                }
            }

            if ($articleId <= 0 || ! SeoArticle::query()->whereKey($articleId)->exists()) {
                $stats['warehouse_missing_article']++;
            }
        }

        $archivedTasks = SeoProjectTask::query()->archived()->orderBy('id');
        if ($siteId > 0) {
            $archivedTasks->where('site_id', $siteId);
        }

        foreach ($archivedTasks->cursor() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $hasWarehouse = SeoContentArchiveItem::query()
                ->where(function ($query) use ($task, $articleId): void {
                    $query->where('task_id', (int) $task->id)
                        ->orWhere('article_id', $articleId);
                })
                ->exists();

            if (! $hasWarehouse) {
                $stats['task_archived_missing_warehouse']++;
            }

            $hasArticleMirror = SeoContentArchiveItem::query()
                ->where('article_id', $articleId)
                ->exists();
            if (! $hasArticleMirror) {
                $stats['task_archived_article_flag_null']++;
            }
        }

        $flaggedArticles = SeoArticle::query()
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('seo_content_archive_items')
                    ->whereColumn('seo_content_archive_items.article_id', 'articles.id');
            })
            ->orderBy('id');
        if ($siteId > 0) {
            $flaggedArticles->where('site_id', $siteId);
        }

        foreach ($flaggedArticles->cursor() as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $activeLinked = SeoProjectTask::query()
                ->active()
                ->where('article_id', (int) $article->id)
                ->exists();

            if ($activeLinked) {
                $stats['article_archived_task_active']++;
                $stats['task_active_article_flag_set']++;
            }
        }

        $dupQuery = SeoContentArchiveItem::query()
            ->selectRaw('article_id, COUNT(*) as c')
            ->groupBy('article_id')
            ->havingRaw('COUNT(*) > 1');
        if ($siteId > 0) {
            $dupQuery->where('site_id', $siteId);
        }
        $stats['duplicate_warehouse_by_article'] = (int) $dupQuery->get()->count();

        $this->newLine();
        $this->table(
            ['Check', 'Count'],
            collect($stats)->map(static fn (int $v, string $k): array => [$k, (string) $v])->values()->all(),
        );

        $this->comment('Sample limit option ignored for full scan counters; use logs above for mismatch samples (cap '.$limit.').');

        return self::SUCCESS;
    }
}
