<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;

/**
 * WordPress = source of truth for display status. Uses shared creation policy.
 */
final class WordPressProductReviewStatusService
{
    public function __construct(
        private readonly WordPressProductReviewService $wordpressReviews,
        private readonly ProductReviewCreationPolicy $policy,
    ) {}

    /**
     * @param  array{target_count?: int, block_if_real_reviews_exist?: bool, enabled?: bool}  $settings
     * @return array<string, mixed>
     */
    public function statusForArticle(SeoArticle $article, array $settings = [], bool $fresh = false): array
    {
        $articleId = (int) $article->id;
        $postType = ArticlePostTypeResolver::resolve($article);
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null;
        $local = $this->policy->localCounts($article);
        $checkedAt = now()->toIso8601String();

        if ($postType !== 'product') {
            $decision = $this->policy->evaluate($article, [
                'wordpress_connected' => false,
                'fetch_success' => false,
                'wordpress_real_review_count' => 0,
                'wordpress_generated_review_count' => 0,
            ], $local, $settings);

            return $this->payload(
                articleId: $articleId,
                postType: $postType,
                wpPostId: $wpPostId,
                connected: false,
                real: 0,
                generated: 0,
                total: 0,
                local: $local,
                decision: $decision,
                checkedAt: $checkedAt,
                warning: null,
            );
        }

        if ($wpPostId === null || $wpPostId <= 0) {
            $decision = $this->policy->evaluate($article, [
                'wordpress_connected' => false,
                'fetch_success' => false,
                'wordpress_real_review_count' => 0,
                'wordpress_generated_review_count' => 0,
            ], $local, $settings);

            return $this->payload(
                articleId: $articleId,
                postType: $postType,
                wpPostId: null,
                connected: false,
                real: 0,
                generated: 0,
                total: 0,
                local: $local,
                decision: $decision,
                checkedAt: $checkedAt,
                warning: 'Product chưa có trên WordPress — chưa kiểm tra được comment gốc.',
            );
        }

        $fetch = $this->wordpressReviews->fetchForProduct($article, useCache: ! $fresh);
        if (! ($fetch['success'] ?? false)) {
            $decision = $this->policy->evaluate($article, [
                'wordpress_connected' => false,
                'fetch_success' => false,
                'wordpress_real_review_count' => 0,
                'wordpress_generated_review_count' => 0,
            ], $local, $settings);

            return $this->payload(
                articleId: $articleId,
                postType: $postType,
                wpPostId: $wpPostId,
                connected: false,
                real: 0,
                generated: 0,
                total: 0,
                local: $local,
                decision: $decision,
                checkedAt: $checkedAt,
                warning: (string) ($fetch['message'] ?? 'Không thể tải đánh giá từ WordPress.'),
            );
        }

        $reviews = is_array($fetch['reviews'] ?? null) ? $fetch['reviews'] : [];
        $real = 0;
        $generated = 0;
        foreach ($reviews as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->isGeneratedReview($row)) {
                $generated++;
            } else {
                $real++;
            }
        }

        $decision = $this->policy->evaluate($article, [
            'wordpress_connected' => true,
            'fetch_success' => true,
            'wordpress_real_review_count' => $real,
            'wordpress_generated_review_count' => $generated,
            'wordpress_review_count' => count($reviews),
        ], $local, $settings);

        return $this->payload(
            articleId: $articleId,
            postType: $postType,
            wpPostId: $wpPostId,
            connected: true,
            real: $real,
            generated: $generated,
            total: count($reviews),
            local: $local,
            decision: $decision,
            checkedAt: is_string($fetch['synced_at'] ?? null) ? $fetch['synced_at'] : $checkedAt,
            warning: ($fetch['cached'] ?? false) ? 'Đang hiển thị dữ liệu gần nhất.' : null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function isGeneratedReview(array $row): bool
    {
        $raw = is_array($row['raw'] ?? null) ? $row['raw'] : $row;
        if (($raw['generated'] ?? false) === true || ($row['generated'] ?? false) === true) {
            return true;
        }

        $source = (string) ($raw['source'] ?? $raw['_omi_source'] ?? $row['source'] ?? '');
        if ($source === 'seo_content_ai' || $source === 'laravel') {
            return true;
        }

        if ((int) ($raw['_omi_review_id'] ?? $raw['laravel_review_id'] ?? 0) > 0) {
            return true;
        }

        if ((string) ($raw['_omi_idempotency_key'] ?? '') !== '') {
            return true;
        }

        return false;
    }

    /**
     * @param  array{
     *     local_pending_count: int,
     *     local_reviewed_count: int,
     *     local_generated_count?: int,
     *     local_real_count?: int
     * }  $local
     * @return array<string, mixed>
     */
    private function payload(
        int $articleId,
        string $postType,
        ?int $wpPostId,
        bool $connected,
        int $real,
        int $generated,
        int $total,
        array $local,
        Data\ProductReviewCreationDecision $decision,
        string $checkedAt,
        ?string $warning,
    ): array {
        return [
            'article_id' => $articleId,
            'post_type' => $postType,
            'wp_post_id' => $wpPostId,
            'applicable' => $postType === 'product',
            'status' => $this->resolvePublicStatus($postType, $wpPostId, $connected),
            'count' => $total,
            'wordpress_connected' => $connected,
            'wordpress_review_count' => $total,
            'wordpress_real_review_count' => $real,
            'wordpress_generated_review_count' => $generated,
            // Canonical editor counters (not WP-only).
            'generated_count' => max($generated, (int) ($local['local_generated_count'] ?? 0)),
            'local_pending_count' => $local['local_pending_count'],
            'local_reviewed_count' => $local['local_reviewed_count'],
            'local_generated_count' => $local['local_generated_count'] ?? 0,
            'local_real_count' => $local['local_real_count'] ?? 0,
            'unique_fulfilled_count' => $local['unique_fulfilled_count'] ?? $local['local_reviewed_count'],
            'local_reviewed_row_count' => $local['local_reviewed_row_count'] ?? $local['local_reviewed_count'],
            'local_generated_row_count' => $local['local_generated_row_count'] ?? $local['local_generated_count'] ?? 0,
            'syncable_pending_count' => $local['local_pending_count'],
            'can_create_reviews' => $decision->allowed,
            'create_block_reason' => $decision->reason,
            'create_block_reason_label' => ProductReviewCreationPolicy::reasonLabel($decision->reason),
            'recommended_action' => $decision->recommendedAction,
            'target_count' => $decision->targetCount,
            'missing_count' => $decision->missingCount,
            'checked_at' => $checkedAt,
            'warning' => $warning,
            'policy' => $decision->toArray(),
        ];
    }

    private function resolvePublicStatus(
        string $postType,
        ?int $wpPostId,
        bool $connected,
    ): ?string {
        if ($postType !== 'product') {
            return null;
        }

        if ($wpPostId === null || $wpPostId <= 0) {
            return 'not_synced';
        }

        if (! $connected) {
            return 'wordpress_unavailable';
        }

        return 'ok';
    }
}
