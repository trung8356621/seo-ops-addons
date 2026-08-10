<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

final class WordPressSlugFixWriteGuardContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_shared_guard_has_stable_code_and_vietnamese_message(): void
    {
        $exception = $this->readAddon('Services/WordPress/WordPressSlugFixRequiredException.php');
        $guard = $this->readAddon('Services/WordPress/WordPressWriteReadinessGuard.php');

        self::assertStringContainsString('wordpress_slug_fix_required', $exception);
        self::assertStringContainsString('Chưa thể đồng bộ sang WordPress', $exception);
        self::assertStringContainsString('assertCanWriteToWordPress', $guard);
        self::assertStringContainsString('pendingArticleLocalSlugFixes', $guard);
        self::assertStringContainsString('pendingSiteLocalSlugFixes', $guard);
        self::assertStringContainsString('image|img|photo|untitled|download|dsc|img_', $guard);
        self::assertStringContainsString('paste|clipboard|import', $guard);
        self::assertStringContainsString('article.find_post_by_meta', $guard);
    }

    public function test_paste_random_slugs_are_treated_as_not_fixed(): void
    {
        $guard = $this->readAddon('Services/WordPress/WordPressWriteReadinessGuard.php');
        $inventory = $this->readAddon('resources/js/utils/unifiedArticleImagesInventory.js');
        $health = $this->readAddon('resources/js/utils/assistantWidgetHealth.js');

        self::assertStringContainsString('paste|clipboard|import', $guard);
        self::assertStringContainsString('paste|clipboard|import', $inventory);
        self::assertStringContainsString('paste|clipboard|import', $health);
    }

    public function test_article_gateway_and_publisher_cannot_bypass_slug_guard(): void
    {
        $gateway = $this->readAddon('Services/WordPress/SideEffect/WordPressGateway.php');
        $publisher = $this->readAddon('Extension/Builtin/Wordpress/WordPressPublisher.php');
        $sync = $this->readAddon('Services/WordPressArticleSyncService.php');

        self::assertStringContainsString('WordPressWriteReadinessGuard', $gateway);
        self::assertStringContainsString('assertCanWriteToWordPress($articleForGuard, $operation)', $gateway);
        self::assertStringContainsString('WordPressWriteReadinessGuard', $publisher);
        self::assertStringContainsString('wordpress_publisher.publish', $publisher);
        self::assertStringContainsString('slugFixRequiredResponse', $sync);
        self::assertStringContainsString('WordPressSlugFixRequiredException::ERROR_CODE', $sync);
    }

    public function test_media_write_paths_are_guarded_before_http_post(): void
    {
        $localMedia = $this->readAddon('Services/WordPressLocalMediaSyncService.php');
        $rename = $this->readAddon('Services/WordPressAttachmentRenameService.php');
        $meta = $this->readAddon('Services/WordPressAttachmentMetaUpdateService.php');
        $library = $this->readAddon('Services/WordPressMediaLibraryService.php');
        $articleMedia = $this->readAddon('Services/WordPressArticleMediaService.php');
        $watermark = $this->readAddon('Services/WordPressMediaWatermarkService.php');
        $virtualComments = $this->readAddon('Services/VirtualCommentService.php');

        self::assertStringContainsString('assertCanWriteToWordPress($article, \'media.sync\')', $localMedia);
        self::assertStringContainsString('WordPressSlugFixRequiredException::MESSAGE', $localMedia);
        self::assertStringContainsString('blockWhenSlugFixRequired($site, \'attachment.rename', $rename);
        self::assertStringContainsString('blockWhenSlugFixRequired($site, \'attachment.update_meta\')', $meta);
        self::assertStringContainsString('blockWhenSlugFixRequired($site, \'attachment.delete\')', $library);
        self::assertStringContainsString('blockWhenSlugFixRequired($article, \'article.media_update\')', $articleMedia);
        self::assertStringContainsString('media.watermark_replace', $watermark);
        self::assertStringContainsString('blockWhenSlugFixRequired($articleId, $site, \'virtual_comments.sync\')', $virtualComments);
    }

    public function test_queue_prerequisite_block_happens_before_claim_and_without_retry_burn(): void
    {
        $handler = $this->readAddon('Services/ContentProject/Application/Handlers/ProcessScheduledProjectItemPublishHandler.php');
        $guardPos = strpos($handler, 'publishing_queue.process');
        $claimPos = strpos($handler, 'claimForDispatch');

        self::assertIsInt($guardPos);
        self::assertIsInt($claimPos);
        self::assertLessThan($claimPos, $guardPos);
        self::assertStringContainsString('retry_count_unchanged', $handler);
        self::assertStringContainsString('publisher_invoked', $handler);
        self::assertStringContainsString('media_upload_started', $handler);
    }
}
