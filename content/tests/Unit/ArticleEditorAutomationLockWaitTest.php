<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * @deprecated Prefer ArticleEditorSaveLockRegressionTest — kept for filter compatibility.
 */
final class ArticleEditorAutomationLockWaitTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_with_article_lock_blocks_instead_of_fail_fast_get(): void
    {
        $support = $this->readAddon('Automation/Support/ActionSupport.php');

        self::assertStringContainsString('LockTimeoutException', $support);
        self::assertStringContainsString('->block(', $support);
        self::assertStringContainsString('article-write:', $support);
        self::assertStringContainsString('articleWriteDepth', $support);
        self::assertStringNotContainsString('if (! $lock->get())', $support);
    }

    public function test_server_autosave_shares_single_flight_with_explicit_save(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('saveArticleViaApiSingleFlight', $editor);
        self::assertStringContainsString("payload.save_mode = 'autosave'", $editor);
        self::assertStringContainsString("priority: 'autosave'", $editor);
    }

    public function test_persist_error_maps_write_busy_not_automation_lock_label(): void
    {
        $action = $this->readAddon('Automation/Actions/Article/UpdateArticleContentAction.php');

        self::assertStringContainsString('article_write_busy', $action);
        self::assertStringContainsString('Bài đang được lưu bởi request khác', $action);
        self::assertStringNotContainsString('lưu/autosave', $action);
    }
}
