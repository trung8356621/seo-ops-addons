<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\Data\ProductReviewCreationDecision;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;

/**
 * Single policy for Edit Article status, automation create action, manual create.
 *
 * Idempotent create: target_count = maintain total UNIQUE AI reviews, not "create N each run".
 * Counters use unique content_hash / unique wp_comment_id — not raw row counts.
 */
final class ProductReviewCreationPolicy
{
    public const DEFAULT_TARGET_COUNT = 10;

    /** @var list<string> */
    private const GENERATED_SOURCES = ['seo_content_ai', 'ai_generated', 'laravel'];

    /**
     * @param  array{
     *     wordpress_connected?: bool,
     *     wordpress_real_review_count?: int,
     *     wordpress_generated_review_count?: int,
     *     wordpress_review_count?: int,
     *     fetch_success?: bool
     * }  $wordpressState
     * @param  array{
     *     local_pending_count?: int,
     *     local_reviewed_count?: int,
     *     local_generated_count?: int,
     *     local_real_count?: int,
     *     unique_fulfilled_count?: int
     * }  $localState
     * @param  array{target_count?: int, block_if_real_reviews_exist?: bool, enabled?: bool}  $settings
     */
    public function evaluate(
        SeoArticle $article,
        array $wordpressState,
        array $localState = [],
        array $settings = [],
    ): ProductReviewCreationDecision {
        $target = max(0, (int) ($settings['target_count'] ?? self::DEFAULT_TARGET_COUNT));
        $blockIfReal = ($settings['block_if_real_reviews_exist'] ?? true) !== false;
        $featureEnabled = ($settings['enabled'] ?? true) !== false;

        $wpReal = max(0, (int) ($wordpressState['wordpress_real_review_count'] ?? 0));
        $wpGenerated = max(0, (int) ($wordpressState['wordpress_generated_review_count'] ?? 0));
        $localGenerated = max(0, (int) ($localState['local_generated_count'] ?? 0));
        $localReal = max(0, (int) ($localState['local_real_count'] ?? 0));
        $pendingCount = max(0, (int) ($localState['local_pending_count'] ?? 0));
        $uniqueFulfilled = max(0, (int) ($localState['unique_fulfilled_count'] ?? 0));

        // Unique generated = max(WP unique generated, local unique content fingerprints).
        $generatedCount = max($wpGenerated, $localGenerated, $uniqueFulfilled);
        $realCount = max($wpReal, $localReal);

        if (! $featureEnabled) {
            return $this->blocked('feature_disabled', $target, 0, $realCount, $generatedCount, $pendingCount);
        }

        if (ArticlePostTypeResolver::resolve($article) !== 'product') {
            return $this->blocked('not_product', $target, 0, $realCount, $generatedCount, $pendingCount);
        }

        $wpConnected = (bool) ($wordpressState['wordpress_connected'] ?? false);
        $fetchOk = ($wordpressState['fetch_success'] ?? true) !== false;
        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) > 0 && (! $wpConnected || ! $fetchOk)) {
            return $this->blocked('wordpress_unavailable', $target, 0, $realCount, $generatedCount, $pendingCount);
        }

        if ($blockIfReal && $realCount > 0) {
            return $this->blocked('wordpress_real_reviews_exist', $target, 0, $realCount, $generatedCount, $pendingCount, 'none');
        }

        $missing = max(0, $target - $generatedCount);
        if ($missing <= 0) {
            return $this->blocked('target_count_reached', $target, 0, $realCount, $generatedCount, $pendingCount, 'none');
        }

        return new ProductReviewCreationDecision(
            allowed: true,
            reason: null,
            targetCount: $target,
            missingCount: $missing,
            recommendedAction: 'create',
            wordpressRealReviewCount: $realCount,
            wordpressGeneratedReviewCount: $generatedCount,
            localPendingCount: $pendingCount,
        );
    }

    /**
     * @return array{
     *     local_pending_count: int,
     *     local_reviewed_count: int,
     *     local_generated_count: int,
     *     local_real_count: int,
     *     unique_fulfilled_count: int,
     *     local_reviewed_row_count: int,
     *     local_generated_row_count: int
     * }
     */
    public function localCounts(SeoArticle $article): array
    {
        $articleId = (int) $article->id;

        $pendingStatuses = [
            ArticleProductReviewStatus::Pending->value,
            ArticleProductReviewStatus::Syncing->value,
            ArticleProductReviewStatus::Failed->value,
            ArticleProductReviewStatus::Draft->value,
            ArticleProductReviewStatus::PendingArticle->value,
            ArticleProductReviewStatus::PendingPublish->value,
            ArticleProductReviewStatus::Scheduled->value,
            ArticleProductReviewStatus::Publishing->value,
            ArticleProductReviewStatus::FailedDispatch->value,
        ];

        $pending = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereIn('status', $pendingStatuses)
            ->count();

        $reviewedRows = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereIn('status', [
                ArticleProductReviewStatus::Reviewed->value,
                ArticleProductReviewStatus::Published->value,
            ])
            ->whereNotNull('wp_comment_id')
            ->where('wp_comment_id', '!=', 0)
            ->get(['id', 'content_hash', 'wp_comment_id']);

        $uniqueRemoteIds = [];
        $uniqueReviewedHashes = [];
        foreach ($reviewedRows as $row) {
            $remoteId = (int) ($row->wp_comment_id ?? 0);
            if ($remoteId !== 0) {
                $uniqueRemoteIds[(string) $remoteId] = true;
            }
            $hash = trim((string) ($row->content_hash ?? ''));
            if ($hash !== '') {
                $uniqueReviewedHashes[$hash] = true;
            }
        }
        $reviewedUnique = max(count($uniqueRemoteIds), count($uniqueReviewedHashes));

        $generatedHashes = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereNotIn('status', [ArticleProductReviewStatus::Cancelled->value])
            ->where(function ($query): void {
                $query->whereIn('source', self::GENERATED_SOURCES)
                    ->orWhereNotNull('generation_batch_id');
            })
            ->whereNotNull('content_hash')
            ->distinct()
            ->count('content_hash');

        $generatedRows = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereNotIn('status', [ArticleProductReviewStatus::Cancelled->value])
            ->where(function ($query): void {
                $query->whereIn('source', self::GENERATED_SOURCES)
                    ->orWhereNotNull('generation_batch_id');
            })
            ->count();

        $real = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereNotIn('status', [ArticleProductReviewStatus::Cancelled->value])
            ->whereNull('generation_batch_id')
            ->where(function ($query): void {
                $query->whereNull('source')
                    ->orWhereNotIn('source', self::GENERATED_SOURCES);
            })
            ->count();

        // Fulfilled unique = unique remote ids among reviewed (preferred) or unique reviewed hashes.
        $uniqueFulfilled = $reviewedUnique;

        return [
            'local_pending_count' => $pending,
            'local_reviewed_count' => $reviewedUnique,
            'local_generated_count' => $generatedHashes,
            'local_real_count' => $real,
            'unique_fulfilled_count' => $uniqueFulfilled,
            'local_reviewed_row_count' => $reviewedRows->count(),
            'local_generated_row_count' => $generatedRows,
        ];
    }

    /**
     * Cancel duplicate local rows that share content_hash with an older reviewed canonical row.
     *
     * @return array{cancelled: list<int>, kept: list<int>}
     */
    public function cancelDuplicateReviewedRows(SeoArticle $article): array
    {
        $articleId = (int) $article->id;
        $rows = ArticleProductReview::query()
            ->where('article_id', $articleId)
            ->whereIn('status', [
                ArticleProductReviewStatus::Reviewed->value,
                ArticleProductReviewStatus::Published->value,
            ])
            ->whereNotNull('content_hash')
            ->orderBy('id')
            ->get();

        $keptByHash = [];
        $cancelled = [];
        $kept = [];

        foreach ($rows as $row) {
            /** @var ArticleProductReview $row */
            $hash = trim((string) $row->content_hash);
            if ($hash === '') {
                $kept[] = (int) $row->id;
                continue;
            }
            if (! isset($keptByHash[$hash])) {
                $keptByHash[$hash] = (int) $row->id;
                $kept[] = (int) $row->id;
                continue;
            }
            $row->status = ArticleProductReviewStatus::Cancelled;
            $row->last_error_code = 'DUPLICATE_CONTENT';
            $row->last_error_message = sprintf(
                'Duplicate content_hash of canonical_review_id=%d',
                $keptByHash[$hash],
            );
            $row->save();
            $cancelled[] = (int) $row->id;
        }

        return ['cancelled' => $cancelled, 'kept' => $kept];
    }

    private function blocked(
        string $reason,
        int $target,
        int $missing,
        int $real,
        int $generated,
        int $pending,
        string $recommended = 'none',
    ): ProductReviewCreationDecision {
        return new ProductReviewCreationDecision(
            allowed: false,
            reason: $reason,
            targetCount: $target,
            missingCount: $missing,
            recommendedAction: $recommended,
            wordpressRealReviewCount: $real,
            wordpressGeneratedReviewCount: $generated,
            localPendingCount: $pending,
        );
    }
}
