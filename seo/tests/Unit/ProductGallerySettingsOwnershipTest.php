<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProductGallerySettingsOwnershipTest extends TestCase
{
    public function test_product_gallery_prompt_getter_uses_hook_binding_key(): void
    {
        $map = SeoCreateArticleSettingsService::LEGACY_PROMPT_FIELD_TO_HOOK;

        self::assertSame(
            'product.gallery.generate',
            $map[SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_IMAGE],
        );
    }

    public function test_form_encode_decode_product_gallery_hook_key(): void
    {
        $hook = 'product.gallery.generate';
        $encoded = SeoCreateArticleSettingsService::encodeHookKeyForForm($hook);

        self::assertSame('product__gallery__generate', $encoded);
        self::assertSame($hook, SeoCreateArticleSettingsService::decodeHookKeyFromForm($encoded));
        self::assertStringNotContainsString('.', $encoded);
    }

    public function test_workflows_page_has_zero_product_gallery_operator_fields(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoSettingsWorkflows::class))->getFileName() ?: '',
        );

        self::assertStringNotContainsString('productGallerySourceFields', $source);
        self::assertStringNotContainsString('assertProductGalleryModeConfigured', $source);
        self::assertStringNotContainsString('product_gallery_prompt_status', $source);
        self::assertStringNotContainsString(
            'KEY_CREATE_PRODUCT_GALLERY_SOURCE => $data',
            $source,
        );
        self::assertStringNotContainsString(
            'KEY_CREATE_PRODUCT_GALLERY_TASK => $data',
            $source,
        );
        self::assertStringContainsString('saveWorkflowsOperatorSettings', $source);
        self::assertStringContainsString('mergePreservingNonUserEditableBindings', $source);
    }

    public function test_system_managed_gallery_and_thumbnail_hooks_not_settings_visible(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $catalog = new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));
        $visible = array_column($catalog->settingsVisibleHooks(), 'hook_key');

        foreach (SeoCreateArticleSettingsService::SYSTEM_MANAGED_HOOK_KEYS as $hookKey) {
            self::assertNotContains($hookKey, $visible, $hookKey.' must not appear in Workflows Settings');
            $definition = (new PromptHookRuntimeRegistry($loader))->get($hookKey, '0.1.0');
            self::assertNotNull($definition, $hookKey.' must remain loadable');
            self::assertFalse($definition->settingsVisible, $hookKey.' settings_visible');

            $manifest = PromptHookDefinitionLoader::defaultV01Directory()
                .DIRECTORY_SEPARATOR.$hookKey.'@0.1.0.json';
            self::assertFileExists($manifest);
            $spec = json_decode((string) file_get_contents($manifest), true);
            self::assertIsArray($spec);
            self::assertTrue((bool) ($spec['enabled'] ?? false), $hookKey.' enabled');
            self::assertFalse((bool) ($spec['settings_visible'] ?? true), $hookKey.' settings_visible json');
        }
    }

    public function test_merge_preserves_system_gallery_and_thumbnail_bindings(): void
    {
        $service = new SeoCreateArticleSettingsService;
        $existing = [
            'article.faq.generate' => 70,
            'article.featured_image.generate' => 123,
            'product.gallery.generate' => 45,
            'product.gallery.plan' => 67,
            'product.gallery.parent.generate' => 68,
            'product.gallery.child.generate' => 69,
            'legacy.unknown.hook' => 999,
        ];

        $merged = $service->mergePreservingNonUserEditableBindings(
            [
                'article.faq.generate' => 71,
            ],
            $existing,
        );

        self::assertSame(71, $merged['article.faq.generate']);
        self::assertSame(123, $merged['article.featured_image.generate']);
        self::assertSame(45, $merged['product.gallery.generate']);
        self::assertSame(67, $merged['product.gallery.plan']);
        self::assertSame(68, $merged['product.gallery.parent.generate']);
        self::assertSame(69, $merged['product.gallery.child.generate']);
        self::assertSame(999, $merged['legacy.unknown.hook']);
    }

    public function test_merge_clears_user_binding_when_omitted_from_form(): void
    {
        $service = new SeoCreateArticleSettingsService;
        $existing = [
            'article.faq.generate' => 70,
            'article.title_suggestion' => 11,
            'product.gallery.generate' => 45,
        ];

        $merged = $service->mergePreservingNonUserEditableBindings(
            [
                'article.title_suggestion' => 11,
            ],
            $existing,
        );

        self::assertArrayNotHasKey('article.faq.generate', $merged);
        self::assertSame(11, $merged['article.title_suggestion']);
        self::assertSame(45, $merged['product.gallery.generate']);
    }

    public function test_legacy_gallery_storage_constants_still_defined(): void
    {
        self::assertSame('create_product_gallery_source', SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE);
        self::assertSame('create_product_gallery_image_task_id', SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK);
        self::assertSame('create_product_gallery_image_prompt_id', SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_IMAGE);
    }

    public function test_workflows_ui_forbidden_labels_absent_from_page_source(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoSettingsWorkflows::class))->getFileName() ?: '',
        );

        self::assertStringNotContainsString('create_product_gallery_source', $source);
        self::assertStringNotContainsString('create_product_gallery_image_task_id', $source);
        self::assertStringNotContainsString('product.gallery.generate', $source);
        self::assertStringNotContainsString('product.gallery.plan', $source);
        self::assertStringNotContainsString('product.gallery.parent.generate', $source);
        self::assertStringNotContainsString('product.gallery.child.generate', $source);
        self::assertStringNotContainsString('article.featured_image.generate', $source);
        self::assertStringNotContainsString('Create news thumbnail', $source);
    }
}
