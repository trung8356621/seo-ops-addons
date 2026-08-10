<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductGalleryParentChildFeatureGaTest extends TestCase
{
    public function test_config_defaults_mode2_ga_on_via_env(): void
    {
        $featureFile = (string) ((new ReflectionClass(ProductGalleryParentChildFeature::class))->getFileName() ?: '');
        $projectRoot = dirname($featureFile, 6);
        $configFile = $projectRoot.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'seo-content-ai.php';
        self::assertFileExists($configFile);
        $src = (string) file_get_contents($configFile);

        self::assertStringContainsString('SEO_PRODUCT_GALLERY_PARENT_CHILD_ENABLED', $src);
        self::assertStringContainsString("env('SEO_PRODUCT_GALLERY_PARENT_CHILD_ENABLED', true)", $src);
        self::assertDoesNotMatchRegularExpression(
            "/'parent_child'\\s*=>\\s*\\[[^\\]]*?'enabled'\\s*=>\\s*false/s",
            $src,
        );
    }

    public function test_feature_enabled_fallback_default_is_true(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryParentChildFeature::class))->getFileName() ?: '',
        );
        self::assertStringContainsString(
            "config('seo-content-ai.product_gallery.parent_child.enabled', true)",
            $src,
        );
    }

    public function test_allows_article_semantics_documented_in_source(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ProductGalleryParentChildFeature::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('if (! self::enabled())', $src);
        self::assertStringContainsString('Enabled with empty allowlist = all articles', $src);
        self::assertStringContainsString('canary_article_ids', $src);
        self::assertStringContainsString('isCanaryArticle', $src);
    }

    public function test_dispatch_guard_still_rejects_when_feature_blocks_article(): void
    {
        $dispatch = (string) file_get_contents(
            ProjectRoot::addonsPath().'/commerce/src/Services/ProductGallery/ProductGalleryParentChildDispatchService.php',
        );
        self::assertStringContainsString('ProductGalleryParentChildFeature::allowsArticle', $dispatch);
        self::assertStringContainsString('Mode 2 Parent/Child is disabled for this article.', $dispatch);
        self::assertStringContainsString("'error_code' => 'feature_disabled'", $dispatch);
    }

    public function test_editor_bootstrap_exposes_parent_child_allowed_from_feature(): void
    {
        $editArticle = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString("'parentChildAllowed'", $editArticle);
        self::assertStringContainsString("'parent_child_allowed'", $editArticle);
        self::assertStringContainsString('resolveParentChildAllowedForEditor', $editArticle);
        self::assertStringContainsString('ProductGalleryParentChildFeature::allowsArticle', $editArticle);
        self::assertStringContainsString('parentChildReason', $editArticle);
    }

    public function test_frontend_disables_parent_child_when_not_allowed(): void
    {
        $modal = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/components/GenerateImageModal.jsx',
        );
        self::assertStringContainsString('parentChildAllowed', $modal);
        self::assertStringContainsString('disabled: !parentChildAllowed', $modal);
        self::assertStringContainsString('generate_image_mode2_feature_disabled', $modal);

        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('parentChildAllowed={parentChildAllowed}', $editor);

        $bootstrap = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('parentChildAllowed', $bootstrap);
        self::assertStringContainsString('parent_child_allowed', $bootstrap);
    }

    public function test_feature_flag_without_container_stays_safe_kill(): void
    {
        // Pure PHPUnit: config() unavailable â†’ catch â†’ false (safe when boot broken).
        self::assertFalse(ProductGalleryParentChildFeature::enabled());
        self::assertFalse(ProductGalleryParentChildFeature::allowsArticle(2626));
    }
}
