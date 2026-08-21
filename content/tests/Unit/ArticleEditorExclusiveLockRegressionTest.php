<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Exclusive entry-gate lock: different users blocked, same user can reopen.
 */
final class ArticleEditorExclusiveLockRegressionTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_locked_acquire_mounts_exclusive_screen_not_editor(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString('function ExclusiveLockScreen', $shell);
        self::assertStringContainsString('data-seo-editor-exclusive-lock="1"', $shell);
        self::assertStringContainsString('seo-editor-session-shell--exclusive-lock', $shell);
        self::assertStringContainsString('if (blocked)', $shell);
        self::assertStringContainsString('<ExclusiveLockScreen', $shell);
        self::assertStringContainsString('seo-editor-exclusive-lock-screen__notice', $shell);
        self::assertStringNotContainsString('metaBits', $shell);
        self::assertStringNotContainsString('Bắt đầu:', $shell);
        self::assertStringNotContainsString('onTakeover', $shell);
        self::assertStringNotContainsString('seo-editor-hard-lock-bar__btn--takeover', $shell);
        self::assertStringNotContainsString('client.takeover', $shell);
    }

    public function test_client_instance_id_uses_session_storage(): void
    {
        $client = $this->readAddon('resources/js/utils/editorSessionClient.js');

        self::assertStringContainsString('sessionStorage.getItem(key)', $client);
        self::assertStringContainsString('sessionStorage.setItem(key, id)', $client);
        self::assertStringNotContainsString('localStorage.getItem(key)', $client);
    }

    public function test_version_conflict_keeps_editor_writable_not_exclusive_screen(): void
    {
        $client = $this->readAddon('resources/js/utils/editorSessionClient.js');
        $shell = $this->readAddon('resources/js/article-editor.jsx');
        $editor = $this->readAddon('resources/js/hooks/useArticleEditorImageSlugRename.js');

        self::assertStringContainsString("this.lockStatus = 'owned'", $client);
        self::assertStringContainsString('this.readOnly = false', $client);
        self::assertStringContainsString('actual_document_version', $client);
        self::assertStringContainsString("=== 'article_editor_locked'", $shell);
        self::assertStringContainsString('&& Boolean(sessionReadOnly)', $shell);
        self::assertStringContainsString('syncVersionAfterSlugFix', $editor);
        self::assertStringContainsString('after_fix_slug_all_retry', $editor);
    }

    public function test_beforeunload_and_intentional_close_flag(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString("addEventListener('beforeunload'", $shell);
        self::assertStringContainsString('intentionalEditorCloseRef', $shell);
        self::assertStringContainsString('intentionalEditorCloseRef.current || window.__SEO_EDITOR_EXITING__', $shell);
        self::assertStringContainsString('__seoMarkIntentionalEditorClose', $shell);
        self::assertStringContainsString("addEventListener('pagehide'", $shell);
        self::assertStringContainsString('sendBeacon', $shell);
        self::assertStringContainsString('__seoMarkIntentionalEditorClose?.()', $shell);

        $editPage = $this->readAddon('src/Filament/Resources/ArticleResource/Pages/EditArticle.php');
        self::assertStringContainsString('finishHeavyArticleActionWithReload', $editPage);
        self::assertStringContainsString('window.__SEO_EDITOR_EXITING__=true;', $editPage);
        self::assertStringContainsString('window.__seoMarkIntentionalEditorClose?.();', $editPage);
        // Code reload after WP pull / heavy action must arm exit flags before location.reload.
        $reloadFnStart = strpos($editPage, 'function finishHeavyArticleActionWithReload');
        self::assertNotFalse($reloadFnStart);
        $reloadFnBody = substr($editPage, $reloadFnStart, 1200);
        self::assertStringContainsString('__SEO_EDITOR_EXITING__=true', $reloadFnBody);
        self::assertStringContainsString('__seoMarkIntentionalEditorClose?.()', $reloadFnBody);
        self::assertStringContainsString('location.reload()', $reloadFnBody);
    }

    public function test_lock_copy_is_exclusive_not_readonly(): void
    {
        $i18n = $this->readAddon('resources/js/utils/i18n.js');

        self::assertStringContainsString('Bài viết đang được chỉnh sửa', $i18n);
        self::assertStringContainsString('không thể mở trình biên tập', $i18n);
        self::assertStringNotContainsString('Bạn đang xem ở chế độ chỉ đọc', $i18n);
    }

    public function test_same_user_tabs_keep_independent_leases(): void
    {
        $service = $this->readAddon('Services/ArticleEditor/ArticleEditorSessionService.php');
        $channel = $this->readAddon('resources/js/utils/articleEditorTabChannel.js');

        self::assertStringContainsString('client_instance_id === $clientInstanceId', $service);
        self::assertStringContainsString('same_user_active_leases', $service);
        self::assertStringNotContainsString('session_same_user_takeover', $service);
        self::assertStringContainsString('ArticleEditorSessionException::locked', $service);
        self::assertStringContainsString('new window.BroadcastChannel(`article-editor-${normalizedArticleId}`)', $channel);
        self::assertStringContainsString("return { supported: false, destroy() {} }", $channel);
    }

    public function test_client_renews_active_visible_lease_without_polling(): void
    {
        $client = $this->readAddon('resources/js/utils/editorSessionClient.js');

        self::assertStringContainsString('bindVisibility', $client);
        self::assertStringContainsString('bindActivity', $client);
        self::assertStringContainsString('recoverSession', $client);
        self::assertStringContainsString('RECOVERABLE_SESSION_LOSS', $client);
        self::assertStringContainsString('renewLeaseOnce', $client);
        self::assertStringContainsString("document.visibilityState === 'visible'", $client);
        self::assertStringContainsString('recentlyActive', $client);
        self::assertStringNotContainsString('setInterval', $client);
    }
}
