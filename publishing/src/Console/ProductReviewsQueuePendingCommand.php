<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\ArticleProductReviewStoreService;
use Illuminate\Console\Command;

final class ProductReviewsQueuePendingCommand extends Command
{
    protected $signature = 'product-reviews:queue-pending
                            {--dry-run : Report only, do not emit events (default unless --execute)}
                            {--execute : Actually emit publish_requested events}
                            {--article= : Limit to one article id}';

    protected $description = 'Queue pending product reviews via automation events (no direct WordPress).';

    public function handle(ArticleProductReviewStoreService $store): int
    {
        $dryRun = ! (bool) $this->option('execute');
        if ($this->option('dry-run')) {
            $dryRun = true;
        }

        $articleIds = ArticleProductReview::query()
            ->whereIn('status', [
                ArticleProductReviewStatus::PendingArticle->value,
                ArticleProductReviewStatus::PendingPublish->value,
            ])
            ->when($this->option('article'), fn ($q) => $q->where('article_id', (int) $this->option('article')))
            ->distinct()
            ->pluck('article_id');

        $pendingArticle = 0;
        $pendingPublish = 0;
        $queued = 0;
        $skippedNoWp = 0;

        foreach ($articleIds as $articleId) {
            $article = SeoArticle::query()->find((int) $articleId);
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $reviews = ArticleProductReview::query()
                ->where('article_id', (int) $articleId)
                ->whereIn('status', [
                    ArticleProductReviewStatus::PendingArticle->value,
                    ArticleProductReviewStatus::PendingPublish->value,
                ])
                ->get();

            foreach ($reviews as $review) {
                if ($review->status === ArticleProductReviewStatus::PendingArticle) {
                    $pendingArticle++;
                } else {
                    $pendingPublish++;
                }
            }

            if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
                $skippedNoWp += $reviews->count();
                $this->line("article #{$articleId}: {$reviews->count()} pending_article (no wp_post_id)");
                continue;
            }

            if ($dryRun) {
                $queued += $reviews->count();
                $this->line("article #{$articleId}: would queue {$reviews->count()} reviews");
                continue;
            }

            $ids = $store->queuePendingForArticle($article, 'publish_after_article');
            $queued += count($ids);
            $this->info("article #{$articleId}: queued ".count($ids).' reviews');
        }

        $this->table(['metric', 'count'], [
            ['pending_article_seen', $pendingArticle],
            ['pending_publish_seen', $pendingPublish],
            ['queued_or_would_queue', $queued],
            ['skipped_no_wp_post', $skippedNoWp],
            ['dry_run', $dryRun ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
