<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\ArticleProductReviewStoreService;

final class WordPressCommentReviewService
{
    public function __construct(
        private readonly ArticleProductReviewStoreService $productReviewStore,
    ) {}

    /**
     * @return array{success: bool, message: string, created_count?: int, error_count?: int, created?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function publishFromAiOutput(SeoArticle $article, string $aiOutput): array
    {
        return $this->storeLocalFromAiOutput($article, $aiOutput);
    }

    /**
     * Automatic workflow path: store local rows + emit product review events. No WordPress mutate.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     created_count?: int,
     *     review_ids?: list<int>,
     *     automation_enabled?: bool,
     *     has_wp_post_id?: bool
     * }
     */
    public function storeLocalFromAiOutput(SeoArticle $article, string $aiOutput): array
    {
        return $this->productReviewStore->storeFromAiOutput($article, $aiOutput, 'ai_generated');
    }

    /**
     * Legacy direct push — cutover to local store + automation queue.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{success: bool, message: string, created_count?: int, error_count?: int, created?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function publishItems(SeoArticle $article, array $items): array
    {
        if ($items === []) {
            return [
                'success' => false,
                'message' => 'Không có mục bình luận/review hợp lệ để lưu.',
                'created_count' => 0,
                'error_count' => 0,
            ];
        }

        $result = $this->productReviewStore->storeItems($article, $items, 'legacy_publish_items');

        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'created_count' => (int) ($result['created_count'] ?? 0),
            'error_count' => ($result['success'] ?? false) ? 0 : count($items),
            'created' => [],
            'errors' => [],
            'review_ids' => $result['review_ids'] ?? [],
            'automation_enabled' => $result['automation_enabled'] ?? false,
            'has_wp_post_id' => $result['has_wp_post_id'] ?? false,
        ];
    }
}
