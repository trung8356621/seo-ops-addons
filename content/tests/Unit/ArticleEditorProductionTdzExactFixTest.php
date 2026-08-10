<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Exact production TDZ mapped from article-editor-6yMBuq9T.js:34:13481 â†’ Oo.
 * Oo = requestGenerateArticleImage used in useEffect deps before const declaration.
 */
final class ArticleEditorProductionTdzExactFixTest extends TestCase
{
    public function test_host_actions_effect_does_not_read_request_generate_in_deps(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        $effectAnchor = "window.addEventListener('seo-editor-document-html-request', onDocumentHtmlRequest);";
        $effectPos = strpos($source, $effectAnchor);
        self::assertNotFalse($effectPos, 'host actions effect missing');

        // Find the dependency array that closes this effect: }, [ ... ]);
        $depsStart = strpos($source, '}, [', $effectPos);
        self::assertNotFalse($depsStart);
        $depsEnd = strpos($source, ']);', $depsStart);
        self::assertNotFalse($depsEnd);
        $depsBlock = substr($source, $depsStart, $depsEnd - $depsStart);

        self::assertStringContainsString('scrollToExtractedLink', $depsBlock);
        self::assertStringContainsString('focusImageBlock', $depsBlock);
        self::assertStringNotContainsString('requestGenerateArticleImage', $depsBlock);

        self::assertStringContainsString('requestGenerateArticleImageRef', $source);
        self::assertStringContainsString(
            'requestGenerateArticleImageRef.current?.(detail)',
            $source,
        );
        self::assertStringContainsString(
            'requestGenerateArticleImageRef.current = requestGenerateArticleImage',
            $source,
        );
    }

    public function test_request_generate_declaration_still_exists(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertMatchesRegularExpression(
            '/const requestGenerateArticleImage = useCallback\\s*\\(/',
            $source,
        );
    }

    public function test_mapped_crash_site_markers_remain_coherent(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        // Production minified site was between these listeners and clearMediaPolling.
        self::assertStringContainsString("seo-editor-scroll-to-link", $source);
        self::assertStringContainsString("seo-editor-document-html-request", $source);
        self::assertStringContainsString('const clearMediaPolling = useCallback', $source);

        $listenersPos = strpos($source, "seo-editor-document-html-request");
        $clearPos = strpos($source, 'const clearMediaPolling = useCallback');
        $declPos = strpos($source, 'const requestGenerateArticleImage = useCallback');
        self::assertNotFalse($listenersPos);
        self::assertNotFalse($clearPos);
        self::assertNotFalse($declPos);
        self::assertLessThan($clearPos, $listenersPos);
        // Declaration remains below the host-actions effect (ref bridge required).
        self::assertGreaterThan($listenersPos, $declPos);
    }
}
