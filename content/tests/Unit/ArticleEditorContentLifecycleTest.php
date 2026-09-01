<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleEditorContentLifecycle;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

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
    }

    public function test_core_bootstrap_exposes_content_lifecycle(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('ArticleEditorContentLifecycle', $source);
        self::assertStringContainsString("'contentLifecycle'", $source);
        self::assertStringContainsString('bootstrapPayload(', $source);
    }

    public function test_persist_service_rejects_unhydrated_empty(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorPersistService.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('rejectUnhydratedEmptyPersist', $source);
        self::assertStringContainsString('ArticleEditorContentLifecycle', $source);
        self::assertStringContainsString('ArticleEditorSessionErrorCode::LOCAL_CONTENT_SYNC_REQUIRED', $source);
        self::assertStringContainsString('shouldRejectEmptyPersist', $source);
    }

    public function test_session_error_code_constant_exists(): void
    {
        self::assertSame('local_content_sync_required', ArticleEditorSessionErrorCode::LOCAL_CONTENT_SYNC_REQUIRED);
        self::assertSame(
            ArticleEditorContentLifecycle::REJECT_EMPTY_UNHYDRATED_CODE,
            ArticleEditorSessionErrorCode::LOCAL_CONTENT_SYNC_REQUIRED,
        );
    }

    public function test_frontend_lifecycle_and_blocker_contracts(): void
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

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('ArticleContentSyncRequiredBlocker', $editor);
        self::assertStringContainsString('useWpEditorContentAutoLoad', $editor);
        self::assertStringContainsString('loadWpEditorHtmlFromWordPress', (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useWpEditorContentAutoLoad.js',
        ));

        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringContainsString('local_content_sync_required', $api);
        self::assertStringContainsString('SYNC_REQUIRED', $api);

        $session = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorSessionState.js',
        );
        self::assertStringContainsString('SYNC_REQUIRED', $session);

        $actions = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );
        self::assertStringContainsString('contentLifecycle', $actions);
        self::assertStringContainsString('NEW_EMPTY_ARTICLE', $actions);
    }

    public function test_outline_and_seo_hide_fake_empty_when_sync_required(): void
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

    public function test_edit_article_livewire_save_guards_unhydrated_empty(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('rejectUnhydratedEmptyPersist', $source);
        self::assertStringContainsString('syncArticleFromWordPress', $source);
    }
}
