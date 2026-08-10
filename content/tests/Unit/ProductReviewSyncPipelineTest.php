<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewCreationPolicy;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use Omnichannel\Addons\WordPress\Services\SyncArticleToWordPressPipeline;
use PHPUnit\Framework\TestCase;

final class ProductReviewSyncPipelineTest extends TestCase
{
    public function test_status_lifecycle_values(): void
    {
        self::assertSame('pending', ArticleProductReviewStatus::Pending->value);
        self::assertSame('syncing', ArticleProductReviewStatus::Syncing->value);
        self::assertSame('reviewed', ArticleProductReviewStatus::Reviewed->value);
        self::assertSame('failed', ArticleProductReviewStatus::Failed->value);
    }

    public function test_split_ownership_classes_exist(): void
    {
        self::assertTrue(class_exists(SyncArticleToWordPressPipeline::class));
        self::assertTrue(class_exists(ArticleWordPressBusinessSequence::class));
        self::assertTrue(class_exists(ProductReviewCreationPolicy::class));
        self::assertTrue(class_exists(
            \Omnichannel\Addons\Commerce\Services\ProductReview\WordPressProductReviewStatusService::class,
        ));
        self::assertTrue(class_exists(
            \Omnichannel\Addons\Commerce\Http\Controllers\ArticleProductReviewStatusController::class,
        ));
    }

    public function test_pipeline_article_only(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/SyncArticleToWordPressPipeline.php',
        );
        self::assertStringContainsString('WordPressArticleSyncService', $source);
        self::assertStringNotContainsString('WordPressProductReviewService', $source);
    }

    public function test_business_sequence_has_create_and_sync(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/ArticleWordPressBusinessSequence.php',
        );
        self::assertStringContainsString('runCreate', $source);
        self::assertStringContainsString('runSync', $source);
        self::assertStringContainsString('ProductReviewCreationPolicy', $source);
    }
}
