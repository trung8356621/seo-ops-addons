<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\WordPress\Support\CommentReviewPayloadParser;
use Omnichannel\Addons\Seo\Support\CommentReviewRatingAssigner;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\WordPress\Services\VirtualCommentService;
use Illuminate\Support\Facades\DB;

/**
 * Normalize AI/UI input → local pending article_product_reviews.
 * Never calls WordPress. Sync owned by SyncArticleToWordPressPipeline.
 */
final class ArticleProductReviewStoreService
{
    public function __construct(
        private readonly CommentReviewPayloadParser $parser,
        private readonly VirtualCommentService $virtualComments,
        private readonly CommentReviewRatingAssigner $ratingAssigner,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     created_count: int,
     *     review_ids: list<int>,
     *     automation_enabled?: bool,
     *     has_wp_post_id?: bool
     * }
     */
    public function storeFromAiOutput(SeoArticle $article, string $aiOutput, string $source = 'ai_generated'): array
    {
        $items = $this->parser->parse($aiOutput);
        if ($items === []) {
            return [
                'success' => false,
                'message' => 'Không parse được bình luận/review từ kết quả AI.',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        return $this->storeItems($article, $items, $source);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     success: bool,
     *     message: string,
     *     created_count: int,
     *     review_ids: list<int>,
     *     automation_enabled?: bool,
     *     has_wp_post_id?: bool
     * }
     */
    public function storeItems(SeoArticle $article, array $items, string $source = 'ai_generated'): array
    {
        $connectionId = (int) (SeoConnectionContext::current()?->id ?? 0);
        if ($connectionId <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu SEO connection context — không lưu được product review.',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain (site_id).',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $status = ArticleProductReviewStatus::Pending;

        $isProduct = ArticlePostTypeResolver::resolve($article) === 'product';
        $createdIds = [];

        DB::connection('omi_seo_ai')->transaction(function () use (
            $article,
            $items,
            $source,
            $connectionId,
            $siteId,
            $wpPostId,
            $status,
            $isProduct,
            &$createdIds,
        ): void {
            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $content = trim((string) ($item['content'] ?? $item['comment'] ?? $item['review'] ?? ''));
                if ($content === '') {
                    continue;
                }

                $author = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách mua hàng'));
                if ($author === '') {
                    $author = 'Khách mua hàng';
                }

                $email = trim((string) ($item['email'] ?? $item['author_email'] ?? ''));
                $email = $email !== '' ? $email : null;

                $explicitRating = null;
                if (isset($item['rating']) && is_numeric($item['rating'])) {
                    $explicitRating = (int) $item['rating'];
                }
                $rating = $isProduct
                    ? $this->ratingAssigner->resolve($explicitRating, $index)
                    : ($explicitRating !== null ? max(1, min(5, $explicitRating)) : null);

                $reviewDate = trim((string) ($item['date'] ?? ''));
                $contentHash = hash('sha256', mb_strtolower($author)."\0".mb_strtolower($content)."\0".(string) $rating);
                $idempotencyKey = hash(
                    'sha256',
                    implode('|', [
                        $siteId,
                        $connectionId,
                        (int) $article->id,
                        $wpPostId,
                        $contentHash,
                        mb_strtolower($author),
                        (string) ($email ?? ''),
                        $source,
                    ]),
                );

                $existing = ArticleProductReview::query()
                    ->where('connection_id', $connectionId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing instanceof ArticleProductReview) {
                    $createdIds[] = (int) $existing->id;
                    continue;
                }

                $review = ArticleProductReview::query()->create([
                    'article_id' => (int) $article->id,
                    'site_id' => $siteId,
                    'connection_id' => $connectionId,
                    'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                    'author_name' => $author,
                    'author_email' => $email,
                    'content' => $content,
                    'rating' => $rating,
                    'review_date' => $reviewDate !== '' ? $reviewDate : now(),
                    'source' => $source,
                    'status' => $status,
                    'publish_attempts' => 0,
                    'content_hash' => $contentHash,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $createdIds[] = (int) $review->id;
            }

            // Optional local mirror for legacy readers — not source of truth.
            $mirrorItems = ArticleProductReview::query()
                ->where('article_id', (int) $article->id)
                ->whereIn('status', [
                    ArticleProductReviewStatus::Pending->value,
                    ArticleProductReviewStatus::Syncing->value,
                    ArticleProductReviewStatus::Failed->value,
                ])
                ->orderBy('id')
                ->get()
                ->map(static function (ArticleProductReview $review): array {
                    $row = [
                        'author' => $review->author_name,
                        'content' => $review->content,
                        'date' => $review->review_date?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                    ];
                    if ($review->rating !== null) {
                        $row['rating'] = (int) $review->rating;
                    }

                    return $row;
                })
                ->all();

            $this->virtualComments->storeOnArticle($article, $mirrorItems, $isProduct);
        });

        if ($createdIds === []) {
            return [
                'success' => false,
                'message' => 'Không có mục bình luận/review hợp lệ để lưu.',
                'created_count' => 0,
                'review_ids' => [],
            ];
        }

        $article = $article->fresh() ?? $article;
        $kind = $isProduct ? 'review tạm' : 'bình luận tạm';
        $message = $wpPostId > 0
            ? sprintf('Đã lưu %d %s. Sẽ đồng bộ WordPress ở lần sync bài tiếp theo.', count($createdIds), $kind)
            : sprintf('Đã lưu %d %s. Sẽ đồng bộ sau khi product có trên WordPress.', count($createdIds), $kind);

        return [
            'success' => true,
            'message' => $message,
            'created_count' => count($createdIds),
            'review_ids' => $createdIds,
            'automation_enabled' => true,
            'has_wp_post_id' => $wpPostId > 0,
        ];
    }

    /**
     * Pending local rows only — WordPress is source of truth for synced reviews.
     *
     * @return list<array<string, mixed>>
     */
    public function listForEditor(SeoArticle $article): array
    {
        return ArticleProductReview::query()
            ->where('article_id', (int) $article->id)
            ->whereIn('status', [
                ArticleProductReviewStatus::Pending->value,
                ArticleProductReviewStatus::Syncing->value,
                ArticleProductReviewStatus::Failed->value,
            ])
            ->orderBy('id')
            ->get()
            ->map(static fn (ArticleProductReview $r): array => $r->toEditorArray())
            ->all();
    }

    /**
     * Cheap existence check (no row hydration) — for mount-time review_status
     * checks that don't need the full row payload (Phase 2 perf).
     */
    public function hasPendingReviews(SeoArticle $article): bool
    {
        return ArticleProductReview::query()
            ->where('article_id', (int) $article->id)
            ->whereIn('status', [
                ArticleProductReviewStatus::Pending->value,
                ArticleProductReviewStatus::Syncing->value,
                ArticleProductReviewStatus::Failed->value,
            ])
            ->exists();
    }

    public function isPublishAutomationEnabled(): bool
    {
        try {
            return \Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule::query()
                ->where('code', 'sync-article-to-wordpress')
                ->where('is_enabled', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @deprecated Reviews sync inside SyncArticleToWordPressPipeline.
     *
     * @return list<int>
     */
    public function queuePendingForArticle(SeoArticle $article, string $publishIntent = 'publish_after_article'): array
    {
        return [];
    }
}
