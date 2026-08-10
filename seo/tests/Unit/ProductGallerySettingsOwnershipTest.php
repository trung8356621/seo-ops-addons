<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

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

    public function test_media_section_has_no_duplicate_gallery_prompt_field(): void
    {
        $method = new ReflectionMethod(SeoSettingsWorkflows::class, 'productGallerySourceFields');
        $method->setAccessible(true);
        $page = (new ReflectionClass(SeoSettingsWorkflows::class))->newInstanceWithoutConstructor();
        /** @var list<\Filament\Forms\Components\Component> $fields */
        $fields = $method->invoke($page);

        $names = [];
        foreach ($fields as $field) {
            if (method_exists($field, 'getName')) {
                $names[] = $field->getName();
            }
        }

        self::assertContains(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE, $names);
        self::assertContains(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK, $names);
        self::assertContains('product_gallery_prompt_status', $names);
        self::assertNotContains(SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_IMAGE, $names);
        self::assertFalse(
            in_array('create_product_gallery_image_prompt_id', $names, true),
        );
    }

    public function test_assert_product_gallery_prompt_mode_requires_binding(): void
    {
        $method = new ReflectionMethod(SeoSettingsWorkflows::class, 'assertProductGalleryModeConfigured');
        $method->setAccessible(true);
        $page = (new ReflectionClass(SeoSettingsWorkflows::class))->newInstanceWithoutConstructor();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($page, [
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE => SeoCreateArticleSettingsService::SOURCE_PROMPT,
        ], []);
    }

    public function test_assert_product_gallery_workflow_mode_requires_task(): void
    {
        $method = new ReflectionMethod(SeoSettingsWorkflows::class, 'assertProductGalleryModeConfigured');
        $method->setAccessible(true);
        $page = (new ReflectionClass(SeoSettingsWorkflows::class))->newInstanceWithoutConstructor();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($page, [
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE => SeoCreateArticleSettingsService::SOURCE_WORKFLOW,
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK => null,
        ], [
            'product.gallery.generate' => 99,
        ]);
    }

    public function test_assert_product_gallery_modes_pass_when_configured(): void
    {
        $method = new ReflectionMethod(SeoSettingsWorkflows::class, 'assertProductGalleryModeConfigured');
        $method->setAccessible(true);
        $page = (new ReflectionClass(SeoSettingsWorkflows::class))->newInstanceWithoutConstructor();

        $method->invoke($page, [
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE => SeoCreateArticleSettingsService::SOURCE_PROMPT,
        ], [
            'product.gallery.generate' => 12,
        ]);

        $method->invoke($page, [
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE => SeoCreateArticleSettingsService::SOURCE_WORKFLOW,
            SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK => 5,
        ], []);

        self::assertTrue(true);
    }
}
