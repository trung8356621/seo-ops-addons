<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Commerce\Filament\Pages\ProductGalleryCanaryPage;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryCanaryAccess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductGalleryCanaryUiTest extends TestCase
{
    public function test_canary_page_and_access_gate(): void
    {
        $this->assertTrue(class_exists(ProductGalleryCanaryPage::class));
        $page = new ReflectionClass(ProductGalleryCanaryPage::class);
        $this->assertTrue($page->hasMethod('createFixture'));
        $this->assertTrue($page->hasMethod('refreshReadiness'));
        $this->assertTrue($page->hasMethod('loadPromptPreview'));
        $this->assertTrue($page->hasMethod('discardGenerated'));

        $pageSource = (string) file_get_contents($page->getFileName() ?: '');
        $this->assertStringContainsString("pluck('domain', 'id')", $pageSource);
        $this->assertStringNotContainsString("orderBy('name')", $pageSource);
        $this->assertStringContainsString('accessibleSitesQuery()', $pageSource);

        $access = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryCanaryAccess::class))->getFileName() ?: '',
        );
        $this->assertStringContainsString('canAccessManagerFeatures', $access);
        $this->assertStringContainsString('ROLE_OWNER', $access);
        $this->assertStringContainsString('fixture_ui_enabled', $access);
        $this->assertStringContainsString('local', $access);
    }

    public function test_list_projects_exposes_canary_action_for_gate(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ListSeoProjects.php',
        );
        $this->assertStringContainsString('product_gallery_canary', $source);
        $this->assertStringContainsString('ProductGalleryCanaryAccess::allowsUi', $source);
    }

    public function test_modal_and_editor_carry_canary_badge_prop(): void
    {
        $modal = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/components/GenerateImageModal.jsx',
        );
        $this->assertStringContainsString('canaryProduct', $modal);
        $this->assertStringContainsString('Canary Product', $modal);
        $this->assertStringContainsString('parent_child', $modal);
        $this->assertStringContainsString('parentChildAllowed', $modal);

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        $this->assertStringContainsString('canaryProduct={isCanaryProduct}', $editor);
        $this->assertStringContainsString('parentChildAllowed={parentChildAllowed}', $editor);

        $bootstrap = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        $this->assertStringContainsString('isCanaryProduct,', $bootstrap);
        $this->assertStringContainsString('isCanaryProduct={isCanaryProduct}', $bootstrap);

        $editArticle = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        $this->assertStringContainsString('is_canary_product', $editArticle);
        $this->assertStringContainsString("'isCanaryProduct'", $editArticle);
    }

    public function test_canary_page_blade_has_test_sequence_and_history(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/product-gallery-canary.blade.php'),
        );
        $this->assertStringContainsString('Test A â€” Mode 1 PASS', $blade);
        $this->assertStringContainsString('Test C â€” Mode 2', $blade);
        $this->assertStringContainsString('Execution history', $blade);
        $this->assertStringContainsString('Preview prompts', $blade);
        $this->assertStringContainsString('XÃ³a káº¿t quáº£ canary Ä‘Ã£ generate', $blade);
        $this->assertTrue(
            str_starts_with(ltrim($blade), '<x-filament-panels::page>'),
            'Filament page must be Livewire root (no outer wrapper div)',
        );
    }

    public function test_mode1_and_mode2_regression_hooks_still_present(): void
    {
        $this->assertTrue(class_exists(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPipelineService::class));
        $this->assertTrue(class_exists(\Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter::class));
        $this->assertFalse(\Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter::FALLBACK_BRIEF_ENABLED);
    }
}
