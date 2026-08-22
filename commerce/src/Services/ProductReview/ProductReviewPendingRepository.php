<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Local pending/syncing/failed Product Reviews — not WordPress source of truth.
 */
final class ProductReviewPendingRepository
{
    /**
     * Reviews still needing WordPress create (or retry).
     *
     * @return Collection<int, ArticleProductReview>
     */
    public function pendingForArticle(SeoArticle $article): Collection
    {
        return ArticleProductReview::query()
            ->where('article_id', (int) $article->id)
            ->whereIn('status', [
                ArticleProductReviewStatus::Pending->value,
                ArticleProductReviewStatus::Failed->value,
                ArticleProductReviewStatus::Syncing->value,
                // Legacy rows if migration not applied yet
                ArticleProductReviewStatus::Draft->value,
                ArticleProductReviewStatus::PendingArticle->value,
                ArticleProductReviewStatus::PendingPublish->value,
                ArticleProductReviewStatus::Scheduled->value,
                ArticleProductReviewStatus::Publishing->value,
                ArticleProductReviewStatus::FailedDispatch->value,
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Local rows visible in editor (pending + failed + recently reviewed).
     *
     * @return Collection<int, ArticleProductReview>
     */
    public function localForEditor(SeoArticle $article): Collection
    {
        return ArticleProductReview::query()
            ->where('article_id', (int) $article->id)
            ->where('status', '!=', ArticleProductReviewStatus::Cancelled->value)
            ->orderBy('id')
            ->get();
    }

    public function markSyncing(ArticleProductReview $review, int $wpPostId): ArticleProductReview
    {
        return DB::connection('omi_seo_ai')->transaction(function () use ($review, $wpPostId): ArticleProductReview {
            $review->status = ArticleProductReviewStatus::Syncing;
            $review->wp_post_id = $wpPostId;
            $review->publishing_started_at = now();
            $review->publish_attempts = (int) $review->publish_attempts + 1;
            $review->last_error_code = null;
            $review->last_error_message = null;
            $review->save();

            return $review->fresh() ?? $review;
        });
    }

    public function markFailed(
        ArticleProductReview $review,
        string $errorCode,
        string $message,
    ): ArticleProductReview {
        return DB::connection('omi_seo_ai')->transaction(function () use ($review, $errorCode, $message): ArticleProductReview {
            $review->status = ArticleProductReviewStatus::Failed;
            $review->last_error_code = $errorCode;
            $review->last_error_message = mb_substr($message, 0, 2000);
            $review->save();

            return $review->fresh() ?? $review;
        });
    }

    public function markReviewed(
        ArticleProductReview $review,
        int $wpPostId,
        int $wpCommentId,
    ): ArticleProductReview {
        return DB::connection('omi_seo_ai')->transaction(function () use ($review, $wpPostId, $wpCommentId): ArticleProductReview {
            $review->status = ArticleProductReviewStatus::Reviewed;
            $review->wp_post_id = $wpPostId;
            $review->wp_comment_id = $wpCommentId;
            $review->published_at = $review->published_at ?? now();
            $review->synced_at = now();
            $review->last_error_code = null;
            $review->last_error_message = null;
            $review->save();

            return $review->fresh() ?? $review;
        });
    }

    /**
     * Cancel a local row that maps to an already-fulfilled unique review (same content_hash / remote).
     */
    public function markCancelledDuplicate(
        ArticleProductReview $review,
        string $reason,
        ?int $canonicalReviewId = null,
    ): ArticleProductReview {
        return DB::connection('omi_seo_ai')->transaction(function () use ($review, $reason, $canonicalReviewId): ArticleProductReview {
            $message = $canonicalReviewId !== null && $canonicalReviewId > 0
                ? sprintf('%s (canonical_review_id=%d)', $reason, $canonicalReviewId)
                : $reason;
            $review->status = ArticleProductReviewStatus::Cancelled;
            $review->last_error_code = 'DUPLICATE_CONTENT';
            $review->last_error_message = mb_substr($message, 0, 2000);
            $review->save();

            return $review->fresh() ?? $review;
        });
    }

    /**
     * Oldest reviewed/published row sharing the same content fingerprint (if any).
     */
    public function findCanonicalByContentHash(int $articleId, string $contentHash, ?int $exceptReviewId = null): ?ArticleProductReview
    {
        $hash = trim($contentHash);
        if ($hash === '') {
            return null;
        }

        $query = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->where('content_hash', $hash)
            ->whereIn('status', [
                ArticleProductReviewStatus::Reviewed->value,
                ArticleProductReviewStatus::Published->value,
            ])
            ->whereNotNull('wp_comment_id')
            ->where('wp_comment_id', '!=', 0)
            ->orderBy('id');

        if ($exceptReviewId !== null && $exceptReviewId > 0) {
            $query->where('id', '!=', $exceptReviewId);
        }

        $row = $query->first();

        return $row instanceof ArticleProductReview ? $row : null;
    }

    public function deleteReviewedForArticle(SeoArticle $article): int
    {
        // Only drop rows that already have remote confirmation — never wipe pending/failed.
        return ArticleProductReview::query()
            ->where('article_id', (int) $article->id)
            ->whereIn('status', [
                ArticleProductReviewStatus::Reviewed->value,
                ArticleProductReviewStatus::Published->value,
            ])
            ->whereNotNull('wp_comment_id')
            ->where('wp_comment_id', '!=', 0)
            ->delete();
    }

    /**
     * @deprecated Prefer deleteReviewedForArticle — must never delete unsynced rows.
     */
    public function deleteLocalForArticle(SeoArticle $article): int
    {
        return $this->deleteReviewedForArticle($article);
    }

    /**
     * Skip re-publish when already reviewed with remote id.
     */
    public function shouldSkipCreate(ArticleProductReview $review): bool
    {
        $status = $review->status instanceof ArticleProductReviewStatus
            ? $review->status
            : ArticleProductReviewStatus::tryFrom((string) $review->status);

        if ($status === null) {
            return false;
        }

        return $status->isReviewed() && (int) ($review->wp_comment_id ?? 0) !== 0;
    }
}
