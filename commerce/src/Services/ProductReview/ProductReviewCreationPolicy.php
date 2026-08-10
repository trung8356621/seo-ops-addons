<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\Data\ProductReviewCreationDecision;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;

/**
 * Single policy for Edit Article status, automation create action, manual create.
 *
 * Idempotent create: target_count = maintain total AI reviews, not "create N each run".
 */
final class ProductReviewCreationPolicy
{
    public const DEFAULT_TARGET_COUNT = 10;

    /** @var list<string> */
    private const GENERATED_SOURCES = ['seo_content_ai', 'ai_generated', 'laravel'];

    /**
     * @param  array{
     *     wordpress_connected?: bool,
     *     wordpress_real_review_count?: int,
     *     wordpress_generated_review_count?: int,
     *     wordpress_review_count?: int,
     *     fetch_success?: bool
     * }  $wordpressState
     * @param  array{
     *     local_pending_count?: int,
     *     local_reviewed_count?: int,
     *     local_generated_count?: int,
     *     local_real_count?: int
     * }  $localState
     * @param  array{target_count?: int, block_if_real_reviews_exist?: bool, enabled?: bool}  $settings
     */
    public function evaluate(
        SeoArticle $article,
        array $wordpressState,
        array $localState = [],
        array $settings = [],
    ): ProductReviewCreationDecision {
        $target = max(0, (int) ($settings['target_count'] ?? self::DEFAULT_TARGET_COUNT));
        $blockIfReal = ($settings['block_if_real_reviews_exist'] ?? true) !== false;
        $featureEnabled = ($settings['enabled'] ?? true) !== false;

        $wpReal = max(0, (int) ($wordpressState['wordpress_real_review_count'] ?? 0));
        $wpGenerated = max(0, (int) ($wordpressState['wordpress_generated_review_count'] ?? 0));
        $localGenerated = max(0, (int) ($localState['local_generated_count'] ?? 0));
        $localReal = max(0, (int) ($localState['local_real_count'] ?? 0));
        $pendingCount = max(0, (int) ($localState['local_pending_count'] ?? 0));

        // max() tránh double-count khi local mirror + WP cùng tồn tại sau sync.
        $generatedCount = max($wpGenerated, $localGenerated);
        $realCount = max($wpReal, $localReal);

        if (! $featureEnabled) {
            return $this->blocked('feature_disabled', $target, 0, $realCount, $generatedCount, $pendingCount);
        }

        if (ArticlePostTypeResolver::resolve($article) !== 'product') {
            return $this->blocked('not_product', $target, 0, $realCount, $generatedCount, $pendingCount);
        }

        $wpConnected = (bool) ($wordpressState['wordpress_connected'] ?? false);
        $fetchOk = ($wordpressState['fetch_success'] ?? true) !== false;
        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0 && (! $wpConnected || ! $fetchOk)) {
            return $this->blocked('wordpress_unavailable', $target, 0, $realCount, $generatedCount, $pendingCount);
        }

        if ($blockIfReal && $realCount > 0) {
            return $this->blocked('wordpress_real_reviews_exist', $target, 0, $realCount, $generatedCount, $pendingCount, 'none');
        }

        $missing = max(0, $target - $generatedCount);
        if ($missing <= 0) {
            return $this->blocked('target_count_reached', $target, 0, $realCount, $generatedCount, $pendingCount, 'none');
        }

        return new ProductReviewCreationDecision(
            allowed: true,
            reason: null,
            targetCount: $target,
            missingCount: $missing,
            recommendedAction: 'create',
            wordpressRealReviewCount: $realCount,
            wordpressGeneratedReviewCount: $generatedCount,
            localPendingCount: $pendingCount,
        );
    }

    /**
     * @return array{
     *     local_pending_count: int,
     *     local_reviewed_count: int,
     *     local_generated_count: int,
     *     local_real_count: int
     * }
     */
    public function localCounts(SeoArticle $article): array
    {
        $articleId = (int) $article->id;

        $pending = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereIn('status', [
                ArticleProductReviewStatus::Pending->value,
                ArticleProductReviewStatus::Syncing->value,
                ArticleProductReviewStatus::Failed->value,
            ])
            ->count();

        $reviewed = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereIn('status', [
                ArticleProductReviewStatus::Reviewed->value,
                ArticleProductReviewStatus::Published->value,
            ])
            ->count();

        $generated = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereNotIn('status', [ArticleProductReviewStatus::Cancelled->value])
            ->where(function ($query): void {
                $query->whereIn('source', self::GENERATED_SOURCES)
                    ->orWhereNotNull('generation_batch_id');
            })
            ->count();

        $real = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereNotIn('status', [ArticleProductReviewStatus::Cancelled->value])
            ->whereNull('generation_batch_id')
            ->where(function ($query): void {
                $query->whereNull('source')
                    ->orWhereNotIn('source', self::GENERATED_SOURCES);
            })
            ->count();

        return [
            'local_pending_count' => $pending,
            'local_reviewed_count' => $reviewed,
            'local_generated_count' => $generated,
            'local_real_count' => $real,
        ];
    }

    private function blocked(
        string $reason,
        int $target,
        int $missing,
        int $real,
        int $generated,
        int $pending,
        string $recommended = 'none',
    ): ProductReviewCreationDecision {
        return new ProductReviewCreationDecision(
            allowed: false,
            reason: $reason,
            targetCount: $target,
            missingCount: $missing,
            recommendedAction: $recommended,
            wordpressRealReviewCount: $real,
            wordpressGeneratedReviewCount: $generated,
            localPendingCount: $pending,
        );
    }
}
