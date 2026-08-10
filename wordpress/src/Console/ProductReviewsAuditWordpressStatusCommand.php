<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Console;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Console\Command;

final class ProductReviewsAuditWordpressStatusCommand extends Command
{
    protected $signature = 'product-reviews:audit-wordpress-status
                            {--dry-run : Report only (default)}
                            {--article= : Limit to one article id}';

    protected $description = 'Audit local product reviews vs wp_post_id / wp_comment_id (default dry-run).';

    public function handle(): int
    {
        $query = ArticleProductReview::query();
        if ($this->option('article')) {
            $query->where('article_id', (int) $this->option('article'));
        }

        $counts = [
            'pending_article' => 0,
            'pending_publish' => 0,
            'publishing' => 0,
            'published' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'draft' => 0,
            'invalid_missing_article' => 0,
            'has_wp_post_missing_comment' => 0,
            'no_wp_post' => 0,
        ];

        foreach ($query->orderBy('id')->cursor() as $review) {
            /** @var ArticleProductReview $review */
            $status = $review->status instanceof ArticleProductReviewStatus
                ? $review->status->value
                : (string) $review->status;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            $article = SeoArticle::query()->find((int) $review->article_id);
            if (! $article instanceof SeoArticle) {
                $counts['invalid_missing_article']++;
                continue;
            }

            $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpPostId <= 0) {
                $counts['no_wp_post']++;
            } elseif ($review->wp_comment_id === null
                && $status !== ArticleProductReviewStatus::Cancelled->value
                && $status !== ArticleProductReviewStatus::Published->value
            ) {
                $counts['has_wp_post_missing_comment']++;
            }
        }

        $this->table(['metric', 'count'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info('dry-run: yes (audit never mutates)');

        return self::SUCCESS;
    }
}
