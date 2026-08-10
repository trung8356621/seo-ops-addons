<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Static contract: editor session heartbeat must not 500 silently on expire deadlock,
 * and FE must surface reload CTA when session becomes unavailable.
 */
final class ArticleEditorSessionHeartbeatUxContractTest extends TestCase
{
    public function test_expire_stale_sessions_swallows_deadlock(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/ArticleEditorSessionService.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('function expireStaleSessionsForArticleId', $source);
        self::assertStringContainsString('isTransientSessionLockFailure', $source);
        self::assertStringContainsString('sessions_expire_skipped_lock', $source);
        self::assertStringContainsString('1213', $source);
        self::assertStringContainsString('Deadlock found', $source);
    }

    public function test_frontend_maps_server_errors_to_unavailable_and_reload_cta(): void
    {
        $client = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorSessionClient.js',
        );
        $shell = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js',
        );

        self::assertStringContainsString('SESSION_UNAVAILABLE', $client);
        self::assertStringContainsString('httpStatus >= 500', $client);
        self::assertStringContainsString('seo-article-editor-notify', $client);

        self::assertStringContainsString('article_editor_session_unavailable', $shell);
        self::assertStringContainsString('onReload', $shell);
        self::assertStringContainsString('editor_session_reload', $shell);
        self::assertStringContainsString('sessionReadOnly={Boolean(sessionReadOnly)}', $shell);

        self::assertStringContainsString('editor_session_unavailable_title', $i18n);
        self::assertStringContainsString('editor_session_reload', $i18n);
    }
}
