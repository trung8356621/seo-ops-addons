<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewAutomationSettingsResolver;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewCreationPolicy;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewLocalBatchCreator;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewPendingRepository;
use Omnichannel\Addons\Commerce\Services\ProductReview\WordPressProductReviewService;
use Omnichannel\Addons\Commerce\Services\ProductReview\WordPressProductReviewStatusService;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;

/**
 * Manual entry: same business sequence as linear rule actions
 * wordpress.article.sync → product-review.create → product-review.sync-wp
 * without Automation Rule gate.
 */
final class ArticleWordPressBusinessSequence
{
    public function __construct(
        private readonly SyncArticleToWordPressPipeline $articlePipeline,
        private readonly WordPressProductReviewStatusService $statusService,
        private readonly ProductReviewCreationPolicy $policy,
        private readonly ProductReviewLocalBatchCreator $batchCreator,
        private readonly WordPressProductReviewService $reviewService,
        private readonly ProductReviewPendingRepository $pendingRepository,
        private readonly ProductReviewAutomationSettingsResolver $reviewSettingsResolver,
    ) {}

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @param  array{target_count?: int, block_if_real_reviews_exist?: bool, enabled?: bool, retry_failed?: bool}  $reviewSettings
     * @return array<string, mixed>
     */
    public function run(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        string $mode = 'sync',
        ?array $seoOverride = null,
        ?string $slug = null,
        bool $syncProductReviews = true,
        array $reviewSettings = [],
    ): array {
        $articleResult = $this->articlePipeline->run($article, $sideEffect, $mode, $seoOverride, $slug);
        if (! ($articleResult['success'] ?? false)) {
            return array_merge($articleResult, [
                'product_review_create' => ['status' => 'skipped', 'reason' => 'article_sync_failed'],
                'product_review_sync' => ['status' => 'skipped', 'reason' => 'article_sync_failed'],
            ]);
        }

        $article = $article->fresh() ?? $article;
        if (! $syncProductReviews || ! in_array($mode, ['sync', 'publish'], true)) {
            return array_merge($articleResult, [
                'product_review_create' => ['status' => 'skipped', 'reason' => 'sync_product_reviews_false'],
                'product_review_sync' => ['status' => 'skipped', 'reason' => 'sync_product_reviews_false'],
            ]);
        }

        $create = $this->runCreate($article, $this->reviewSettingsResolver->resolve($reviewSettings));
        $sync = $this->runSync($article, $sideEffect, $this->reviewSettingsResolver->resolve($reviewSettings));

        return array_merge($articleResult, [
            'product_review_create' => $create,
            'product_review_sync' => $sync,
        ]);
    }

    /**
     * @param  array{target_count?: int, block_if_real_reviews_exist?: bool, enabled?: bool}  $settings
     * @return array<string, mixed>
     */
    public function runCreate(SeoArticle $article, array $settings = []): array
    {
        $settings = $this->reviewSettingsResolver->resolve($settings);
        $status = $this->statusService->statusForArticle($article, $settings);
        $local = $this->policy->localCounts($article);
        $decision = $this->policy->evaluate(
            $article,
            [
                'wordpress_connected' => (bool) ($status['wordpress_connected'] ?? false),
                'fetch_success' => ($status['warning'] ?? null) === null || (bool) ($status['wordpress_connected'] ?? false),
                'wordpress_real_review_count' => (int) ($status['wordpress_real_review_count'] ?? 0),
                'wordpress_generated_review_count' => (int) ($status['wordpress_generated_review_count'] ?? 0),
            ],
            $local,
            $settings,
        );

        if (! $decision->allowed) {
            return [
                'article_id' => (int) $article->id,
                'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                'created_count' => 0,
                'pending_review_ids' => [],
                'status' => 'skipped',
                'reason' => $decision->reason,
                'policy' => $decision->toArray(),
            ];
        }

        $batch = $this->batchCreator->createPendingBatch($article, $decision->missingCount);

        return [
            'article_id' => (int) $article->id,
            'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
            'created_count' => (int) ($batch['created_count'] ?? 0),
            'pending_review_ids' => is_array($batch['pending_review_ids'] ?? null) ? $batch['pending_review_ids'] : [],
            'generation_batch_id' => $batch['generation_batch_id'] ?? null,
            'status' => ($batch['success'] ?? false) ? 'completed' : 'failed',
            'message' => $batch['message'] ?? null,
            'policy' => $decision->toArray(),
        ];
    }

    /**
     * @param  array{retry_failed?: bool, enabled?: bool}  $settings
     * @return array<string, mixed>
     */
    public function runSync(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        array $settings = [],
    ): array {
        if (($settings['enabled'] ?? true) === false) {
            return [
                'article_id' => (int) $article->id,
                'status' => 'skipped',
                'reason' => 'feature_disabled',
            ];
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'article_id' => (int) $article->id,
                'status' => 'skipped',
                'reason' => 'missing_wp_post_id',
            ];
        }

        $created = [];
        $deduplicated = [];
        $duplicateCancelled = [];
        $failed = [];
        $skipped = [];

        foreach ($this->pendingRepository->pendingForArticle($article) as $review) {
            if ($this->pendingRepository->shouldSkipCreate($review)) {
                $skipped[] = (int) $review->id;
                continue;
            }

            $result = $this->reviewService->create($review, $article, $sideEffect);
            $id = (int) $review->id;
            if (! ($result['success'] ?? false)) {
                $failed[] = [
                    'review_id' => $id,
                    'error_code' => $result['error_code'] ?? null,
                    'message' => (string) ($result['message'] ?? 'failed'),
                ];
                continue;
            }

            if (($result['outcome'] ?? '') === 'DUPLICATE_CANCELLED') {
                $duplicateCancelled[] = $id;
            } elseif (($result['deduplicated'] ?? false) || ($result['outcome'] ?? '') === 'DEDUPLICATED') {
                $deduplicated[] = $id;
            } elseif (($result['outcome'] ?? '') === 'SKIPPED_REVIEWED') {
                $skipped[] = $id;
            } else {
                $created[] = $id;
            }
        }

        $this->reviewService->invalidateFetchCache($article);

        return [
            'article_id' => (int) $article->id,
            'wp_post_id' => $wpPostId,
            'status' => $failed === [] ? 'completed' : 'partial',
            'created' => $created,
            'deduplicated' => $deduplicated,
            'duplicate_cancelled' => $duplicateCancelled,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }
}
