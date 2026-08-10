<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\VirtualCommentService;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressGateway;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Omnichannel\Addons\WordPress\Support\WordPressRestResponseParser;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Publish one local product review into WP virtual-comments meta (merge upsert).
 */
final class WordPressCommentReviewPublisher
{
    public function __construct(
        private readonly WordPressCommentReviewPayloadFactory $payloadFactory,
        private readonly WordPressGateway $gateway,
        private readonly WordPressArticleContentService $contentService,
        private readonly VirtualCommentService $virtualComments,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     outcome?: string,
     *     message: string,
     *     review_id: int,
     *     article_id: int,
     *     wp_post_id?: int|null,
     *     wp_comment_id?: int|null,
     *     status?: string,
     *     deduplicated?: bool,
     *     error_code?: string|null,
     *     remote_response?: array<string, mixed>|null
     * }
     */
    public function publish(
        ArticleProductReview $review,
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
    ): array {
        $reviewId = (int) $review->id;
        $articleId = (int) $article->id;
        $connectionId = (int) $review->connection_id;
        $lockKey = "wordpress-comment-review:{$connectionId}:{$reviewId}";
        $lock = Cache::lock($lockKey, 120);

        if (! $lock->get()) {
            return [
                'success' => false,
                'message' => 'Review đang được publish bởi process khác.',
                'review_id' => $reviewId,
                'article_id' => $articleId,
                'error_code' => 'REVIEW_PUBLISH_LOCK_BUSY',
            ];
        }

        try {
            $review = ArticleProductReview::query()->find($reviewId);
            if (! $review instanceof ArticleProductReview) {
                return [
                    'success' => false,
                    'message' => "Review [{$reviewId}] not found.",
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'error_code' => 'REVIEW_NOT_FOUND',
                ];
            }

            $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? $review->wp_post_id ?? 0);
            if ($wpPostId <= 0) {
                DB::connection('omi_seo_ai')->transaction(function () use ($review): void {
                    $review->status = ArticleProductReviewStatus::PendingArticle;
                    $review->last_error_code = null;
                    $review->last_error_message = null;
                    $review->save();
                });

                return [
                    'success' => true,
                    'outcome' => 'SKIPPED_WAITING_FOR_ARTICLE',
                    'message' => 'Article chưa có wp_post_id — review chờ sync bài.',
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => null,
                    'status' => ArticleProductReviewStatus::PendingArticle->value,
                    'deduplicated' => false,
                ];
            }

            if ($review->status === ArticleProductReviewStatus::Published
                && $review->wp_comment_id !== null
            ) {
                $remote = $this->fetchRemoteVirtualItems($article, $wpPostId);
                $existingIndex = $this->findIndexByOmiReviewId($remote, $reviewId);
                if ($existingIndex !== null) {
                    return [
                        'success' => true,
                        'outcome' => 'DEDUPLICATED',
                        'message' => 'Review đã publish trên WordPress.',
                        'review_id' => $reviewId,
                        'article_id' => $articleId,
                        'wp_post_id' => $wpPostId,
                        'wp_comment_id' => (int) $review->wp_comment_id,
                        'status' => ArticleProductReviewStatus::Published->value,
                        'deduplicated' => true,
                    ];
                }
            }

            // Queue worker không Auth → role mặc định content_manager; automation path dùng SeoQueueContext.
            if (! SeoQueueContext::isWpSyncFromQueue()
                && ! SeoAccessControl::canSyncArticlesToWordPress()
            ) {
                return [
                    'success' => false,
                    'message' => 'Quản lý nội dung không được đăng review lên WordPress.',
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'error_code' => 'FORBIDDEN',
                ];
            }

            DB::connection('omi_seo_ai')->transaction(function () use ($review, $wpPostId): void {
                $review->status = ArticleProductReviewStatus::Publishing;
                $review->wp_post_id = $wpPostId;
                $review->publishing_started_at = now();
                $review->publish_attempts = (int) $review->publish_attempts + 1;
                $review->last_error_code = null;
                $review->last_error_message = null;
                $review->save();
            });

            $remote = $this->fetchRemoteVirtualItems($article, $wpPostId);
            $item = $this->payloadFactory->makeItem($review);
            $merged = $this->upsertByOmiReviewId($remote, $item, $reviewId);

            $postResult = $this->postMerged($article, $wpPostId, $merged, $sideEffect);
            if (! ($postResult['success'] ?? false)) {
                $errorCode = (string) ($postResult['error_code'] ?? 'WORDPRESS_REVIEW_PUBLISH_FAILED');
                $message = (string) ($postResult['message'] ?? 'Publish review thất bại.');
                $retryable = $this->isRetryableError($errorCode, $postResult);
                $attempts = (int) $review->publish_attempts;
                $nextRetry = $retryable ? $this->nextRetryAt($attempts) : null;

                DB::connection('omi_seo_ai')->transaction(function () use ($review, $errorCode, $message, $retryable, $nextRetry): void {
                    $review->status = $retryable && $nextRetry !== null
                        ? ArticleProductReviewStatus::Failed
                        : ArticleProductReviewStatus::Failed;
                    $review->last_error_code = $errorCode;
                    $review->last_error_message = mb_substr($message, 0, 2000);
                    $review->next_retry_at = $nextRetry;
                    $review->save();
                });

                return [
                    'success' => false,
                    'message' => $message,
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => $wpPostId,
                    'status' => ArticleProductReviewStatus::Failed->value,
                    'error_code' => $errorCode,
                    'retryable' => $retryable,
                    'next_retry_at' => $nextRetry?->toIso8601String(),
                    'remote_response' => $postResult['remote_response'] ?? null,
                ];
            }

            $index = $this->findIndexByOmiReviewId($merged, $reviewId);
            $wpCommentId = $index !== null
                ? $this->payloadFactory->syntheticWpCommentId($wpPostId, $index + 1)
                : $this->payloadFactory->syntheticWpCommentId($wpPostId, count($merged));

            try {
                DB::connection('omi_seo_ai')->transaction(function () use ($review, $wpPostId, $wpCommentId): void {
                    $review->status = ArticleProductReviewStatus::Published;
                    $review->wp_post_id = $wpPostId;
                    $review->wp_comment_id = $wpCommentId;
                    $review->published_at = now();
                    $review->last_error_code = null;
                    $review->last_error_message = null;
                    $review->save();
                });
            } catch (\Throwable $finalizeException) {
                return [
                    'success' => false,
                    'message' => 'WP OK nhưng local finalize lỗi: '.$finalizeException->getMessage(),
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => $wpPostId,
                    'wp_comment_id' => $wpCommentId,
                    'status' => ArticleProductReviewStatus::Publishing->value,
                    'error_code' => 'LOCAL_FINALIZE_FAILED',
                    'remote_response' => [
                        'wp_comment_id' => $wpCommentId,
                        'virtual_count' => count($merged),
                    ],
                ];
            }

            return [
                'success' => true,
                'outcome' => 'PUBLISHED',
                'message' => 'Đã publish review lên WordPress.',
                'review_id' => $reviewId,
                'article_id' => $articleId,
                'wp_post_id' => $wpPostId,
                'wp_comment_id' => $wpCommentId,
                'status' => ArticleProductReviewStatus::Published->value,
                'deduplicated' => false,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Reconcile after LOCAL_FINALIZE_FAILED — find remote by _omi_review_id and finalize local.
     *
     * @return array{success: bool, message: string, wp_comment_id?: int, deduplicated?: bool}
     */
    public function reconcileLocalFinalize(ArticleProductReview $review, SeoArticle $article): array
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? $review->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return ['success' => false, 'message' => 'Missing wp_post_id for reconcile.'];
        }

        $remote = $this->fetchRemoteVirtualItems($article, $wpPostId);
        $index = $this->findIndexByOmiReviewId($remote, (int) $review->id);
        if ($index === null) {
            return ['success' => false, 'message' => 'Remote review not found for reconcile.'];
        }

        $wpCommentId = $this->payloadFactory->syntheticWpCommentId($wpPostId, $index + 1);
        DB::connection('omi_seo_ai')->transaction(function () use ($review, $wpPostId, $wpCommentId): void {
            $review->status = ArticleProductReviewStatus::Published;
            $review->wp_post_id = $wpPostId;
            $review->wp_comment_id = $wpCommentId;
            $review->published_at = $review->published_at ?? now();
            $review->last_error_code = null;
            $review->last_error_message = null;
            $review->save();
        });

        return [
            'success' => true,
            'message' => 'Reconciled local finalize from remote.',
            'wp_comment_id' => $wpCommentId,
            'deduplicated' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRemoteVirtualItems(SeoArticle $article, int $wpPostId): array
    {
        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [];
        }

        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $base = $this->contentService->getPermalinkBase($site);
        if ($readToken === '' || $base === '') {
            return [];
        }

        $url = $base.'/wp-json/omi-seo-ai/v1/posts/'.$wpPostId.'/comment-reviews';

        try {
            $response = Http::timeout(30)->acceptJson()->withToken($readToken)->get($url);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();
        $items = is_array($body) ? ($body['items'] ?? []) : [];
        if (! is_array($items)) {
            return [];
        }

        $virtualOnly = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            // Keep virtual meta rows; real wp_comments usually lack virtual=true.
            if (array_key_exists('virtual', $row) && $row['virtual'] !== true) {
                continue;
            }
            $virtualOnly[] = $row;
        }

        return array_values($virtualOnly);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function upsertByOmiReviewId(array $items, array $item, int $reviewId): array
    {
        $found = false;
        $out = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $omiId = (int) ($row['_omi_review_id'] ?? 0);
            if ($omiId === $reviewId) {
                $out[] = $item;
                $found = true;
            } else {
                $out[] = $row;
            }
        }

        if (! $found) {
            $out[] = $item;
        }

        return array_values($out);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function findIndexByOmiReviewId(array $items, int $reviewId): ?int
    {
        foreach ($items as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((int) ($row['_omi_review_id'] ?? 0) === $reviewId) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $merged
     * @return array{success: bool, message?: string, error_code?: string, remote_response?: array<string, mixed>}
     */
    private function postMerged(
        SeoArticle $article,
        int $wpPostId,
        array $merged,
        WordPressExecutionContext $sideEffect,
    ): array {
        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return ['success' => false, 'message' => 'Bài viết chưa gắn domain.', 'error_code' => 'MISSING_SITE'];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return ['success' => false, 'message' => 'Thiếu Migration/Write token trên domain.', 'error_code' => 'MISSING_WRITE_TOKEN'];
        }

        $base = $this->contentService->getPermalinkBase($site);
        if ($base === '') {
            return ['success' => false, 'message' => 'Không xác định được URL WordPress.', 'error_code' => 'MISSING_WP_URL'];
        }

        $url = $base.'/wp-json/omi-seo-ai/v1/posts/'.$wpPostId.'/virtual-comments';
        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';

        try {
            $response = $this->gateway->postJson(
                $sideEffect,
                'wordpress.comment_review.publish',
                $url,
                $writeToken,
                [
                    'virtual_comments' => $merged,
                    'meta_input' => [
                        VirtualCommentService::WP_META_KEY => json_encode(
                            $merged,
                            JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0),
                        ),
                    ],
                    'is_product' => $isProduct,
                ],
                60,
                (int) $article->id,
                (int) ($article->site_id ?? 0),
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'WORDPRESS_REVIEW_PUBLISH_EXCEPTION',
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => WordPressRestResponseParser::formatHttpErrorMessage($response->status(), $response),
                'error_code' => 'WORDPRESS_REVIEW_PUBLISH_FAILED',
                'remote_response' => ['status' => $response->status(), 'body' => $response->body()],
            ];
        }

        $decoded = $response->json();
        if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($decoded['message'] ?? 'WordPress từ chối lưu review.'),
                'error_code' => 'WORDPRESS_REVIEW_PUBLISH_FAILED',
                'remote_response' => is_array($decoded) ? $decoded : null,
            ];
        }

        return [
            'success' => true,
            'message' => (string) ($decoded['message'] ?? 'ok'),
            'remote_response' => $decoded,
        ];
    }

    /**
     * @param  array<string, mixed>  $postResult
     */
    private function isRetryableError(string $errorCode, array $postResult): bool
    {
        if (in_array($errorCode, [
            'FORBIDDEN',
            'INVALID_INPUT',
            'INVALID_CANONICAL_IDS',
            'REVIEW_SCOPE_MISMATCH',
            'ARTICLE_SITE_MISMATCH',
            'REVIEW_CANCELLED',
            'REVIEW_NOT_FOUND',
        ], true)) {
            return false;
        }

        $status = (int) ($postResult['remote_response']['status'] ?? 0);
        if (in_array($status, [401, 403], true)) {
            return false;
        }

        if ($status === 429 || ($status >= 500 && $status <= 599)) {
            return true;
        }

        return in_array($errorCode, [
            'WORDPRESS_REVIEW_PUBLISH_FAILED',
            'WORDPRESS_REVIEW_PUBLISH_EXCEPTION',
            'REVIEW_PUBLISH_LOCK_BUSY',
            'LOCAL_FINALIZE_FAILED',
        ], true);
    }

    private function nextRetryAt(int $attempts): ?\Carbon\CarbonInterface
    {
        // attempt 1 already used automation delay; retries: 1m, 5m, 15m
        $backoff = [60, 300, 900];
        $index = max(0, min(count($backoff) - 1, $attempts - 1));
        if ($attempts > count($backoff) + 1) {
            return null;
        }

        return now()->addSeconds($backoff[$index]);
    }
}
