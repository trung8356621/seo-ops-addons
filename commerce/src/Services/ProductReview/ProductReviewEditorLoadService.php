<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;

/**
 * Edit Article: WordPress = source of truth; local pending rows separate.
 */
final class ProductReviewEditorLoadService
{
    public function __construct(
        private readonly WordPressProductReviewService $wordpressReviews,
        private readonly ProductReviewPendingRepository $pendingRepository,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     article_id: int,
     *     wp_post_id: int|null,
     *     reviews: list<array<string, mixed>>,
     *     pending_local_reviews: list<array<string, mixed>>,
     *     synced_at: string|null,
     *     warning: string|null,
     *     loading_message?: string|null
     * }
     */
    public function loadForArticle(SeoArticle $article): array
    {
        $articleId = (int) $article->id;
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null;
        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';

        $pendingLocal = $this->pendingRepository
            ->pendingForArticle($article)
            ->map(static fn ($r): array => $r->toEditorArray())
            ->all();

        if (! $isProduct) {
            return [
                'source' => 'none',
                'article_id' => $articleId,
                'wp_post_id' => $wpPostId,
                'reviews' => [],
                'pending_local_reviews' => $pendingLocal,
                'synced_at' => null,
                'warning' => null,
            ];
        }

        if ($wpPostId === null || $wpPostId <= 0) {
            return [
                'source' => 'local_pending',
                'article_id' => $articleId,
                'wp_post_id' => null,
                'reviews' => [],
                'pending_local_reviews' => $pendingLocal,
                'synced_at' => null,
                'warning' => 'Product chưa có trên WordPress. Chỉ hiển thị review tạm local.',
            ];
        }

        $fetch = $this->wordpressReviews->fetchForProduct($article, useCache: true);
        if (! ($fetch['success'] ?? false)) {
            return [
                'source' => 'wordpress_unavailable',
                'article_id' => $articleId,
                'wp_post_id' => $wpPostId,
                'reviews' => [],
                'pending_local_reviews' => $pendingLocal,
                'synced_at' => null,
                'warning' => (string) ($fetch['message'] ?? 'Không thể tải đánh giá từ WordPress.'),
            ];
        }

        return [
            'source' => 'wordpress',
            'article_id' => $articleId,
            'wp_post_id' => $wpPostId,
            'reviews' => is_array($fetch['reviews'] ?? null) ? $fetch['reviews'] : [],
            'pending_local_reviews' => $pendingLocal,
            'synced_at' => is_string($fetch['synced_at'] ?? null) ? $fetch['synced_at'] : now()->toIso8601String(),
            'warning' => ($fetch['cached'] ?? false) ? 'Đang hiển thị dữ liệu gần nhất.' : null,
        ];
    }
}
