<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageModelInputLengthPolicy;
use PHPUnit\Framework\TestCase;

final class ImageModelInputLengthPolicyTest extends TestCase
{
    public function test_it_measures_rendered_prompt_length(): void
    {
        self::assertSame(11, ImageModelInputLengthPolicy::measureCompiledPromptLength('  hello world '));
    }

    public function test_it_prefers_flash_tier_for_short_input(): void
    {
        $models = ImageModelInputLengthPolicy::reorderModels(
            GoogleAiModelRegistry::defaultImageModelPriority(),
            500,
        );

        self::assertSame('gemini-3.1-flash-image-preview', $models[0]);
        self::assertSame('gemini-3-pro-image-preview', $models[1]);
    }

    public function test_it_prefers_pro_tier_for_long_input(): void
    {
        $models = ImageModelInputLengthPolicy::reorderModels(
            GoogleAiModelRegistry::defaultImageModelPriority(),
            1500,
        );

        self::assertSame('gemini-3-pro-image-preview', $models[0]);
        self::assertSame('gemini-3.1-flash-image-preview', $models[1]);
    }

    public function test_registry_applies_input_length_to_custom_priority(): void
    {
        $models = GoogleAiModelRegistry::imageModelsToTry(
            preferred: null,
            excludeImagen: false,
            customPriority: [
                'imagen-4.0-generate-001',
                'gemini-3-pro-image-preview',
                'gemini-3.1-flash-image-preview',
            ],
            inputLength: 200,
        );

        self::assertSame([
            'gemini-3.1-flash-image-preview',
            'gemini-3-pro-image-preview',
            'imagen-4.0-generate-001',
        ], $models);
    }
}
