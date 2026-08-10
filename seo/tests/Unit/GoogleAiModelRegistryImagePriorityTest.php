<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use PHPUnit\Framework\TestCase;

final class GoogleAiModelRegistryImagePriorityTest extends TestCase
{
    public function test_it_uses_custom_priority_list(): void
    {
        $models = GoogleAiModelRegistry::imageModelsToTry(
            preferred: null,
            excludeImagen: false,
            customPriority: [
                'gemini-3-pro-image-preview',
                'gemini-3.1-flash-image-preview',
                'imagen-4.0-generate-001',
            ],
            inputLength: 2000,
        );

        self::assertSame([
            'gemini-3-pro-image-preview',
            'gemini-3.1-flash-image-preview',
            'imagen-4.0-generate-001',
        ], $models);
    }

    public function test_it_excludes_imagen_for_product_context(): void
    {
        $models = GoogleAiModelRegistry::imageModelsToTry(
            preferred: null,
            excludeImagen: true,
            customPriority: GoogleAiModelRegistry::defaultImageModelPriority(),
        );

        self::assertNotContains('imagen-4.0-generate-001', $models);
        self::assertContains('gemini-3.1-flash-image-preview', $models);
    }

    public function test_it_filters_legacy_gemini_2x_from_priority(): void
    {
        $models = GoogleAiModelRegistry::resolveImageModelPriorityList([
            'gemini-2.5-flash-image',
            'gemini-3.1-flash-image-preview',
        ]);

        self::assertNotContains('gemini-2.5-flash-image', $models);
        self::assertContains('gemini-3.1-flash-image-preview', $models);
    }
}
