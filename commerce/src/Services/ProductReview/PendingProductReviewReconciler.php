<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Enums\ProductReviewPublishIntent;
use Omnichannel\Addons\WordPress\Jobs\DispatchScheduledProductReviewPublishJob;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\Data\ProductReviewReconciliationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Query pending reviews, lock, schedule per-review delayed publish (no WP HTTP).
 */
final class PendingProductReviewReconciler
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  list<int>|null  $reviewIds  null = all eligible; [] also means all (never "match none")
     */
    public function reconcileForArticle(
        SeoArticle $article,
        array $settings = [],
        ?array $reviewIds = null,
        string $publishIntent = ProductReviewPublishIntent::PublishAfterArticle->value,
        ?int $actorId = null,
        bool $dryRun = false,
    ): ProductReviewReconciliationResult {
        $articleId = (int) $article->id;
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $maxDelayMinutes = ProductReviewDelaySettings::resolveMaxDelayMinutes($settings);
        $settingsSnapshot = ProductReviewDelaySettings::normalizeSettings($settings, $maxDelayMinutes);

        if ($wpPostId <= 0) {
            $pending = $this->queryCandidateReviews($articleId, $reviewIds);
            $waiting = $pending->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            if (! $dryRun) {
                foreach ($pending as $review) {
                    /** @var ArticleProductReview $review */
                    if ($review->status !== ArticleProductReviewStatus::PendingArticle) {
                        $review->forceFill(['status' => ArticleProductReviewStatus::PendingArticle->value])->save();
                    }
                }
            }

            return new ProductReviewReconciliationResult(
                articleId: $articleId,
                foundReviewIds: $waiting,
                waitingForArticle: $waiting,
                outcome: 'WAITING_FOR_ARTICLE_SYNC',
                message: 'Article missing wp_post_id — reviews stay pending_article.',
            );
        }

        $queued = [];
        $skippedScheduled = [];
        $skippedPublished = [];
        $skippedProcessing = [];
        $invalid = [];
        $found = [];

        $candidates = $this->queryCandidateReviews($articleId, $reviewIds);
        foreach ($candidates as $candidate) {
            $found[] = (int) $candidate->id;
        }

        foreach ($found as $reviewId) {
            $outcome = $dryRun
                ? $this->previewSchedule($reviewId, $wpPostId)
                : $this->scheduleOne(
                    reviewId: $reviewId,
                    article: $article,
                    wpPostId: $wpPostId,
                    maxDelayMinutes: $maxDelayMinutes,
                    settingsSnapshot: $settingsSnapshot,
                    publishIntent: $publishIntent,
                    actorId: $actorId,
                );

            match ($outcome) {
                'queued' => $queued[] = $reviewId,
                'already_scheduled' => $skippedScheduled[] = $reviewId,
                'already_published' => $skippedPublished[] = $reviewId,
                'already_processing' => $skippedProcessing[] = $reviewId,
                default => $invalid[] = $reviewId,
            };
        }

        return new ProductReviewReconciliationResult(
            articleId: $articleId,
            foundReviewIds: $found,
            queuedReviewIds: $queued,
            skippedAlreadyScheduled: $skippedScheduled,
            skippedAlreadyPublished: $skippedPublished,
            skippedAlreadyProcessing: $skippedProcessing,
            invalid: $invalid,
            outcome: 'OK',
            message: sprintf('Queued %d / found %d reviews.', count($queued), count($found)),
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<int>  $reviewIds
     */
    public function scheduleGeneratedReviews(
        SeoArticle $article,
        array $reviewIds,
        array $settings = [],
        ?int $actorId = null,
    ): ProductReviewReconciliationResult {
        // Empty list = schedule all pending for article (never treat as match-none).
        $ids = $reviewIds === [] ? null : $reviewIds;

        return $this->reconcileForArticle(
            article: $article,
            settings: $settings,
            reviewIds: $ids,
            publishIntent: ProductReviewPublishIntent::GeneratedReview->value,
            actorId: $actorId,
            dryRun: false,
        );
    }

    /**
     * @param  list<int>|null  $reviewIds
     * @return \Illuminate\Support\Collection<int, ArticleProductReview>
     */
    private function queryCandidateReviews(int $articleId, ?array $reviewIds)
    {
        $query = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereNull('wp_comment_id')
            ->whereNotIn('status', [
                ArticleProductReviewStatus::Cancelled->value,
                ArticleProductReviewStatus::Published->value,
                ArticleProductReviewStatus::Publishing->value,
            ])
            ->whereIn('status', [
                ArticleProductReviewStatus::Draft->value,
                ArticleProductReviewStatus::PendingArticle->value,
                ArticleProductReviewStatus::PendingPublish->value,
                ArticleProductReviewStatus::Scheduled->value,
                ArticleProductReviewStatus::Failed->value,
                ArticleProductReviewStatus::FailedDispatch->value,
            ])
            ->orderBy('id');

        if ($reviewIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $reviewIds), static fn (int $id): bool => $id > 0));
            // [] after filter → do not narrow (avoid false "found 0")
            if ($ids !== []) {
                $query->whereIn('id', $ids);
            }
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $settingsSnapshot
     */
    private function scheduleOne(
        int $reviewId,
        SeoArticle $article,
        int $wpPostId,
        int $maxDelayMinutes,
        array $settingsSnapshot,
        string $publishIntent,
        ?int $actorId,
    ): string {
        $plan = DB::connection('omi_seo_ai')->transaction(function () use (
            $reviewId,
            $article,
            $wpPostId,
            $maxDelayMinutes,
            $settingsSnapshot,
            $publishIntent,
            $actorId,
        ): array|string {
            /** @var ArticleProductReview|null $review */
            $review = ArticleProductReview::query()
                ->whereKey($reviewId)
                ->lockForUpdate()
                ->first();

            if (! $review instanceof ArticleProductReview) {
                return 'invalid';
            }

            if ($review->status === ArticleProductReviewStatus::Cancelled) {
                return 'invalid';
            }

            if ($review->status === ArticleProductReviewStatus::Published && $review->wp_comment_id !== null) {
                return 'already_published';
            }

            if ($review->status === ArticleProductReviewStatus::Publishing) {
                return 'already_processing';
            }

            if ($review->wp_comment_id !== null) {
                return 'already_published';
            }

            if ($review->next_retry_at !== null && $review->next_retry_at->isFuture()) {
                return 'already_scheduled';
            }

            $idempotencyKey = $this->publishIdempotencyKey($review);
            if ($this->hasActivePublishExecution($idempotencyKey)) {
                return 'already_scheduled';
            }

            if ($review->status === ArticleProductReviewStatus::Scheduled
                && $review->scheduled_at !== null
                && $review->scheduled_at->isFuture()
                && $review->selected_delay_seconds !== null
            ) {
                return 'already_scheduled';
            }

            $isFailedRetry = in_array($review->status, [
                ArticleProductReviewStatus::Failed,
                ArticleProductReviewStatus::FailedDispatch,
            ], true);
            $preserveDelay = $review->selected_delay_seconds !== null && (int) $review->selected_delay_seconds >= 0
                && ($isFailedRetry || ($review->status === ArticleProductReviewStatus::Scheduled && $review->scheduled_at?->isPast()));

            $delaySeconds = $preserveDelay
                ? (int) $review->selected_delay_seconds
                : ProductReviewDelaySettings::pickDelaySeconds($maxDelayMinutes);

            $missedOrRetry = $isFailedRetry
                || ($review->status === ArticleProductReviewStatus::Scheduled
                    && $review->scheduled_at !== null
                    && $review->scheduled_at->isPast());

            $dispatchDelaySeconds = $missedOrRetry ? 0 : $delaySeconds;
            $scheduledAt = $dispatchDelaySeconds > 0 ? now()->addSeconds($dispatchDelaySeconds) : now();
            $queueName = AutomationQueueName::External->value;

            // Chưa scheduled — chỉ lưu plan. Status = scheduled SAU khi dispatch thành công.
            $review->forceFill([
                'wp_post_id' => $wpPostId,
                'status' => ArticleProductReviewStatus::PendingPublish->value,
                'configured_max_delay_minutes' => $maxDelayMinutes,
                'selected_delay_seconds' => $delaySeconds,
                'scheduled_at' => $scheduledAt,
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return [
                'review_id' => (int) $review->id,
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? $review->site_id),
                'connection_id' => (int) $review->connection_id,
                'publish_intent' => $publishIntent,
                'settings_snapshot' => $settingsSnapshot,
                'actor_id' => $actorId,
                'delay_seconds' => $dispatchDelaySeconds,
                'selected_delay_seconds' => $delaySeconds,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'queue' => $queueName,
                'idempotency_key' => $idempotencyKey,
            ];
        });

        if (is_string($plan)) {
            return $plan;
        }

        try {
            $pending = DispatchScheduledProductReviewPublishJob::dispatch(
                $plan['review_id'],
                $plan['article_id'],
                $plan['site_id'],
                $plan['connection_id'],
                $plan['publish_intent'],
                $plan['settings_snapshot'],
                $plan['actor_id'],
            )->onQueue($plan['queue']);

            if ((int) $plan['delay_seconds'] > 0) {
                $pending->delay(\Carbon\Carbon::parse((string) $plan['scheduled_at']));
            }

            ArticleProductReview::query()->whereKey($plan['review_id'])->update([
                'status' => ArticleProductReviewStatus::Scheduled->value,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            Log::info('product_review.publish_scheduled', [
                'review_id' => $plan['review_id'],
                'article_id' => $plan['article_id'],
                'queue' => $plan['queue'],
                'selected_delay_seconds' => $plan['selected_delay_seconds'],
                'scheduled_at' => $plan['scheduled_at'],
                'idempotency_key' => $plan['idempotency_key'],
                'child_job' => DispatchScheduledProductReviewPublishJob::class,
            ]);

            return 'queued';
        } catch (\Throwable $e) {
            ArticleProductReview::query()->whereKey($plan['review_id'])->update([
                'status' => ArticleProductReviewStatus::FailedDispatch->value,
                'last_error_code' => 'DISPATCH_FAILED',
                'last_error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('product_review.publish_dispatch_failed', [
                'review_id' => $plan['review_id'],
                'article_id' => $plan['article_id'],
                'error' => $e->getMessage(),
            ]);

            return 'invalid';
        }
    }

    private function previewSchedule(int $reviewId, int $wpPostId): string
    {
        $review = ArticleProductReview::query()->find($reviewId);
        if (! $review instanceof ArticleProductReview) {
            return 'invalid';
        }
        if ($review->status === ArticleProductReviewStatus::Published && $review->wp_comment_id !== null) {
            return 'already_published';
        }
        if ($review->status === ArticleProductReviewStatus::Publishing) {
            return 'already_processing';
        }
        if ($review->status === ArticleProductReviewStatus::Scheduled
            && $review->scheduled_at !== null
            && $review->scheduled_at->isFuture()
        ) {
            return 'already_scheduled';
        }
        if ($this->hasActivePublishExecution($this->publishIdempotencyKey($review))) {
            return 'already_scheduled';
        }
        if ($wpPostId <= 0) {
            return 'waiting';
        }

        return 'queued';
    }

    private function publishIdempotencyKey(ArticleProductReview $review): string
    {
        return 'wordpress-comment-review-publish:'
            .(int) $review->connection_id.':'
            .(int) $review->article_id.':'
            .(int) $review->id.':'
            .(string) $review->content_hash;
    }

    private function hasActivePublishExecution(string $idempotencyKey): bool
    {
        try {
            return AutomationExecution::query()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('status', [
                    AutomationExecutionStatus::Pending->value,
                    AutomationExecutionStatus::Processing->value,
                ])
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
