<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 contracts updated through Phase 6C.4 â€” ModuleHost removed; runtime portals own panels.
 */
final class ArticleEditorPhase3ModuleHostTest extends TestCase
{
    public function test_article_editor_entry_does_not_static_import_heavy_modules(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );

        self::assertStringNotContainsString("from './components/ArticleLinksSidebar'", $source);
        self::assertStringNotContainsString("from './components/ArticleFaqEditor'", $source);
        self::assertStringNotContainsString("from './components/ArticleAiChatPanel'", $source);
        self::assertStringNotContainsString('ArticleEditorModuleHost', $source);
        self::assertStringContainsString('__seoArticleEditorNavigatedBound', $source);
        self::assertStringContainsString('__seoArticleLivewireBridgeRegistered', $source);
    }

    public function test_ai_module_lazy_loads_chat_panel(): void
    {
        $panel = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/js/editor/modules/ai/AiChatSidebarPanel.jsx',
        );
        self::assertStringContainsString('lazy(() => import(', $panel);
        self::assertStringContainsString('ArticleAiChatPanel', $panel);
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleEditorModuleHost.jsx',
        );
    }

    public function test_seo_article_editor_uses_runtime_portal_host(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringContainsString('EditorSidebarPortalHost', $source);
        self::assertStringContainsString('activeHeavyModule', $source);
        self::assertStringContainsString('EditorInspectorBubbleHost', $source);
    }

    public function test_legacy_module_shims_removed_transitional_modules_kept(): void
    {
        $base = ProjectRoot::addonsPath().'/content/resources/js/modules';
        foreach (['LinksModule.jsx', 'FaqModule.jsx', 'AiChatModule.jsx'] as $file) {
            self::assertFileDoesNotExist($base.'/'.$file, $file);
        }
        foreach (['ImagesModule.jsx', 'ReviewsModule.jsx', 'SeoModule.jsx'] as $file) {
            self::assertFileExists($base.'/'.$file, $file);
        }
    }
}
