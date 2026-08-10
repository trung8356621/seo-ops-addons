<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\CommentReviewRatingAssigner;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create local pending product reviews only (no WordPress).
 */
final class ProductReviewLocalBatchCreator
{
    public function __construct(
        private readonly CommentReviewRatingAssigner $ratingAssigner,
    ) {}

    /**
     * @return array{success: bool, message: string, created_count: int, pending_review_ids: list<int>, generation_batch_id: string|null}
     */
    public function createPendingBatch(SeoArticle $article, int $count, ?string $generationBatchId = null): array
    {
        $count = max(0, $count);
        if ($count === 0) {
            return [
                'success' => true,
                'message' => 'No reviews to create.',
                'created_count' => 0,
                'pending_review_ids' => [],
                'generation_batch_id' => null,
            ];
        }

        $connectionId = (int) (SeoConnectionContext::current()?->id ?? 0);
        if ($connectionId <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu SEO connection context.',
                'created_count' => 0,
                'pending_review_ids' => [],
                'generation_batch_id' => null,
            ];
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain (site_id).',
                'created_count' => 0,
                'pending_review_ids' => [],
                'generation_batch_id' => null,
            ];
        }

        $batchId = $generationBatchId !== null && $generationBatchId !== ''
            ? $generationBatchId
            : (string) Str::uuid();
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $title = trim((string) ($article->title ?? 'sản phẩm'));
        $createdIds = [];

        DB::connection('omi_seo_ai')->transaction(function () use (
            $article,
            $count,
            $connectionId,
            $siteId,
            $wpPostId,
            $batchId,
            $title,
            &$createdIds,
        ): void {
            for ($i = 0; $i < $count; $i++) {
                $author = $this->authorName($i);
                $content = $this->contentFor($title, $i);
                $rating = $this->ratingAssigner->resolve(null, $i);
                $contentHash = hash('sha256', mb_strtolower($author)."\0".mb_strtolower($content)."\0".(string) $rating);
                $idempotencyKey = hash(
                    'sha256',
                    implode('|', [
                        $siteId,
                        $connectionId,
                        (int) $article->id,
                        $wpPostId,
                        $batchId,
                        $contentHash,
                        (string) $i,
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
                    'author_email' => null,
                    'content' => $content,
                    'rating' => $rating,
                    'review_date' => now()->subDays($count - $i),
                    'source' => 'seo_content_ai',
                    'status' => ArticleProductReviewStatus::Pending,
                    'publish_attempts' => 0,
                    'content_hash' => $contentHash,
                    'idempotency_key' => $idempotencyKey,
                    'generation_batch_id' => $batchId,
                ]);

                $createdIds[] = (int) $review->id;
            }
        });

        return [
            'success' => true,
            'message' => sprintf('Đã tạo %d review pending.', count($createdIds)),
            'created_count' => count($createdIds),
            'pending_review_ids' => $createdIds,
            'generation_batch_id' => $batchId,
        ];
    }

    private function authorName(int $index): string
    {
        $names = ['Lan Anh', 'Minh Tuấn', 'Hồng Nhung', 'Quốc Bảo', 'Thu Hà', 'Đức Anh', 'Mai Phương', 'Hoàng Long', 'Ngọc Trâm', 'Văn Khoa'];

        return $names[$index % count($names)];
    }

    private function contentFor(string $title, int $index): string
    {
        $templates = [
            'Mình mua %s, chất lượng ổn, giao hàng nhanh.',
            'Dùng %s được vài ngày thấy hài lòng, sẽ ủng hộ tiếp.',
            '%s đúng mô tả, đóng gói cẩn thận.',
            'Giá hợp lý cho %s, nhân viên hỗ trợ nhiệt tình.',
            'Đánh giá tốt về %s — đáng tiền.',
        ];

        return sprintf($templates[$index % count($templates)], $title !== '' ? $title : 'sản phẩm');
    }
}
