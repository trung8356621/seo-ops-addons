<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Contract: 423 article_editor_locked → exclusive lock screen (editor runtime not mounted).
 */
final class ArticleEditorLockedReadonlyUxTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_423_sets_locked_status_and_exclusive_screen(): void
    {
        $session = $this->readAddon('resources/js/utils/editorSessionState.js');
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString("LOCKED: 'locked'", $session);
        self::assertStringContainsString("case 'article_editor_locked':", $session);
        self::assertStringContainsString('return EDITOR_SESSION_STATUS.LOCKED', $session);
        self::assertStringContainsString("result.error?.code || 'article_editor_locked'", $shell);
        self::assertStringContainsString('seo-editor-session-shell--exclusive-lock', $shell);
        self::assertStringContainsString('data-seo-editor-exclusive-lock="1"', $shell);
        self::assertStringContainsString('function ExclusiveLockScreen', $shell);
        self::assertStringNotContainsString('seo-editor-hard-lock-bar__btn--takeover', $shell);
    }

    public function test_tiptap_set_editable_false_on_session_readonly(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('editor.setEditable(!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__)', $editor);
        self::assertStringContainsString('editor.setEditable(writable)', $editor);
        self::assertStringContainsString('editable={!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__}', $editor);
    }

    public function test_save_and_save_close_disabled_when_not_writable(): void
    {
        $actions = $this->readAddon(
            'resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );

        self::assertStringContainsString('canMutateDocument()', $actions);
        self::assertStringContainsString('x-bind:disabled="!canMutateDocument()"', $actions);
        self::assertStringContainsString('data-seo-page-action="save-close"', $actions);
    }

    public function test_autosave_stopped_when_session_readonly(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('|| Boolean(sessionReadOnly)', $editor);
        self::assertStringContainsString('if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) {', $editor);
        self::assertStringContainsString('scheduleServerAutosave', $editor);
    }

    public function test_toolbar_and_command_layer_block_mutations(): void
    {
        $toolbar = $this->readAddon('resources/js/components/BlockFormatToolbar.jsx');
        $commandCtx = $this->readAddon('resources/js/utils/editorCommands/editorCommandContext.js');

        self::assertStringContainsString('mutationLocked', $toolbar);
        self::assertStringContainsString('canMutateEditor()', $toolbar);
        self::assertStringContainsString('EDITOR_COMMAND_CODES.READ_ONLY', $commandCtx);
        self::assertStringContainsString('!context.writable', $commandCtx);
    }

    public function test_cta_link_media_blocked_when_locked(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('if (!canMutateEditor())', $cta);
        self::assertStringContainsString("assertWritableEditorSession('editor_read_only')", $editor);
    }

    public function test_retry_only_no_takeover(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString('onRetry={() => { void runAcquire(); }}', $shell);
        self::assertStringNotContainsString('setInterval', $shell);
        self::assertStringNotContainsString('client.takeover', $shell);
        self::assertStringNotContainsString('Phiên chỉnh sửa hiện tại sẽ bị thu hồi', $shell);
        self::assertStringNotContainsString('canTakeover || lockInfo?.can_takeover', $shell);
    }

    public function test_archived_project_hides_retry(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString("code === 'content_project_archived'", $shell);
        self::assertStringContainsString('const showRetry = typeof onRetry === \'function\' && !archived && !conflict', $shell);
        self::assertStringContainsString('editor_archived_body', $shell);
    }

    public function test_lock_shell_independent_of_runtime_modules(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');
        $css = $this->readAddon('resources/css/article-editor.css');
        $i18n = $this->readAddon('resources/js/utils/i18n.js');

        self::assertStringContainsString('ExclusiveLockScreen', $shell);
        self::assertStringContainsString('seo-editor-session-shell', $shell);
        self::assertStringContainsString('.seo-editor-exclusive-lock-screen', $css);
        self::assertStringContainsString('editor_locked_title', $i18n);
        self::assertStringContainsString('Article is being edited', $i18n);
        self::assertStringContainsString('Bài viết đang được chỉnh sửa', $i18n);
    }
}
