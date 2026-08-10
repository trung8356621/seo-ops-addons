<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\WordPress\Jobs\DispatchScheduledProductReviewPublishJob;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trace one stuck product review — parent vs child job evidence.
 */
final class ProductReviewsDiagnoseStuckCommand extends Command
{
    protected $signature = 'product-reviews:diagnose-stuck
        {--article= : Article ID}
        {--review= : Review ID}';

    protected $description = 'Diagnose stuck product review schedule/publish pipeline.';

    public function handle(): int
    {
        $reviewId = (int) ($this->option('review') ?? 0);
        $articleId = (int) ($this->option('article') ?? 0);

        $query = ArticleProductReview::query()->orderByDesc('id');
        if ($reviewId > 0) {
            $query->whereKey($reviewId);
        } elseif ($articleId > 0) {
            $query->where('article_id', $articleId);
        } else {
            $query->whereIn('status', [
                ArticleProductReviewStatus::PendingPublish->value,
                ArticleProductReviewStatus::Scheduled->value,
                ArticleProductReviewStatus::FailedDispatch->value,
                ArticleProductReviewStatus::Failed->value,
            ]);
        }

        /** @var ArticleProductReview|null $review */
        $review = $query->first();
        if (! $review instanceof ArticleProductReview) {
            $this->error('No matching review.');

            return self::FAILURE;
        }

        $article = SeoArticle::query()->find((int) $review->article_id);
        $this->table(['field', 'value'], [
            ['review_id', (string) $review->id],
            ['article_id', (string) $review->article_id],
            ['status', $review->status instanceof ArticleProductReviewStatus ? $review->status->value : (string) $review->status],
            ['wp_post_id_review', (string) ($review->wp_post_id ?? '')],
            ['wp_post_id_article', (string) ($article?->wordpressLink?->wp_post_id ?? '')],
            ['wp_comment_id', (string) ($review->wp_comment_id ?? '')],
            ['selected_delay_seconds', (string) ($review->selected_delay_seconds ?? '')],
            ['configured_max_delay_minutes', (string) ($review->configured_max_delay_minutes ?? '')],
            ['scheduled_at', (string) ($review->scheduled_at ?? '')],
            ['last_error_code', (string) ($review->last_error_code ?? '')],
            ['last_error_message', mb_substr((string) ($review->last_error_message ?? ''), 0, 200)],
            ['idempotency_key', (string) ($review->idempotency_key ?? '')],
        ]);

        $step = match (true) {
            $review->status === ArticleProductReviewStatus::PendingPublish
                && (int) ($article?->wordpressLink?->wp_post_id ?? 0) <= 0 => 'A/D: waiting article — should be pending_article',
            $review->status === ArticleProductReviewStatus::PendingPublish => 'E: Rule ran or not — child job NOT scheduled (still pending_publish)',
            $review->status === ArticleProductReviewStatus::FailedDispatch => 'E: dispatch child job failed',
            $review->status === ArticleProductReviewStatus::Scheduled => 'F/G: scheduled — check jobs table for DispatchScheduledProductReviewPublishJob',
            $review->status === ArticleProductReviewStatus::Publishing => 'G/H: WP handler running / finalize',
            $review->status === ArticleProductReviewStatus::Published => 'OK published',
            default => 'Check status manually',
        };
        $this->warn("Stop step: {$step}");

        $jobs = DB::table('jobs')
            ->where('payload', 'like', '%DispatchScheduledProductReviewPublishJob%')
            ->where('payload', 'like', '%"reviewId";i:'.(int) $review->id.'%')
            ->orWhere(function ($q) use ($review): void {
                $q->where('payload', 'like', '%DispatchScheduledProductReviewPublishJob%')
                    ->where('payload', 'like', '%reviewId%'.(int) $review->id.'%');
            })
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'queue', 'attempts', 'available_at', 'created_at']);

        if ($jobs->isEmpty()) {
            $this->line('No matching jobs rows for '.DispatchScheduledProductReviewPublishJob::class.' + review '.$review->id);
        } else {
            $this->table(
                ['job_id', 'queue', 'attempts', 'available_at', 'created_at'],
                $jobs->map(static fn ($j): array => [
                    (string) $j->id,
                    (string) $j->queue,
                    (string) $j->attempts,
                    (string) $j->available_at,
                    (string) $j->created_at,
                ])->all(),
            );
        }

        $failed = DB::table('failed_jobs')
            ->where('payload', 'like', '%DispatchScheduledProductReviewPublishJob%')
            ->where('payload', 'like', '%'.(int) $review->id.'%')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['id', 'queue', 'failed_at', 'exception']);

        if ($failed->isNotEmpty()) {
            $this->error('failed_jobs hits:');
            foreach ($failed as $row) {
                $this->line('#'.$row->id.' queue='.$row->queue.' at='.$row->failed_at);
                $this->line(mb_substr((string) $row->exception, 0, 300));
            }
        }

        $executions = AutomationExecution::query()
            ->where('action_code', 'wordpress.comment_review.publish')
            ->orWhere('context->manual_action->input->review_id', (int) $review->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'execution_uuid', 'status', 'action_code', 'created_at']);

        $this->line('Recent publish executions (best-effort): '.$executions->count());

        return self::SUCCESS;
    }
}
