<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Media\Http\Controllers\SeoMediaController;
use Omnichannel\Addons\Media\Services\SeoMediaArticleSlugFixService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Owning editor session must be able to Fix Slug without false foreign-lock conflict.
 */
final class ArticleEditorFixSlugSessionOwnershipTest extends TestCase
{
    public function test_rename_one_forwards_session_context_to_rewrite(): void
    {
        $source = $this->methodSource(new ReflectionMethod(SeoMediaArticleSlugFixService::class, 'renameOne'));
        self::assertStringContainsString('array $context = []', $source);
        self::assertStringContainsString(
            'rewriteArticleReferences($article, $urlMap, $context)',
            $source,
        );
        self::assertStringNotContainsString(
            'rewriteArticleReferences($article, $urlMap);',
            $source,
        );
    }

    public function test_rename_endpoints_pass_editor_session_context(): void
    {
        $controller = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Http/Controllers/SeoMediaController.php',
        );
        self::assertStringContainsString('editorSessionContext', $controller);
        self::assertStringContainsString("'editor_session_id'", $controller);
        self::assertStringContainsString('ArticleEditorSessionException', $controller);
        self::assertStringContainsString('editorSessionLockedResponse', $controller);
        self::assertStringContainsString('X-Editor-Session-Id', $controller);
    }

    public function test_client_rename_sends_editor_session_id(): void
    {
        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/seoMediaApi.js',
        );
        self::assertStringContainsString('function withEditorSessionRequest', $api);
        self::assertStringContainsString('X-Editor-Session-Id', $api);
        self::assertStringContainsString('editor_session_id', $api);
        self::assertStringContainsString('withEditorSessionRequest(payload)', $api);
        self::assertStringContainsString('__seoEditorSessionClient', $api);
        self::assertStringContainsString('__SEO_EDITOR_SESSION_ID__', $api);
    }

    public function test_body_rewrite_allows_owning_session(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(
                \Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService::class,
                'assertBodyRewriteAllowed',
            ),
        );
        self::assertStringContainsString('$editorSessionId', $source);
        self::assertStringContainsString('(string) $active->id === $sessionId', $source);
        self::assertStringContainsString('ArticleEditorSessionException::locked', $source);
    }

    private function methodSource(\ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
