<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Roundtrip ownership: changing one USER Workflows field must not wipe SYSTEM/legacy keys.
 * Uses merge + constant contracts (no WpOption) — mirrors SeoSettingsWorkflows save path.
 */
final class WorkflowsSettingsBindingsRoundtripTest extends TestCase
{
    public function test_roundtrip_preserves_system_bindings_and_legacy_gallery_keys_contract(): void
    {
        $service = new SeoCreateArticleSettingsService;

        $seededBindings = [
            'article.faq.generate' => 70,
            'article.title_suggestion' => 12,
            'article.featured_image.generate' => 123,
            'product.gallery.generate' => 45,
            'product.gallery.plan' => 67,
            'product.gallery.parent.generate' => 68,
            'product.gallery.child.generate' => 69,
        ];

        // Operator changes only FAQ binding (form payload would omit system hooks).
        $formPayload = [
            'article.faq.generate' => 71,
            'article.title_suggestion' => 12,
        ];

        $after = $service->mergePreservingNonUserEditableBindings($formPayload, $seededBindings);

        self::assertSame(71, $after['article.faq.generate']);
        self::assertSame(12, $after['article.title_suggestion']);
        self::assertSame(123, $after['article.featured_image.generate']);
        self::assertSame(45, $after['product.gallery.generate']);
        self::assertSame(67, $after['product.gallery.plan']);
        self::assertSame(68, $after['product.gallery.parent.generate']);
        self::assertSame(69, $after['product.gallery.child.generate']);
    }

    public function test_restored_user_settings_keys_still_present_on_workflows_form_contract(): void
    {
        $page = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows::class))->getFileName() ?: '',
        );

        // Legitimate USER settings from stable history — still on form (never lost).
        foreach ([
            'KEY_PUBLISH_ARTICLE',
            'KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE',
            'KEY_CREATE_TYPOGRAPHY_IMAGE_PROMPT',
            'KEY_CREATE_TYPOGRAPHY_IMAGE_TASK',
            'KEY_CREATE_VIDEO_SOURCE',
            'KEY_CREATE_VIDEO',
            'KEY_CREATE_VIDEO_TASK',
        ] as $const) {
            self::assertStringContainsString($const, $page, $const.' must remain on Workflows form');
        }
        self::assertStringNotContainsString('KEY_POST_REVIEW,', $page);
    }

    public function test_save_workflows_operator_settings_strips_gallery_legacy_writes(): void
    {
        $method = new \ReflectionMethod(SeoCreateArticleSettingsService::class, 'saveWorkflowsOperatorSettings');
        $source = file_get_contents($method->getFileName() ?: '');
        self::assertIsString($source);
        self::assertStringContainsString('KEY_CREATE_PRODUCT_GALLERY_SOURCE', $source);
        self::assertStringContainsString('KEY_CREATE_PRODUCT_GALLERY_TASK', $source);
        self::assertStringContainsString('unset(', $source);
    }
}
