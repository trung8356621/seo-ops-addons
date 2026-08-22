<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\Data\ProductReviewDto;
use Omnichannel\Addons\WordPress\Services\VirtualCommentService;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressGateway;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\WordPress\Support\WordPressRestResponseParser;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * WordPress is source of truth for product reviews.
 * Local Laravel rows are pending drafts until marked reviewed.
 */
final class WordPressProductReviewService
{
    private const FETCH_CACHE_TTL_SECONDS = 45;

    public function __construct(
        private readonly WordPressCommentReviewPayloadFactory $payloadFactory,
        private readonly WordPressGateway $gateway,
        private readonly WordPressArticleContentService $contentService,
        private readonly ProductReviewPendingRepository $pendingRepository,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     reviews: list<array<string, mixed>>,
     *     message?: string,
     *     error_code?: string|null,
     *     cached?: bool,
     *     synced_at?: string|null
     * }
     */
    public function fetchForProduct(SeoArticle $article, bool $useCache = true): array
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => true,
                'reviews' => [],
                'message' => 'Article chưa có wp_post_id.',
                'synced_at' => null,
            ];
        }

        $cacheKey = $this->fetchCacheKey((int) $article->id, $wpPostId);
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['reviews']) && is_array($cached['reviews'])) {
                return [
                    'success' => true,
                    'reviews' => $cached['reviews'],
                    'cached' => true,
                    'synced_at' => is_string($cached['synced_at'] ?? null) ? $cached['synced_at'] : null,
                ];
            }
        }

        $remote = $this->fetchRemoteVirtualItems($article, $wpPostId);
        if ($remote['success'] !== true) {
            return [
                'success' => false,
                'reviews' => [],
                'message' => (string) ($remote['message'] ?? 'Không thể tải đánh giá từ WordPress.'),
                'error_code' => (string) ($remote['error_code'] ?? 'WORDPRESS_REVIEW_FETCH_FAILED'),
                'synced_at' => null,
            ];
        }

        $reviews = [];
        foreach ($remote['items'] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $reviews[] = $this->mapRemoteReview($item, $wpPostId, $index + 1)->toArray();
        }

        $syncedAt = now()->toIso8601String();
        Cache::put($cacheKey, ['reviews' => $reviews, 'synced_at' => $syncedAt], self::FETCH_CACHE_TTL_SECONDS);

        return [
            'success' => true,
            'reviews' => $reviews,
            'cached' => false,
            'synced_at' => $syncedAt,
        ];
    }

    public function invalidateFetchCache(SeoArticle $article): void
    {
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return;
        }

        Cache::forget($this->fetchCacheKey((int) $article->id, $wpPostId));
    }

    /**
     * @param  list<array<string, mixed>>  $remoteItems
     */
    public function exists(ArticleProductReview $review, array $remoteItems): bool
    {
        if ($this->findIndexByOmiReviewId($remoteItems, (int) $review->id) !== null) {
            return true;
        }

        $key = (string) $review->idempotency_key;
        if ($key !== '') {
            foreach ($remoteItems as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ((string) ($row['_omi_idempotency_key'] ?? '') === $key) {
                    return true;
                }
            }
        }

        $author = mb_strtolower(trim((string) $review->author_name));
        $hash = (string) $review->content_hash;
        if ($hash === '') {
            $hash = ProductReviewContentFingerprint::hash(
                (string) $review->author_name,
                (string) $review->content,
                $review->rating,
            );
        }

        foreach ($remoteItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $remoteAuthor = mb_strtolower(trim((string) ($row['author'] ?? $row['author_name'] ?? '')));
            $remoteHash = ProductReviewContentFingerprint::fromRemoteItem($row);
            if ($remoteAuthor === $author && $remoteHash === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create (or upsert) one pending review onto WordPress product post.
     *
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
     *     error_code?: string|null
     * }
     */
    public function create(
        ArticleProductReview $review,
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
    ): array {
        $reviewId = (int) $review->id;
        $articleId = (int) $article->id;
        $connectionId = (int) $review->connection_id;
        $lockKey = "wordpress-product-review:{$connectionId}:{$reviewId}";
        $lock = Cache::lock($lockKey, 120);

        if (! $lock->get()) {
            return [
                'success' => false,
                'message' => 'Review đang được sync bởi process khác.',
                'review_id' => $reviewId,
                'article_id' => $articleId,
                'error_code' => 'REVIEW_SYNC_LOCK_BUSY',
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

            if ($this->pendingRepository->shouldSkipCreate($review)) {
                return [
                    'success' => true,
                    'outcome' => 'SKIPPED_REVIEWED',
                    'message' => 'Review đã reviewed — bỏ qua.',
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => (int) ($review->wp_post_id ?? 0) ?: null,
                    'wp_comment_id' => (int) ($review->wp_comment_id ?? 0) ?: null,
                    'status' => 'reviewed',
                    'deduplicated' => true,
                ];
            }

            $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? $review->wp_post_id ?? 0);
            if ($wpPostId <= 0) {
                return [
                    'success' => false,
                    'message' => 'Product chưa có wp_post_id — không tạo review.',
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'error_code' => 'MISSING_WP_POST_ID',
                ];
            }

            $this->pendingRepository->markSyncing($review, $wpPostId);
            $review = $review->fresh() ?? $review;

            try {
                $remoteFetch = $this->fetchRemoteVirtualItems($article, $wpPostId);
                if (! ($remoteFetch['success'] ?? false)) {
                    $errorCode = (string) ($remoteFetch['error_code'] ?? 'WORDPRESS_REVIEW_FETCH_FAILED');
                    $message = (string) ($remoteFetch['message'] ?? 'Không đọc được review hiện có trên WordPress.');
                    $this->pendingRepository->markFailed($review, $errorCode, $message);

                    return [
                        'success' => false,
                        'message' => $message,
                        'review_id' => $reviewId,
                        'article_id' => $articleId,
                        'wp_post_id' => $wpPostId,
                        'status' => 'failed',
                        'error_code' => $errorCode,
                    ];
                }

                $remoteItems = is_array($remoteFetch['items'] ?? null) ? $remoteFetch['items'] : [];

                if ($this->exists($review, $remoteItems)) {
                    $canonical = $this->pendingRepository->findCanonicalByContentHash(
                        $articleId,
                        (string) $review->content_hash,
                        $reviewId,
                    );
                    if ($canonical instanceof ArticleProductReview) {
                        // Same logical review already fulfilled — do not count a second reviewed row.
                        $this->pendingRepository->markCancelledDuplicate(
                            $review,
                            'Duplicate of already-synced review content.',
                            (int) $canonical->id,
                        );
                        $this->invalidateFetchCache($article);

                        return [
                            'success' => true,
                            'outcome' => 'DUPLICATE_CANCELLED',
                            'message' => 'Review trùng nội dung review đã sync — đã hủy local trùng.',
                            'review_id' => $reviewId,
                            'article_id' => $articleId,
                            'wp_post_id' => $wpPostId,
                            'wp_comment_id' => (int) ($canonical->wp_comment_id ?? 0) ?: null,
                            'status' => 'cancelled',
                            'deduplicated' => true,
                            'unique_fulfilled' => false,
                            'canonical_review_id' => (int) $canonical->id,
                        ];
                    }

                    $index = $this->findIndexByOmiReviewId($remoteItems, $reviewId)
                        ?? $this->findIndexByIdempotency($remoteItems, (string) $review->idempotency_key)
                        ?? $this->findIndexByContentFingerprint($remoteItems, $review)
                        ?? 0;
                    $wpCommentId = $this->payloadFactory->syntheticWpCommentId($wpPostId, $index + 1);
                    $this->pendingRepository->markReviewed($review, $wpPostId, $wpCommentId);
                    $this->invalidateFetchCache($article);

                    return [
                        'success' => true,
                        'outcome' => 'DEDUPLICATED',
                        'message' => 'Review đã tồn tại trên WordPress.',
                        'review_id' => $reviewId,
                        'article_id' => $articleId,
                        'wp_post_id' => $wpPostId,
                        'wp_comment_id' => $wpCommentId,
                        'status' => 'reviewed',
                        'deduplicated' => true,
                        'unique_fulfilled' => true,
                    ];
                }

                $item = $this->payloadFactory->makeItem($review);
                $item['source_system'] = 'laravel';
                $item['laravel_review_id'] = $reviewId;
                $merged = $this->upsertByOmiReviewId($remoteItems, $item, $reviewId);
                $postResult = $this->postMerged($article, $wpPostId, $merged, $sideEffect);

                if (! ($postResult['success'] ?? false)) {
                    $errorCode = (string) ($postResult['error_code'] ?? 'WORDPRESS_REVIEW_CREATE_FAILED');
                    $message = (string) ($postResult['message'] ?? 'Tạo review trên WordPress thất bại.');
                    $this->pendingRepository->markFailed($review, $errorCode, $message);

                    return [
                        'success' => false,
                        'message' => $message,
                        'review_id' => $reviewId,
                        'article_id' => $articleId,
                        'wp_post_id' => $wpPostId,
                        'status' => 'failed',
                        'error_code' => $errorCode,
                    ];
                }

                $index = $this->findIndexByOmiReviewId($merged, $reviewId);
                $wpCommentId = $index !== null
                    ? $this->payloadFactory->syntheticWpCommentId($wpPostId, $index + 1)
                    : $this->payloadFactory->syntheticWpCommentId($wpPostId, count($merged));

                $this->pendingRepository->markReviewed($review, $wpPostId, $wpCommentId);
                $this->invalidateFetchCache($article);

                return [
                    'success' => true,
                    'outcome' => 'CREATED',
                    'message' => 'Đã tạo review trên WordPress.',
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => $wpPostId,
                    'wp_comment_id' => $wpCommentId,
                    'status' => 'reviewed',
                    'deduplicated' => false,
                    'unique_fulfilled' => true,
                ];
            } catch (\Throwable $exception) {
                // Never leave Syncing / never drop the row — keep retryable Failed.
                $class = $exception::class;
                if ($exception instanceof \Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException
                    || str_contains($class, 'WordPressSlugFixRequiredException')
                ) {
                    $errorCode = 'SLUG_FIX_REQUIRED';
                } elseif ($exception instanceof \Omnichannel\Addons\WordPress\Services\SideEffect\UnauthorizedWordPressSideEffectException
                    || str_contains($class, 'UnauthorizedWordPressSideEffectException')
                ) {
                    $errorCode = 'WORDPRESS_SIDE_EFFECT_BLOCKED';
                } else {
                    $errorCode = 'WORDPRESS_REVIEW_CREATE_EXCEPTION';
                }
                $message = mb_substr($exception->getMessage(), 0, 2000);
                $this->pendingRepository->markFailed($review, $errorCode, $message);

                return [
                    'success' => false,
                    'message' => $message !== '' ? $message : 'Tạo review trên WordPress thất bại.',
                    'review_id' => $reviewId,
                    'article_id' => $articleId,
                    'wp_post_id' => $wpPostId,
                    'status' => 'failed',
                    'error_code' => $errorCode,
                ];
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function mapRemoteReview(array $item, int $wpPostId, int $oneBasedIndex): ProductReviewDto
    {
        $author = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách'));
        $content = trim((string) ($item['content'] ?? $item['comment'] ?? ''));
        $email = trim((string) ($item['author_email'] ?? $item['email'] ?? ''));
        $date = trim((string) ($item['date'] ?? $item['review_date'] ?? ''));
        $rating = isset($item['rating']) && is_numeric($item['rating']) ? (int) $item['rating'] : null;
        $omiId = (int) ($item['_omi_review_id'] ?? $item['laravel_review_id'] ?? 0);
        $wpCommentId = isset($item['id']) && is_numeric($item['id'])
            ? (int) $item['id']
            : $this->payloadFactory->syntheticWpCommentId($wpPostId, $oneBasedIndex);

        return new ProductReviewDto(
            id: $omiId > 0 ? $omiId : ('wp:'.$wpCommentId),
            author: $author !== '' ? $author : 'Khách',
            authorEmail: $email !== '' ? $email : null,
            content: $content,
            date: $date !== '' ? $date : null,
            rating: $rating,
            wpCommentId: $wpCommentId,
            source: ((string) ($item['source'] ?? $item['_omi_source'] ?? '') !== '')
                ? (string) ($item['source'] ?? $item['_omi_source'])
                : 'wordpress',
            remote: true,
            raw: $item,
        );
    }

    /**
     * @return array{success: bool, items: list<array<string, mixed>>, message?: string, error_code?: string}
     */
    public function fetchRemoteVirtualItems(SeoArticle $article, int $wpPostId): array
    {
        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'items' => [],
                'message' => 'Bài viết chưa gắn domain.',
                'error_code' => 'MISSING_SITE',
            ];
        }

        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $base = $this->contentService->getPermalinkBase($site);
        if ($readToken === '' || $base === '') {
            return [
                'success' => false,
                'items' => [],
                'message' => 'Thiếu WordPress read token hoặc URL.',
                'error_code' => 'MISSING_WP_CREDENTIALS',
            ];
        }

        $url = $base.'/wp-json/omi-seo-ai/v1/posts/'.$wpPostId.'/comment-reviews';

        try {
            $response = Http::timeout(30)->acceptJson()->withToken($readToken)->get($url);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'items' => [],
                'message' => $e->getMessage(),
                'error_code' => 'WORDPRESS_REVIEW_FETCH_EXCEPTION',
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'items' => [],
                'message' => WordPressRestResponseParser::formatHttpErrorMessage($response->status(), $response),
                'error_code' => 'WORDPRESS_REVIEW_FETCH_FAILED',
            ];
        }

        $body = $response->json();
        $items = is_array($body) ? ($body['items'] ?? []) : [];
        if (! is_array($items)) {
            $items = [];
        }

        $virtualOnly = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (array_key_exists('virtual', $row) && $row['virtual'] !== true) {
                continue;
            }
            $virtualOnly[] = $row;
        }

        return ['success' => true, 'items' => array_values($virtualOnly)];
    }

    private function fetchCacheKey(int $articleId, int $wpPostId): string
    {
        return "wp-product-reviews:{$articleId}:{$wpPostId}";
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
            $omiId = (int) ($row['_omi_review_id'] ?? $row['laravel_review_id'] ?? 0);
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
            if ((int) ($row['_omi_review_id'] ?? $row['laravel_review_id'] ?? 0) === $reviewId) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function findIndexByContentFingerprint(array $items, ArticleProductReview $review): ?int
    {
        $hash = trim((string) $review->content_hash);
        if ($hash === '') {
            $hash = ProductReviewContentFingerprint::hash(
                (string) $review->author_name,
                (string) $review->content,
                $review->rating,
            );
        }
        $author = mb_strtolower(trim((string) $review->author_name));

        foreach ($items as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $remoteAuthor = mb_strtolower(trim((string) ($row['author'] ?? $row['author_name'] ?? '')));
            if ($remoteAuthor !== $author) {
                continue;
            }
            if (ProductReviewContentFingerprint::fromRemoteItem($row) === $hash) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function findIndexByIdempotency(array $items, string $key): ?int
    {
        if ($key === '') {
            return null;
        }

        foreach ($items as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['_omi_idempotency_key'] ?? '') === $key) {
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
                'wordpress.product_review.sync',
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
                'error_code' => 'WORDPRESS_REVIEW_CREATE_EXCEPTION',
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => WordPressRestResponseParser::formatHttpErrorMessage($response->status(), $response),
                'error_code' => 'WORDPRESS_REVIEW_CREATE_FAILED',
                'remote_response' => ['status' => $response->status(), 'body' => $response->body()],
            ];
        }

        $decoded = $response->json();
        if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($decoded['message'] ?? 'WordPress từ chối lưu review.'),
                'error_code' => 'WORDPRESS_REVIEW_CREATE_FAILED',
                'remote_response' => is_array($decoded) ? $decoded : null,
            ];
        }

        return [
            'success' => true,
            'message' => (string) ($decoded['message'] ?? 'ok'),
            'remote_response' => $decoded,
        ];
    }
}
