<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleEditorContentLifecycle;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Regression: articles.body is the only SoT for editor content presence.
 */
final class ArticleEditorContentLifecycleTest extends TestCase
{
    public function test_resolve_facts_covers_acceptance_states(): void
    {
        $lifecycle = new ArticleEditorContentLifecycle();

        self::assertSame(
            ArticleEditorContentLifecycle::CONTENT_LOADING,
            $lifecycle->resolveFromFacts(['load_completed' => false]),
        );

        self::assertSame(
            ArticleEditorContentLifecycle::CONTENT_LOADING,
            $lifecycle->resolveFromFacts([
                'load_completed' => true,
                'wordpress_linked' => true,
                'local_content_present' => false,
            ]),
        );

        self::assertSame(
            ArticleEditorContentLifecycle::NEW_EMPTY_ARTICLE,
            $lifecycle->resolveFromFacts([
                'load_completed' => true,
                'wordpress_linked' => false,
                'local_content_present' => false,
            ]),
        );

        self::assertSame(
            ArticleEditorContentLifecycle::EDITABLE,
            $lifecycle->resolveFromFacts([
                'load_completed' => true,
                'wordpress_linked' => true,
                'local_content_present' => true,
            ]),
        );

        self::assertSame(
            ArticleEditorContentLifecycle::ERROR,
            $lifecycle->resolveFromFacts(['error' => true, 'load_completed' => true]),
        );
    }

    public function test_html_meaningful_content_ignores_empty_markup(): void
    {
        $lifecycle = new ArticleEditorContentLifecycle();

        self::assertFalse($lifecycle->htmlHasMeaningfulContent(''));
        self::assertFalse($lifecycle->htmlHasMeaningfulContent('   '));
        self::assertFalse($lifecycle->htmlHasMeaningfulContent('<p></p>'));
        self::assertFalse($lifecycle->htmlHasMeaningfulContent('<p>&nbsp;</p>'));
        self::assertTrue($lifecycle->htmlHasMeaningfulContent('<p>Hello WP</p>'));
        self::assertTrue($lifecycle->htmlHasMeaningfulContent('<p><img src="https://x.test/a.jpg" alt=""></p>'));
    }

    public function test_local_content_present_uses_body_only_not_bootstrap_or_document(): void
    {
        $lifecycle = new ArticleEditorContentLifecycle();
        $article = new SeoArticle();
        $article->body = null;
        $article->editor_document = [
            'type' => 'article_editor_document',
            'blocks' => [
                ['type' => 'text', 'content' => '<p>Stale document text</p>'],
            ],
        ];

        self::assertFalse($lifecycle->hasLocalContentSnapshot($article));
        self::assertFalse($lifecycle->hasLocalContentSnapshot($article, '<p>Bootstrap HTML must not count</p>'));

        $article->body = '<p>Canonical body</p>';
        self::assertTrue($lifecycle->hasLocalContentSnapshot($article));
        self::assertTrue($lifecycle->hasLocalContentSnapshot($article, ''));
    }

    public function test_empty_body_wp_linked_resolves_to_content_loading(): void
    {
        $lifecycle = new ArticleEditorContentLifecycle();

        self::assertSame(
            ArticleEditorContentLifecycle::CONTENT_LOADING,
            $lifecycle->resolveFromFacts([
                'load_completed' => true,
                'wordpress_linked' => true,
                'local_content_present' => false,
            ]),
        );
        self::assertNotSame(
            ArticleEditorContentLifecycle::SYNC_REQUIRED,
            $lifecycle->resolveFromFacts([
                'load_completed' => true,
                'wordpress_linked' => true,
                'local_content_present' => false,
            ]),
        );
    }

    public function test_core_bootstrap_exposes_content_lifecycle(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('ArticleEditorContentLifecycle', $source);
        self::assertStringContainsString("'contentLifecycle'", $source);
        self::assertStringContainsString('bootstrapPayload(', $source);
        self::assertStringContainsString('hydrateEditorBodyFromWordPress', $source);
    }

    public function test_persist_service_rejects_unhydrated_empty(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorPersistService.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('rejectUnhydratedEmptyPersist', $source);
        self::assertStringContainsString('ArticleEditorContentLifecycle', $source);
        self::assertStringContainsString('ArticleEditorSessionErrorCode::LOCAL_CONTENT_SYNC_REQUIRED', $source);
        self::assertStringContainsString('shouldRejectEmptyPersist', $source);
        self::assertStringNotContainsString('keepBodyNull', $source);
    }

    public function test_session_error_code_constant_exists(): void
    {
        self::assertSame('local_content_sync_required', ArticleEditorSessionErrorCode::LOCAL_CONTENT_SYNC_REQUIRED);
        self::assertSame(
            ArticleEditorContentLifecycle::REJECT_EMPTY_UNHYDRATED_CODE,
            ArticleEditorSessionErrorCode::LOCAL_CONTENT_SYNC_REQUIRED,
        );
    }

    public function test_frontend_lifecycle_auto_hydrate_and_error_retry(): void
    {
        $lifecycleJs = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorContentLifecycle.js',
        );
        self::assertStringContainsString('CONTENT_LOADING', $lifecycleJs);
        self::assertStringContainsString('SYNC_REQUIRED', $lifecycleJs);
        self::assertStringContainsString('NEW_EMPTY_ARTICLE', $lifecycleJs);
        self::assertStringContainsString('resolveContentLifecycleFromFacts', $lifecycleJs);

        $blocker = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleContentSyncRequiredBlocker.jsx',
        );
        self::assertStringContainsString('content_wp_loading', $blocker);
        self::assertStringContainsString('content_wp_load_failed', $blocker);
        self::assertStringContainsString('content_wp_load_retry', $blocker);
        self::assertStringNotContainsString('syncArticleFromWordPress', $blocker);
        self::assertStringNotContainsString('content_sync_required_action', $blocker);

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('ArticleContentSyncRequiredBlocker', $editor);
        self::assertStringContainsString('useWpEditorContentAutoLoad', $editor);

        $autoLoad = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useWpEditorContentAutoLoad.js',
        );
        self::assertStringContainsString("wire.call('loadWpEditorHtmlFromWordPress')", $autoLoad);
        self::assertStringContainsString('CONTENT_LIFECYCLE.ERROR', $autoLoad);
        self::assertStringContainsString('retry', $autoLoad);
        self::assertStringNotContainsString('syncArticleFromWordPress', $autoLoad);

        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringContainsString('local_content_sync_required', $api);

        $actions = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );
        self::assertStringContainsString('contentLifecycle', $actions);
        self::assertStringContainsString('NEW_EMPTY_ARTICLE', $actions);

        $danger = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/partials/article-editor-danger-actions.blade.php',
        );
        self::assertStringContainsString("wire.call('syncArticleFromWordPress')", $danger);
    }

    public function test_document_bootstrap_skips_stale_json_when_body_empty(): void
    {
        $path = ProjectRoot::addonsPath()
            .'/content/src/Services/ArticleEditor/Document/ArticleEditorDocumentWriter.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('Empty body: never prefer stale editor_document', $src);
    }

    public function test_outline_and_seo_hide_fake_empty_when_loading(): void
    {
        $outline = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleOutlineTab.jsx',
        );
        self::assertStringContainsString('syncRequired', $outline);
        self::assertStringContainsString('content_wp_loading', $outline);
        self::assertStringNotContainsString('content_sync_required_outline', $outline);

        $seo = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/components/SeoScorePanel.jsx',
        );
        self::assertStringContainsString('syncRequired', $seo);
        self::assertStringContainsString('content_wp_loading', $seo);
        self::assertStringNotContainsString('content_sync_required_seo', $seo);
    }

    public function test_edit_article_keeps_manual_sync_separate_from_auto_hydrate(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('rejectUnhydratedEmptyPersist', $source);
        self::assertStringContainsString('syncArticleFromWordPress', $source);
        self::assertStringContainsString('hydrateEditorBodyFromWordPress', $source);
        self::assertStringContainsString('syncSingleArticleFromWordPress', $source);

        $hydrateMethod = $this->extractMethod($source, 'loadWpEditorHtmlFromWordPress');
        self::assertStringContainsString('hydrateEditorBodyFromWordPress', $hydrateMethod);
        self::assertStringNotContainsString('syncSingleArticleFromWordPress', $hydrateMethod);

        $manualMethod = $this->extractMethod($source, 'syncArticleFromWordPress');
        self::assertStringContainsString('syncSingleArticleFromWordPress', $manualMethod);
        self::assertStringNotContainsString('hydrateEditorBodyFromWordPress', $manualMethod);
    }

    public function test_shell_open_does_not_paint_from_wp_cache(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);
        $restore = $this->extractMethod($source, 'restoreArticleBodyFromWordPressCacheIfMissing');

        self::assertStringNotContainsString('resolveEditorHtmlLocalOnly', $restore);
        self::assertStringContainsString("wpEditorBootstrapHtml = ''", $restore);

        $wpService = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleContentService.php',
        );
        $localOnly = $this->extractMethod($wpService, 'resolveEditorHtmlLocalOnly');
        self::assertStringContainsString('pending_wp_fetch', $localOnly);
        self::assertStringNotContainsString('resolveEditorHtmlFromWpCache', $localOnly);
    }

    private function extractMethod(string $source, string $method): string
    {
        $needle = 'function '.$method.'(';
        $start = strpos($source, $needle);
        self::assertNotFalse($start, 'missing method '.$method);

        $brace = strpos($source, '{', $start);
        self::assertNotFalse($brace);
        $depth = 0;
        $len = strlen($source);
        for ($i = (int) $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail('unclosed method '.$method);

        return '';
    }
}
