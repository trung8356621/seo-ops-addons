<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use Omnichannel\Addons\Media\Support\ImageCapability;
use Omnichannel\Addons\Media\Support\ImageCapabilityResolver;
use Omnichannel\Addons\Media\Support\ImageRoutingStrategy;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use Omnichannel\Addons\Content\Support\TypographyComplexity;
use Omnichannel\Addons\AiPrompt\Support\VisionValidationModelRouter;
use PHPUnit\Framework\TestCase;

final class ImageRoutingStrategyTest extends TestCase
{
    public function test_image_tool_type_helpers(): void
    {
        self::assertTrue(ImageToolType::Image->isImagePipeline());
        self::assertTrue(ImageToolType::ImageTypography->isImagePipeline());
        self::assertTrue(ImageToolType::ImageTypography->isTypography());
        self::assertFalse(ImageToolType::Default->isImagePipeline());
        self::assertSame(ImageToolType::ImageTypography, ImageToolType::fromMixed('image_typography'));
        self::assertSame(ImageToolType::Default, ImageToolType::fromMixed('unknown-tool'));
    }

    public function test_capability_resolver_maps_known_models(): void
    {
        $resolver = new ImageCapabilityResolver();

        self::assertContains(
            ImageCapability::TextGeneration->value,
            $resolver->resolve('gemini-3-flash-preview'),
        );
        self::assertContains(
            ImageCapability::ImageInput->value,
            $resolver->resolve('gemini-3-flash-preview'),
        );
        self::assertContains(
            ImageCapability::ImageGeneration->value,
            $resolver->resolve('gemini-3.1-flash-image-preview'),
        );
        self::assertContains(
            ImageCapability::TypographySupported->value,
            $resolver->resolve('gemini-3.1-flash-image-preview'),
        );
        self::assertContains(
            ImageCapability::TypographyRecommended->value,
            $resolver->resolve('gemini-3-pro-image-preview'),
        );
        self::assertContains(
            ImageCapability::GeneralImage->value,
            $resolver->resolve('imagen-4.0-generate-001'),
        );
        self::assertFalse(
            $resolver->hasCapability('imagen-4.0-generate-001', ImageCapability::TypographySupported->value),
        );
        self::assertSame([ImageCapability::Unknown->value], $resolver->resolve('totally-unknown-xyz'));
    }

    public function test_legacy_gemini_2x_disabled_from_auto_routing(): void
    {
        $decision = GeminiModelVersionPolicy::routingDecision('gemini-2.5-flash-image');
        self::assertSame(GeminiModelVersionPolicy::ROUTING_DISABLED, $decision['routing_status']);
        self::assertSame(GeminiModelVersionPolicy::REASON_LEGACY_VERSION, $decision['disabled_reason']);

        $strategy = new ImageRoutingStrategy();
        $models = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 100,
            configuredPriorityList: [
                'gemini-2.5-flash-image',
                'gemini-2.5-pro-image',
                'gemini-3.1-flash-image-preview',
            ],
        );

        self::assertNotContains('gemini-2.5-flash-image', $models);
        self::assertNotContains('gemini-2.5-pro-image', $models);
        self::assertContains('gemini-3.1-flash-image-preview', $models);
    }

    public function test_typography_tool_keeps_only_typography_supported_v3(): void
    {
        $strategy = new ImageRoutingStrategy();
        $models = $strategy->modelsToTry(
            toolType: ImageToolType::ImageTypography,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 9999,
            configuredPriorityList: [
                'imagen-4.0-generate-001',
                'gemini-2.5-flash-image',
                'gemini-3.1-flash-image-preview',
                'gemini-3-pro-image-preview',
            ],
        );

        self::assertNotContains('imagen-4.0-generate-001', $models);
        self::assertNotContains('gemini-2.5-flash-image', $models);
        self::assertContains('gemini-3.1-flash-image-preview', $models);
        self::assertContains('gemini-3-pro-image-preview', $models);
        self::assertSame('gemini-3-pro-image-preview', $models[0]);
    }

    public function test_product_context_excludes_imagen(): void
    {
        $strategy = new ImageRoutingStrategy();
        $models = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 100,
            productContext: true,
            configuredPriorityList: [
                'gemini-3.1-flash-image-preview',
                'imagen-4.0-generate-001',
            ],
        );

        self::assertSame(['gemini-3.1-flash-image-preview'], $models);
    }

    public function test_balanced_image_keeps_length_policy_order(): void
    {
        $strategy = new ImageRoutingStrategy();
        $short = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 200,
            configuredPriorityList: [
                'imagen-4.0-generate-001',
                'gemini-3-pro-image-preview',
                'gemini-3.1-flash-image-preview',
            ],
        );

        self::assertSame([
            'gemini-3.1-flash-image-preview',
            'gemini-3-pro-image-preview',
            'imagen-4.0-generate-001',
        ], $short);

        $long = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 1500,
            configuredPriorityList: [
                'imagen-4.0-generate-001',
                'gemini-3-pro-image-preview',
                'gemini-3.1-flash-image-preview',
            ],
        );

        self::assertSame('gemini-3-pro-image-preview', $long[0]);
    }

    public function test_typography_ignores_compiled_prompt_length_as_sole_router(): void
    {
        $strategy = new ImageRoutingStrategy();
        $short = $strategy->modelsToTry(
            toolType: ImageToolType::ImageTypography,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 50,
            typographyComplexity: TypographyComplexity::empty(),
            configuredPriorityList: [
                'gemini-3.1-flash-image-preview',
                'gemini-3-pro-image-preview',
            ],
        );
        $long = $strategy->modelsToTry(
            toolType: ImageToolType::ImageTypography,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 9000,
            configuredPriorityList: [
                'gemini-3.1-flash-image-preview',
                'gemini-3-pro-image-preview',
            ],
        );

        self::assertSame($short, $long);
        self::assertSame('gemini-3-pro-image-preview', $short[0]);
    }

    public function test_unknown_excluded_unless_admin_enabled(): void
    {
        $strategy = new ImageRoutingStrategy();
        $capabilitiesBySlug = [
            'custom-mystery-image' => [
                'resolved' => [ImageCapability::Unknown->value],
            ],
            'gemini-3.1-flash-image-preview' => [
                'resolved' => [
                    ImageCapability::ImageGeneration->value,
                    ImageCapability::GeneralImage->value,
                    ImageCapability::TypographySupported->value,
                ],
            ],
        ];

        $without = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::CostFirst,
            compiledPromptLength: 100,
            configuredPriorityList: ['custom-mystery-image', 'gemini-3.1-flash-image-preview'],
            capabilitiesBySlug: $capabilitiesBySlug,
        );
        self::assertSame(['gemini-3.1-flash-image-preview'], $without);

        $with = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::CostFirst,
            compiledPromptLength: 100,
            configuredPriorityList: ['custom-mystery-image', 'gemini-3.1-flash-image-preview'],
            adminEnabledUnknownSlugs: ['custom-mystery-image'],
            capabilitiesBySlug: $capabilitiesBySlug,
        );
        self::assertContains('custom-mystery-image', $with);
    }

    public function test_execution_policy_typography_exact_text_increases_candidates(): void
    {
        $strategy = new ImageRoutingStrategy;
        $complexity = new TypographyComplexity(
            visibleTextChars: 120,
            textBlockCount: 3,
            maxTextBlockLength: 40,
            exactTextRequired: true,
            visibleTextBlocks: [
                ['id' => 't1', 'text' => 'Hello', 'required' => true, 'weight' => 1.0, 'type' => 'title'],
            ],
            complexityScore: 0.55,
        );

        $policy = $strategy->executionPolicy(
            toolType: ImageToolType::ImageTypography,
            preference: RenderingPreference::Balanced,
            typographyComplexity: $complexity,
            configuredPriorityList: ['gemini-3-pro-image-preview', 'gemini-3.1-flash-image-preview'],
            validationEnabled: true,
        );

        self::assertGreaterThanOrEqual(2, $policy->candidateCount);
        self::assertTrue($policy->validationRequired);
        self::assertNotSame([], $policy->models);
    }

    public function test_typography_fallback_uses_general_image_priority(): void
    {
        $strategy = new ImageRoutingStrategy();

        $withoutFallback = $strategy->executionPolicy(
            toolType: ImageToolType::ImageTypography,
            preference: RenderingPreference::Balanced,
            configuredPriorityList: ['imagen-4.0-generate-001'],
            allowGeneralImageFallback: false,
            generalImageFallbackPriorityList: ['gemini-3.1-flash-image-preview'],
        );
        self::assertSame([], $withoutFallback->models);

        $withFallback = $strategy->executionPolicy(
            toolType: ImageToolType::ImageTypography,
            preference: RenderingPreference::Balanced,
            configuredPriorityList: ['imagen-4.0-generate-001'],
            allowGeneralImageFallback: true,
            generalImageFallbackPriorityList: ['gemini-3.1-flash-image-preview', 'imagen-4.0-generate-001'],
        );

        self::assertContains('gemini-3.1-flash-image-preview', $withFallback->models);
        self::assertTrue($withFallback->typographyWarning);
        self::assertTrue($withFallback->allowGeneralImageFallback);
    }

    public function test_vision_validation_router_excludes_legacy_and_image_only(): void
    {
        $router = new VisionValidationModelRouter();
        $models = $router->modelsToTry();

        self::assertNotContains('gemini-2.5-flash', $models);
        self::assertNotContains('gemini-2.5-flash-image', $models);
        self::assertNotContains('gemini-3.1-flash-image-preview', $models);
        self::assertContains('gemini-3.5-flash-preview', $models);
        self::assertSame('gemini-3.5-flash-preview', $router->resolvePrimary());
    }

    public function test_product_context_imagen_only_falls_back_to_canonical_gemini(): void
    {
        $strategy = new ImageRoutingStrategy();
        $configured = ['imagen-4.0-generate-001'];

        $eligibleConfigured = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            productContext: true,
            configuredPriorityList: $configured,
        );

        self::assertNotContains('imagen-4.0-generate-001', $eligibleConfigured);
        self::assertContains('gemini-3.1-flash-image-preview', $eligibleConfigured);
        self::assertContains('gemini-3-pro-image-preview', $eligibleConfigured);
        self::assertSame(['imagen-4.0-generate-001'], $configured);
    }

    public function test_product_context_keeps_configured_gemini_without_fallback(): void
    {
        $strategy = new ImageRoutingStrategy();
        $models = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            productContext: true,
            configuredPriorityList: [
                'gemini-3.1-flash-image-preview',
                'imagen-4.0-generate-001',
            ],
        );

        self::assertSame(['gemini-3.1-flash-image-preview'], $models);
        self::assertNotContains('imagen-4.0-generate-001', $models);
    }

    public function test_non_product_context_keeps_imagen_without_fallback(): void
    {
        $strategy = new ImageRoutingStrategy();
        $models = $strategy->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            productContext: false,
            configuredPriorityList: ['imagen-4.0-generate-001'],
        );

        self::assertSame(['imagen-4.0-generate-001'], $models);
    }
}
