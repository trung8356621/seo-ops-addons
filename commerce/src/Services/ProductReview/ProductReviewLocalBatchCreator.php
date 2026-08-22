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
 *
 * Generation continues across Create runs: template slot does not reset to 0,
 * and content fingerprints already present for the article are skipped.
 */
final class ProductReviewLocalBatchCreator
{
    /** @var list<string> */
    public const AUTHOR_NAMES = [
        'Lan Anh', 'Minh Tuấn', 'Hồng Nhung', 'Quốc Bảo', 'Thu Hà',
        'Đức Anh', 'Mai Phương', 'Hoàng Long', 'Ngọc Trâm', 'Văn Khoa',
    ];

    /** @var list<string> */
    public const CONTENT_TEMPLATES = [
        'Mình mua %s, chất lượng ổn, giao hàng nhanh.',
        'Dùng %s được vài ngày thấy hài lòng, sẽ ủng hộ tiếp.',
        '%s đúng mô tả, đóng gói cẩn thận.',
        'Giá hợp lý cho %s, nhân viên hỗ trợ nhiệt tình.',
        'Đánh giá tốt về %s — đáng tiền.',
    ];

    private const MAX_ATTEMPTS_MULTIPLIER = 5;

    public function __construct(
        private readonly CommentReviewRatingAssigner $ratingAssigner,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     created_count: int,
     *     pending_review_ids: list<int>,
     *     generation_batch_id: string|null,
     *     requested_count: int,
     *     skipped_duplicate_slots: int
     * }
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
                'requested_count' => 0,
                'skipped_duplicate_slots' => 0,
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
                'requested_count' => $count,
                'skipped_duplicate_slots' => 0,
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
                'requested_count' => $count,
                'skipped_duplicate_slots' => 0,
            ];
        }

        $batchId = $generationBatchId !== null && $generationBatchId !== ''
            ? $generationBatchId
            : (string) Str::uuid();
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $title = trim((string) ($article->title ?? 'sản phẩm'));
        $articleId = (int) $article->id;

        $usedHashes = $this->existingContentHashes($articleId);
        $startSlot = $this->nextGenerationSlot($articleId);
        $createdIds = [];
        $skippedDuplicates = 0;

        $maxAttempts = max($count * self::MAX_ATTEMPTS_MULTIPLIER, $count + 10);
        $comboSpace = count(self::AUTHOR_NAMES) * count(self::CONTENT_TEMPLATES) * 3;
        $maxAttempts = min($maxAttempts, $comboSpace + $count);

        DB::connection('omi_seo_ai')->transaction(function () use (
            $articleId,
            $count,
            $connectionId,
            $siteId,
            $wpPostId,
            $batchId,
            $title,
            $startSlot,
            $maxAttempts,
            &$usedHashes,
            &$createdIds,
            &$skippedDuplicates,
        ): void {
            $slot = $startSlot;
            $attempts = 0;

            while (count($createdIds) < $count && $attempts < $maxAttempts) {
                $attempts++;
                $author = $this->authorName($slot);
                $content = $this->contentFor($title, $slot);
                $rating = $this->ratingAssigner->resolve(null, $slot);
                $contentHash = ProductReviewContentFingerprint::hash($author, $content, $rating);

                if (isset($usedHashes[$contentHash])) {
                    $skippedDuplicates++;
                    $slot++;

                    continue;
                }

                $idempotencyKey = hash(
                    'sha256',
                    implode('|', [
                        $siteId,
                        $connectionId,
                        $articleId,
                        $wpPostId,
                        $contentHash,
                    ]),
                );

                $existingByKey = ArticleProductReview::query()
                    ->where('connection_id', $connectionId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('status', '!=', ArticleProductReviewStatus::Cancelled->value)
                    ->first();
                if ($existingByKey instanceof ArticleProductReview) {
                    $usedHashes[$contentHash] = true;
                    $skippedDuplicates++;
                    $slot++;

                    continue;
                }

                $review = ArticleProductReview::query()->create([
                    'article_id' => $articleId,
                    'site_id' => $siteId,
                    'connection_id' => $connectionId,
                    'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                    'author_name' => $author,
                    'author_email' => null,
                    'content' => $content,
                    'rating' => $rating,
                    'review_date' => now()->subDays(max(1, $count - count($createdIds))),
                    'source' => 'seo_content_ai',
                    'status' => ArticleProductReviewStatus::Pending,
                    'publish_attempts' => 0,
                    'content_hash' => $contentHash,
                    'idempotency_key' => $idempotencyKey,
                    'generation_batch_id' => $batchId,
                ]);

                $usedHashes[$contentHash] = true;
                $createdIds[] = (int) $review->id;
                $slot++;
            }
        });

        $created = count($createdIds);
        $message = $created === $count
            ? sprintf('Đã tạo %d review pending.', $created)
            : sprintf(
                'Đã tạo %d/%d review unique pending (bỏ qua %d slot trùng).',
                $created,
                $count,
                $skippedDuplicates,
            );

        return [
            'success' => true,
            'message' => $message,
            'created_count' => $created,
            'pending_review_ids' => $createdIds,
            'generation_batch_id' => $batchId,
            'requested_count' => $count,
            'skipped_duplicate_slots' => $skippedDuplicates,
        ];
    }

    public function authorName(int $index): string
    {
        return self::AUTHOR_NAMES[$index % count(self::AUTHOR_NAMES)];
    }

    public function contentFor(string $title, int $index): string
    {
        $template = self::CONTENT_TEMPLATES[$index % count(self::CONTENT_TEMPLATES)];

        return sprintf($template, $title !== '' ? $title : 'sản phẩm');
    }

    /**
     * Next template slot = number of non-cancelled generated rows (continuous across Create runs).
     */
    public function nextGenerationSlot(int $articleId): int
    {
        return ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->where('status', '!=', ArticleProductReviewStatus::Cancelled->value)
            ->where(function ($query): void {
                $query->whereIn('source', ['seo_content_ai', 'ai_generated', 'laravel'])
                    ->orWhereNotNull('generation_batch_id');
            })
            ->count();
    }

    /**
     * @return array<string, true>
     */
    public function existingContentHashes(int $articleId): array
    {
        $hashes = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->where('status', '!=', ArticleProductReviewStatus::Cancelled->value)
            ->whereNotNull('content_hash')
            ->pluck('content_hash')
            ->all();

        $out = [];
        foreach ($hashes as $hash) {
            $key = trim((string) $hash);
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return $out;
    }
}
