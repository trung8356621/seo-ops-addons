<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewCreationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Product reviews = 3 linear actions; wordpress.article.sync has no review orchestration.
 */
final class ProductReviewArticleSyncIsolationTest extends TestCase
{
    public function test_article_sync_pipeline_has_no_review_service(): void
    {
        $pipeline = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/SyncArticleToWordPressPipeline.php',
        );
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php',
        );

        foreach ([$pipeline, $hook] as $source) {
            self::assertStringNotContainsString('WordPressProductReviewService', $source);
            self::assertStringNotContainsString('ProductReviewPendingRepository', $source);
            self::assertStringNotContainsString('syncPendingReviews', $source);
            self::assertStringNotContainsString('ProductReviewPostSyncReconciler', $source);
        }
    }

    public function test_three_action_codes_exist(): void
    {
        self::assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);
        self::assertSame('product-review.create', AutomationActionCode::ProductReviewCreate->value);
        self::assertSame('product-review.sync-wp', AutomationActionCode::ProductReviewSyncWp->value);
        self::assertTrue(class_exists(\Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\CreateProductReviewsHookAction::class));
        self::assertTrue(class_exists(\Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncProductReviewsToWordPressHookAction::class));
        self::assertTrue(class_exists(ProductReviewCreationPolicy::class));
    }

    public function test_manual_job_uses_business_sequence_not_review_inside_article_sync(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Jobs/ManualWordPressSyncJob.php',
        );
        self::assertStringContainsString('ArticleWordPressBusinessSequence', $source);
        self::assertStringNotContainsString('ProductReviewPostSyncReconciler', $source);
    }

    public function test_legacy_review_actions_are_safe_noops(): void
    {
        foreach ([
            'ScheduleGeneratedProductReviewsHookAction.php',
            'QueuePendingProductReviewsHookAction.php',
            'PublishWordPressCommentReviewHookAction.php',
        ] as $file) {
            $source = (string) file_get_contents(
                ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Actions/'.$file,
            );
            self::assertStringContainsString('owned_by_wordpress_article_sync_pipeline', $source);
            self::assertStringContainsString('skipped', $source);
        }
    }

    public function test_status_lifecycle(): void
    {
        self::assertTrue(ArticleProductReviewStatus::Pending->isPendingSync());
        self::assertTrue(ArticleProductReviewStatus::Reviewed->isReviewed());
    }

    public function test_seeder_defines_three_actions(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );
        self::assertStringContainsString('ProductReviewCreate', $source);
        self::assertStringContainsString('ProductReviewSyncWp', $source);
        self::assertStringContainsString('desiredActions', $source);
    }
}
