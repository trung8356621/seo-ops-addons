<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Models\ArticleProductReview;

/**
 * Build WordPress virtual-comment item from local review (+ OMI idempotency metadata).
 */
final class WordPressCommentReviewPayloadFactory
{
    /**
     * @return array<string, mixed>
     */
    public function makeItem(ArticleProductReview $review): array
    {
        $item = [
            'author' => (string) $review->author_name,
            'content' => (string) $review->content,
            'date' => $review->review_date?->format('Y-m-d H:i:s')
                ?? $review->created_at?->format('Y-m-d H:i:s')
                ?? now()->format('Y-m-d H:i:s'),
            'virtual' => true,
            'source' => 'seo_content_ai',
            'generated' => true,
            'laravel_review_id' => (int) $review->id,
            'generation_batch_id' => (string) ($review->generation_batch_id ?? ''),
            'idempotency_key' => (string) $review->idempotency_key,
            '_omi_source' => 'seo_content_ai',
            '_omi_review_id' => (int) $review->id,
            '_omi_idempotency_key' => (string) $review->idempotency_key,
            '_omi_article_id' => (int) $review->article_id,
            '_omi_generation_batch_id' => (string) ($review->generation_batch_id ?? ''),
        ];

        if ($review->author_email !== null && $review->author_email !== '') {
            $item['author_email'] = (string) $review->author_email;
        }

        if ($review->rating !== null) {
            $item['rating'] = (int) $review->rating;
        }

        return $item;
    }

    public function syntheticWpCommentId(int $wpPostId, int $oneBasedIndex): int
    {
        return -($wpPostId * 1000 + max(1, $oneBasedIndex));
    }
}
