<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Contract: nested article-write mutex + client write queue (save lock regression).
 */
final class ArticleEditorSaveLockRegressionTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_article_write_lock_is_reentrant_and_not_automation_namespace(): void
    {
        $support = $this->readAddon('Automation/Support/ActionSupport.php');

        self::assertStringContainsString('article-write:', $support);
        self::assertStringContainsString('articleWriteDepth', $support);
        self::assertStringContainsString('article_write_busy', $support);
        self::assertStringContainsString('->block(', $support);
        self::assertStringNotContainsString('automation-article-', $support);
        self::assertStringNotContainsString('if (! $lock->get())', $support);
        self::assertStringNotContainsString('lưu/autosave', $support);
    }

    public function test_session_save_and_content_action_both_use_with_article_lock(): void
    {
        $session = $this->readAddon('Services/ArticleEditor/ArticleEditorSessionService.php');
        $action = $this->readAddon('Automation/Actions/Article/UpdateArticleContentAction.php');

        self::assertStringContainsString('ActionSupport::withArticleLock', $session);
        self::assertStringContainsString('ActionSupport::withArticleLock', $action);
        self::assertStringContainsString('Reentrant', $session);
        self::assertStringContainsString('article_write_busy', $action);
        self::assertStringContainsString('Bài đang được lưu bởi request khác', $action);
        self::assertStringNotContainsString('lưu/autosave', $action);
    }

    public function test_client_write_queue_prioritizes_explicit_over_autosave(): void
    {
        $queue = $this->readAddon('resources/js/utils/articleEditorSaveQueue.js');
        $saveHook = $this->readAddon('resources/js/hooks/useArticleEditorSaveQueue.js');
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString("autosave: 1", $queue);
        self::assertStringContainsString("explicit: 2", $queue);
        self::assertStringContainsString("'save-close': 3", $queue);
        self::assertStringContainsString('beginExplicitEditorSave', $queue);
        self::assertStringContainsString('shouldSuppressServerAutosave', $queue);
        self::assertStringContainsString('article_write_busy', $queue);
        self::assertStringContainsString("priority: 'autosave'", $saveHook);
        self::assertStringContainsString("priority: 'explicit'", $shell);
        self::assertStringContainsString('shouldSuppressServerAutosave', $saveHook);
    }

    public function test_persist_failure_surfaces_write_busy_code(): void
    {
        $controller = $this->readAddon('Http/Controllers/ArticleEditorSessionController.php');
        $session = $this->readAddon('Services/ArticleEditor/ArticleEditorSessionService.php');

        self::assertStringContainsString("'code' => \$code !== '' ? \$code : 'persist_rejected'", $controller);
        self::assertStringContainsString("\$persistCode === 'article_write_busy'", $session);
    }
}
