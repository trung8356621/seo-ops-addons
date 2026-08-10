<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

final class ArticleEditorRewriteWordPressSyncEligibilityTest extends TestCase
{
    private function addon(string $relative): string
    {
        return ProjectRoot::addonsPath().'/seo-content-ai-compat'.'/'.$relative;
    }

    public function test_rewrite_sync_uses_shared_eligibility_and_update_existing_only(): void
    {
        $policy = (string) file_get_contents($this->addon('Services/ArticleWordPressSyncEligibility.php'));
        self::assertStringContainsString("TYPE_REWRITE", $policy);
        self::assertStringContainsString("TYPE_IMPROVE", $policy);
        self::assertStringContainsString("'missing_remote_post'", $policy);
        self::assertStringContainsString('MODE_REWRITE_UPDATE_EXISTING', $policy);
        self::assertStringContainsString('articles.wp_post_id', $policy);

        $manual = (string) file_get_contents($this->addon('Services/WordPress/WordPressManualSyncService.php'));
        self::assertStringContainsString('ArticleWordPressSyncEligibility $syncEligibility', $manual);
        self::assertStringContainsString('syncRewriteExistingFromEditorBundle', $manual);
        self::assertStringContainsString('MODE_REWRITE_UPDATE_EXISTING', $manual);
        self::assertStringContainsString('updatePublishedArticleOnly', $manual);
        self::assertStringContainsString("'create_post_called' => false", $manual);
        self::assertStringNotContainsString("['mode' => 'publish'", $this->rewriteMethodSource($manual));

        $controller = (string) file_get_contents($this->addon('Http/Controllers/ArticleEditorSyncController.php'));
        self::assertStringContainsString("'rewrite_existing_synced'", $controller);

        $blade = (string) file_get_contents($this->addon('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'));
        self::assertStringContainsString('ArticleWordPressSyncEligibility::class', $blade);
        self::assertStringContainsString('$contentProjectWpSyncEligible', $blade);
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
