<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ArticleEditorRewriteWordPressSyncEligibilityTest extends TestCase
{
    public function test_rewrite_sync_uses_shared_eligibility_and_update_existing_only(): void
    {
        $policy = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/ArticleWordPressSyncEligibility.php',
        );
        self::assertStringContainsString('TYPE_REWRITE', $policy);
        self::assertStringContainsString('TYPE_IMPROVE', $policy);
        self::assertStringContainsString("'missing_remote_post'", $policy);
        self::assertStringContainsString('MODE_REWRITE_UPDATE_EXISTING', $policy);
        self::assertStringContainsString('articles.wp_post_id', $policy);

        $manual = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressManualSyncService.php',
        );
        self::assertStringContainsString('ArticleWordPressSyncEligibility $syncEligibility', $manual);
        self::assertStringContainsString('syncRewriteExistingFromEditorBundle', $manual);
        self::assertStringContainsString('MODE_REWRITE_UPDATE_EXISTING', $manual);
        self::assertStringContainsString('updatePublishedArticleOnly', $manual);
        self::assertStringContainsString("'create_post_called' => false", $manual);
        self::assertStringNotContainsString("['mode' => 'publish'", $this->rewriteMethodSource($manual));

        $controller = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Http/Controllers/ArticleEditorSyncController.php',
        );
        self::assertStringContainsString("'rewrite_existing_synced'", $controller);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );
        self::assertStringContainsString('articleIsInContentProject', $blade);
        self::assertStringContainsString('$contentProjectRewriteSyncEligible', $blade);
        self::assertStringContainsString('MODE_REWRITE_UPDATE_EXISTING', $blade);
        self::assertStringContainsString('data-seo-sync-origin="content_project_rewrite"', $blade);
        self::assertStringNotContainsString('PostPublishWordPressSyncEligibility::class', $blade);
    }

    private function rewriteMethodSource(string $manual): string
    {
        $start = strpos($manual, 'private function syncRewriteExistingFromEditorBundle');
        $end = strpos($manual, 'private function mapPostPublishCommandResult');

        self::assertIsInt($start);
        self::assertIsInt($end);

        return substr($manual, $start, $end - $start);
    }
}
