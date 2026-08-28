<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewCreationPolicy;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use ReflectionClass;
use Tests\Support\ProjectRoot;
use Tests\TestCase as LaravelTestCase;

/**
 * Sync WordPress → auto create/sync product reviews (idempotent missing math + rewrite path).
 */
final class ProductReviewAutoSyncOnWordPressSyncTest extends LaravelTestCase
{
    public function test_missing_math_no_reviews_creates_full_target(): void
    {
        $policy = new ProductReviewCreationPolicy();
        $article = $this->productArticle(1001, 55);
        $decision = $policy->evaluate(
            $article,
            [
                'wordpress_connected' => true,
                'fetch_success' => true,
                'wordpress_real_review_count' => 0,
                'wordpress_generated_review_count' => 0,
            ],
            [
                'local_pending_count' => 0,
                'local_generated_count' => 0,
                'local_real_count' => 0,
                'unique_fulfilled_count' => 0,
            ],
            ['target_count' => 10, 'enabled' => true, 'block_if_real_reviews_exist' => true],
        );

        self::assertTrue($decision->allowed);
        self::assertSame(10, $decision->missingCount);
        self::assertSame(10, $decision->targetCount);
    }

    public function test_missing_math_already_enough_creates_zero(): void
    {
        $policy = new ProductReviewCreationPolicy();
        $article = $this->productArticle(1002, 55);
        $decision = $policy->evaluate(
            $article,
            [
                'wordpress_connected' => true,
                'fetch_success' => true,
                'wordpress_real_review_count' => 0,
                'wordpress_generated_review_count' => 10,
            ],
            [
                'local_pending_count' => 0,
                'local_generated_count' => 10,
                'local_real_count' => 0,
                'unique_fulfilled_count' => 10,
            ],
            ['target_count' => 10, 'enabled' => true],
        );

        self::assertFalse($decision->allowed);
        self::assertSame('target_count_reached', $decision->reason);
        self::assertSame(0, $decision->missingCount);
    }

    public function test_missing_math_partial_creates_gap_only(): void
    {
        $policy = new ProductReviewCreationPolicy();
        $article = $this->productArticle(1003, 55);
        $decision = $policy->evaluate(
            $article,
            [
                'wordpress_connected' => true,
                'fetch_success' => true,
                'wordpress_real_review_count' => 0,
                'wordpress_generated_review_count' => 6,
            ],
            [
                'local_pending_count' => 0,
                'local_generated_count' => 6,
                'local_real_count' => 0,
                'unique_fulfilled_count' => 6,
            ],
            ['target_count' => 10, 'enabled' => true],
        );

        self::assertTrue($decision->allowed);
        self::assertSame(4, $decision->missingCount);
    }

    public function test_local_pending_does_not_inflate_missing_beyond_unique_generated(): void
    {
        $policy = new ProductReviewCreationPolicy();
        $article = $this->productArticle(1004, 55);
        $decision = $policy->evaluate(
            $article,
            [
                'wordpress_connected' => true,
                'fetch_success' => true,
                'wordpress_real_review_count' => 0,
                'wordpress_generated_review_count' => 6,
            ],
            [
                'local_pending_count' => 4,
                'local_generated_count' => 10,
                'local_real_count' => 0,
                'unique_fulfilled_count' => 6,
            ],
            ['target_count' => 10, 'enabled' => true],
        );

        self::assertFalse($decision->allowed);
        self::assertSame('target_count_reached', $decision->reason);
        self::assertSame(0, $decision->missingCount);
    }

    public function test_repeated_sync_with_full_target_stays_idempotent(): void
    {
        $policy = new ProductReviewCreationPolicy();
        $article = $this->productArticle(1005, 55);
        $state = [
            'wordpress_connected' => true,
            'fetch_success' => true,
            'wordpress_real_review_count' => 0,
            'wordpress_generated_review_count' => 10,
        ];
        $local = [
            'local_pending_count' => 0,
            'local_generated_count' => 10,
            'local_real_count' => 0,
            'unique_fulfilled_count' => 10,
        ];
        $settings = ['target_count' => 10, 'enabled' => true];

        $first = $policy->evaluate($article, $state, $local, $settings);
        $second = $policy->evaluate($article, $state, $local, $settings);

        self::assertSame(0, $first->missingCount);
        self::assertSame(0, $second->missingCount);
        self::assertSame($first->reason, $second->reason);
    }

    public function test_rewrite_path_bootstraps_connection_and_traces_reviews(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressManualSyncService::class))->getFileName(),
        );

        self::assertStringContainsString('runProductReviewsAfterArticleSync', $source);
        self::assertStringContainsString('bootstrapSeoDatabaseConnection', $source);
        self::assertStringContainsString('[WP_SYNC_TRACE]', $source);
        self::assertStringContainsString('review_create_start', $source);
        self::assertStringContainsString('review_create_done', $source);
        self::assertStringContainsString('review_sync_done', $source);
        self::assertStringContainsString('missing_seo_connection_context', $source);
        self::assertStringContainsString('MODE_REWRITE_UPDATE_EXISTING', $source);
    }

    public function test_manual_job_queue_is_seo_and_worker_listens(): void
    {
        self::assertSame('seo', ArticleWpSyncQueueService::QUEUE_NAME);
        $job = new ManualWordPressSyncJob(
            articleId: 1,
            userId: 1,
            source: 'test',
            requestId: 'r',
            correlationId: 'c',
            domainId: 1,
            requestedAt: now()->toIso8601String(),
            syncJobId: 1,
        );
        self::assertSame('seo', $job->queue);
    }

    public function test_reviews_tab_source_has_no_manual_create_sync_buttons(): void
    {
        $ui = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleReviewsTab.jsx',
        );
        self::assertStringContainsString('Refresh status', $ui);
        self::assertStringNotContainsString('Create reviews', $ui);
        self::assertStringNotContainsString('Sync pending reviews', $ui);
    }

    private function productArticle(int $id, int $wpPostId): SeoArticle
    {
        $article = new SeoArticle();
        $article->id = $id;
        $article->site_id = 4;
        $article->setAttribute('type', 'product');
        $article->setRelation('articleMetas', new \Illuminate\Database\Eloquent\Collection());
        $article->setRelation('wordpressLink', (object) ['wp_post_id' => $wpPostId]);
        self::assertSame('product', ArticlePostTypeResolver::resolve($article));

        return $article;
    }
}