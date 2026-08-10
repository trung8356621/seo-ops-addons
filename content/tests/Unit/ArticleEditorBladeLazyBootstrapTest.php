<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

/**
 * Source test (no Blade compile / no DB) â€” Phase 2: edit-article.blade.php must
 * ship the light core bootstrap and must NOT eagerly call the heavy getters that
 * now live behind lazy `/editor/*` endpoints (images, faqs, faq-extract-debug).
 */
final class ArticleEditorBladeLazyBootstrapTest extends TestCase
{
    public function test_blade_renders_core_bootstrap_script(): void
    {
        $source = $this->bladeSource();

        self::assertStringContainsString('id="seo-article-core-bootstrap"', $source);
        self::assertStringContainsString('$this->getEditorCoreBootstrap()', $source);
    }

    public function test_blade_does_not_eagerly_call_heavy_faq_and_image_getters(): void
    {
        $source = $this->bladeSource();

        self::assertStringNotContainsString('$this->getEditorImagesPayload()', $source);
        self::assertStringNotContainsString('$this->getEditorFaqsPayload()', $source);
        self::assertStringNotContainsString('$this->getFaqExtractDebugPayload()', $source);
    }

    public function test_faq_root_is_a_bare_placeholder_mounted_lazily_by_js(): void
    {
        $source = $this->bladeSource();

        self::assertStringContainsString('id="seo-article-faq-root"', $source);
        self::assertStringNotContainsString('seo-article-faq-config', $source);
        self::assertStringNotContainsString('$this->getEditorSeoPayload()', $source);
        self::assertStringNotContainsString('$this->getEditorMetaPayload()', $source);
        self::assertStringNotContainsString('$this->getEditorSettingsPayload()', $source);
        self::assertStringContainsString('getArticleMediaPickerMinimalPayload', $source);

        // Compat FAQ/module-open events live in shell compatibility bridge (ModuleHost removed).
        $bridge = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/editor/runtime/editorShellCompatibilityBridge.js',
        );
        self::assertStringContainsString('article-editor:module-open', $bridge);
        self::assertStringContainsString('seo-assistant-switch-panel', $bridge);
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleEditorModuleHost.jsx',
        );
    }

    private function bladeSource(): string
    {
        $path = LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php');
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
