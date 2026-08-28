<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Http\Controllers\ArticleProductReviewStatusController;
use Omnichannel\Addons\Commerce\Services\ProductReview\LegacyProductReviewStateNormalizer;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewCreationPolicy;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewPendingRepository;
use Omnichannel\Addons\Commerce\Services\ProductReview\WordPressCommentReviewPayloadFactory;
use Omnichannel\Addons\Commerce\Services\ProductReview\WordPressProductReviewService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Reviews sync must be independent of Content Project publishing + lock-isolation contract.
 */
final class ProductReviewSyncIndependenceContractTest extends TestCase
{
    public function test_a_widget_lock_cli_unlocks_reviews_only(): void
    {
        $lib = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/scripts/editor-widget-lock-lib.cjs',
        );
        $cli = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/scripts/widget-lock.cjs',
        );

        self::assertStringContainsString('unlock', $cli);
        self::assertStringContainsString('No unlock-all', $cli);
        self::assertStringContainsString('widget.locked = false', $cli);
        self::assertStringContainsString('there is no unlock-all', $cli);
        self::assertStringContainsString('locked', $lib);
    }

    public function test_c_manual_sync_controller_does_not_invoke_publishing_queue(): void
    {
        $controller = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleProductReviewStatusController::class))->getFileName(),
        );
        $sequence = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleWordPressBusinessSequence::class))->getFileName(),
        );

        self::assertStringContainsString('runSync', $controller);
        self::assertStringContainsString('ManualSyncContext::make', $controller);
        self::assertStringContainsString('article_editor.product_review_sync', $controller);
        self::assertStringNotContainsString('ContentProjectPublishingQueue', $controller);
        self::assertStringNotContainsString('PublishNow', $controller);
        self::assertStringNotContainsString('scheduled_publish_at', $controller);

        $runSync = $this->methodSource(ArticleWordPressBusinessSequence::class, 'runSync');
        self::assertStringNotContainsString('articlePipeline', $runSync);
        self::assertStringNotContainsString('SyncArticleToWordPressPipeline', $runSync);
        self::assertStringContainsString('pendingForArticle', $runSync);
        self::assertStringContainsString('reviewService->create', $runSync);
    }

    public function test_d_e_mark_reviewed_only_after_wp_success_and_failures_stay_local(): void
    {
        $create = $this->methodSource(WordPressProductReviewService::class, 'create');

        self::assertStringContainsString('markSyncing', $create);
        self::assertStringContainsString('markFailed', $create);
        self::assertStringContainsString('markReviewed', $create);
        self::assertMatchesRegularExpression('/if\s*\(\s*!\s*\(\s*\$postResult\[[\'"]success[\'"]\]/', $create);
        self::assertStringContainsString('markFailed($review', $create);
        // Fetch failure must not POST empty merge (would wipe siblings).
        self::assertStringContainsString('WORDPRESS_REVIEW_FETCH_FAILED', $create);
        self::assertStringContainsString('catch (\\Throwable $exception)', $create);
    }

    public function test_f_g_idempotency_fields_and_skip_reviewed(): void
    {
        $factory = (string) file_get_contents(
            (string) (new ReflectionClass(WordPressCommentReviewPayloadFactory::class))->getFileName(),
        );
        $repo = (string) file_get_contents(
            (string) (new ReflectionClass(ProductReviewPendingRepository::class))->getFileName(),
        );
        $service = (string) file_get_contents(
            (string) (new ReflectionClass(WordPressProductReviewService::class))->getFileName(),
        );

        self::assertStringContainsString('_omi_idempotency_key', $factory);
        self::assertStringContainsString('_omi_review_id', $factory);
        self::assertStringContainsString("'virtual' => true", $factory);
        self::assertStringContainsString('shouldSkipCreate', $repo);
        self::assertStringContainsString('isReviewed()', $repo);
        self::assertStringContainsString('DEDUPLICATED', $service);
        self::assertStringContainsString('exists($review', $service);
    }

    public function test_h_counters_include_legacy_pending_and_local_generated(): void
    {
        $policy = (string) file_get_contents(
            (string) (new ReflectionClass(ProductReviewCreationPolicy::class))->getFileName(),
        );
        $ui = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleReviewsTab.jsx',
        );

        self::assertStringContainsString('PendingPublish', $policy);
        self::assertStringContainsString('local_generated_count', $policy);
        self::assertStringContainsString('syncable_pending_count', $ui);
        self::assertStringContainsString('local_generated_count', $ui);
        self::assertStringContainsString('generated_count', $ui);
    }

    public function test_delete_reviewed_never_wipes_pending(): void
    {
        $repo = (string) file_get_contents(
            (string) (new ReflectionClass(ProductReviewPendingRepository::class))->getFileName(),
        );

        self::assertStringContainsString('whereNotNull(\'wp_comment_id\')', $repo);
        self::assertStringContainsString('Reviewed->value', $repo);
        self::assertStringContainsString(
            'return $this->deleteReviewedForArticle($article);',
            $repo,
        );
    }

    public function test_legacy_normalizer_preserves_canonical_pending(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(LegacyProductReviewStateNormalizer::class))->getFileName(),
        );

        self::assertStringContainsString('must never be', $src);
        self::assertStringContainsString('PendingPublish', $src);
        self::assertStringContainsString('ArticleProductReviewStatus::Pending', $src);
        self::assertTrue(ArticleProductReviewStatus::Pending->isPendingSync());
        self::assertTrue(ArticleProductReviewStatus::Failed->isPendingSync());
    }

    public function test_product_review_sync_exempt_from_media_slug_fix(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(WordPressWriteReadinessGuard::class))->getFileName(),
        );

        self::assertStringContainsString('isExemptFromMediaSlugFix', $src);
        self::assertStringContainsString('wordpress.product_review.sync', $src);
    }

    public function test_b_create_persists_pending_status(): void
    {
        $creator = (string) file_get_contents(
            ProjectRoot::addonsPath().'/commerce/src/Services/ProductReview/ProductReviewLocalBatchCreator.php',
        );

        self::assertStringContainsString("ArticleProductReviewStatus::Pending", $creator);
        self::assertStringContainsString("'generation_batch_id'", $creator);
        self::assertStringContainsString('omi_seo_ai', $creator);
        self::assertStringContainsString('idempotency_key', $creator);
    }

    public function test_manual_sync_job_bootstraps_seo_connection_before_reviews(): void
    {
        $job = (string) file_get_contents(
            (new ReflectionClass(ManualWordPressSyncJob::class))->getFileName(),
        );

        self::assertStringContainsString('SeoDatabaseConnectionService', $job);
        self::assertStringContainsString('bootstrapLegacySharedConnection', $job);
        self::assertStringContainsString('bootstrapSeoDatabaseConnection', $job);
        self::assertStringContainsString('ArticleWordPressBusinessSequence', $job);
    }

    public function test_rewrite_existing_manual_sync_runs_product_reviews(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\WordPress\Services\WordPressManualSyncService::class))->getFileName(),
        );

        self::assertStringContainsString('runProductReviewsAfterArticleSync', $source);
        self::assertStringContainsString('businessSequence->runCreate', $source);
        self::assertStringContainsString('businessSequence->runSync', $source);
    }

    /**
     * @param  class-string  $class
     */
    private function methodSource(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $file = (string) $ref->getFileName();
        $start = (int) $ref->getStartLine();
        $end = (int) $ref->getEndLine();
        $lines = file($file);
        self::assertNotFalse($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
