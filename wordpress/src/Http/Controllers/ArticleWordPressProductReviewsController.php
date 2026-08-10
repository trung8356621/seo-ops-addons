<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewEditorLoadService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/seo/articles/{article}/wordpress-product-reviews
 */
final class ArticleWordPressProductReviewsController extends Controller
{
    public function __construct(
        private readonly ProductReviewEditorLoadService $loader,
    ) {}

    public function __invoke(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        if (ArticlePostTypeResolver::resolve($article) !== 'product') {
            return response()->json([
                'success' => true,
                'data' => [
                    'source' => 'none',
                    'article_id' => (int) $article->id,
                    'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                    'reviews' => [],
                    'pending_local_reviews' => [],
                    'synced_at' => null,
                    'warning' => null,
                ],
            ]);
        }

        try {
            $data = $this->loader->loadForArticle($article);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'data' => [
                    'source' => 'wordpress_unavailable',
                    'article_id' => (int) $article->id,
                    'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                    'reviews' => [],
                    'pending_local_reviews' => [],
                    'synced_at' => null,
                    'warning' => 'Không thể tải đánh giá từ WordPress.',
                    'error' => $e->getMessage(),
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
