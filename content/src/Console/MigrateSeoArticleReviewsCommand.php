<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Console;

use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleReview;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `seo_article_reviews` từ dữ liệu legacy:
 * - seo_project_archives / seo_project_archive_items → action archive.
 * - articles đã liên kết project APPROVED nhưng chưa duyệt → review_status pending_review.
 *
 * Idempotent: chạy lại nhiều lần không tạo trùng bản ghi.
 */
final class MigrateSeoArticleReviewsCommand extends Command
{
    protected $signature = 'seo:migrate-article-reviews {--dry-run : Chỉ thống kê, không ghi DB}';

    protected $description = 'Backfill seo_article_reviews từ seo_project_archives/items và trạng thái pending_review.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $connection = SeoContentAiServiceProvider::DB_CONNECTION;

        $this->info('Migrate seo_article_reviews '.($dryRun ? '(dry-run)' : ''));

        $stats = [
            'archive_items_scanned' => 0,
            'archive_reviews_created' => 0,
            'archive_reviews_skipped_existing' => 0,
            'archive_items_missing_article' => 0,
            'pending_review_candidates' => 0,
            'pending_review_updated' => 0,
        ];

        $this->migrateArchiveHistory($dryRun, $stats);
        $this->backfillPendingReview($dryRun, $connection, $stats);

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(static fn (int $v, string $k): array => [$k, (string) $v])->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function migrateArchiveHistory(bool $dryRun, array &$stats): void
    {
        SeoProjectArchiveItem::query()
            ->with('archive')
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($dryRun, &$stats): void {
                foreach ($items as $item) {
                    if (! $item instanceof SeoProjectArchiveItem) {
                        continue;
                    }

                    $stats['archive_items_scanned']++;

                    $archive = $item->archive;
                    $articleId = (int) ($item->article_id ?? 0);
                    if ($articleId <= 0 || ! $archive instanceof SeoProjectArchive) {
                        $stats['archive_items_missing_article']++;

                        continue;
                    }

                    $reviewerId = (int) ($archive->archived_by ?? 0);
                    $createdAt = $item->created_at ?? $archive->created_at ?? now();

                    $alreadyMigrated = SeoArticleReview::query()
                        ->where('article_id', $articleId)
                        ->where('action_type', ArticleReviewActionType::Archive->value)
                        ->where('reviewer_id', $reviewerId)
                        ->where('created_at', $createdAt)
                        ->exists();

                    if ($alreadyMigrated) {
                        $stats['archive_reviews_skipped_existing']++;

                        continue;
                    }

                    if ($dryRun) {
                        $stats['archive_reviews_created']++;

                        continue;
                    }

                    SeoArticleReview::query()->create([
                        'article_id' => $articleId,
                        'action_type' => ArticleReviewActionType::Archive->value,
                        'from_status' => ArticleReviewStatus::Approved->value,
                        'to_status' => ArticleReviewStatus::Archived->value,
                        'reviewer_id' => $reviewerId,
                        'reviewer_role' => null,
                        'note' => $archive->note,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                    $stats['archive_reviews_created']++;
                }
            });
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function backfillPendingReview(bool $dryRun, string $connection, array &$stats): void
    {
        $approvedProjectIds = SeoProject::query()
            ->where('status', SeoProject::STATUS_APPROVED)
            ->pluck('id');

        if ($approvedProjectIds->isEmpty()) {
            return;
        }

        $articleIds = SeoProjectTask::query()
            ->whereIn('project_id', $approvedProjectIds)
            ->whereNotNull('article_id')
            ->pluck('article_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($articleIds->isEmpty()) {
            return;
        }

        $candidates = SeoArticle::query()
            ->whereIn('id', $articleIds)
            ->where('review_status', ArticleReviewStatus::Draft->value)
            ->notContentArchived()
            ->get(['id']);

        $stats['pending_review_candidates'] = $candidates->count();

        if ($dryRun || $candidates->isEmpty()) {
            return;
        }

        $updated = DB::connection($connection)->table('articles')
            ->whereIn('id', $candidates->pluck('id'))
            ->update(['review_status' => ArticleReviewStatus::PendingReview->value]);

        $stats['pending_review_updated'] = $updated;
    }
}
