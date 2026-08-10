<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Map legacy / incomplete product review rows to canonical status (no content rewrite).
 */
final class LegacyProductReviewStateNormalizer
{
    /**
     * @return array{repaired: int, review_ids: list<int>}
     */
    public function normalizeForArticle(SeoArticle $article): array
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $repaired = [];

        $rows = ArticleProductReview::query()
            ->where('article_id', (int) $article->id)
            ->orderBy('id')
            ->get();

        foreach ($rows as $review) {
            /** @var ArticleProductReview $review */
            $before = $review->status instanceof ArticleProductReviewStatus
                ? $review->status->value
                : (string) $review->status;

            $changed = $this->normalizeOne($review, $wpPostId);
            if ($changed) {
                $repaired[] = (int) $review->id;
            } elseif ($before === '' || $before === '0') {
                $repaired[] = (int) $review->id;
            }
        }

        return [
            'repaired' => count($repaired),
            'review_ids' => $repaired,
        ];
    }

    public function normalizeOne(ArticleProductReview $review, int $articleWpPostId): bool
    {
        $dirty = false;

        if ($review->content_hash === null || trim((string) $review->content_hash) === '') {
            $review->content_hash = hash(
                'sha256',
                mb_strtolower((string) $review->author_name)."\0".mb_strtolower((string) $review->content)."\0".(string) $review->rating,
            );
            $dirty = true;
        }

        if ($review->idempotency_key === null || trim((string) $review->idempotency_key) === '') {
            $review->idempotency_key = hash(
                'sha256',
                implode('|', [
                    (int) $review->connection_id,
                    (int) $review->article_id,
                    (string) $review->content_hash,
                    'legacy',
                    (int) $review->id,
                ]),
            );
            $dirty = true;
        }

        $status = $review->status instanceof ArticleProductReviewStatus
            ? $review->status
            : ArticleProductReviewStatus::tryFrom((string) $review->status);

        if ($review->wp_comment_id !== null && (int) $review->wp_comment_id !== 0) {
            if ($status !== ArticleProductReviewStatus::Published) {
                $review->status = ArticleProductReviewStatus::Published;
                $dirty = true;
            }
        } elseif ($status === null
            || $status === ArticleProductReviewStatus::Draft
            || ! in_array($status, [
                ArticleProductReviewStatus::PendingArticle,
                ArticleProductReviewStatus::PendingPublish,
                ArticleProductReviewStatus::Scheduled,
                ArticleProductReviewStatus::Publishing,
                ArticleProductReviewStatus::Published,
                ArticleProductReviewStatus::Failed,
                ArticleProductReviewStatus::FailedDispatch,
                ArticleProductReviewStatus::Cancelled,
            ], true)
        ) {
            $review->status = $articleWpPostId > 0
                ? ArticleProductReviewStatus::PendingPublish
                : ArticleProductReviewStatus::PendingArticle;
            $dirty = true;
        } elseif ($status === ArticleProductReviewStatus::PendingArticle && $articleWpPostId > 0) {
            $review->status = ArticleProductReviewStatus::PendingPublish;
            $dirty = true;
        } elseif ($status === ArticleProductReviewStatus::PendingPublish && $articleWpPostId <= 0) {
            $review->status = ArticleProductReviewStatus::PendingArticle;
            $dirty = true;
        }

        // Legacy "published" without remote id → cần reconcile, không tin mù.
        if ($status === ArticleProductReviewStatus::Published && ($review->wp_comment_id === null || (int) $review->wp_comment_id === 0)) {
            $review->status = $articleWpPostId > 0
                ? ArticleProductReviewStatus::PendingPublish
                : ArticleProductReviewStatus::PendingArticle;
            $dirty = true;
        }

        if ($articleWpPostId > 0 && (int) ($review->wp_post_id ?? 0) <= 0) {
            $review->wp_post_id = $articleWpPostId;
            $dirty = true;
        }

        if ($dirty) {
            $review->save();
        }

        return $dirty;
    }
}
