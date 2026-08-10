<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ImageProviderCapabilityResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryGenerationModeResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryModeOrchestrator;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use PHPUnit\Framework\TestCase;

final class ProductGalleryGenerationModeResolverTest extends TestCase
{
    private ProductGalleryGenerationModeResolver $resolver;

    private ImageProviderCapabilityResolver $capabilities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capabilities = new ImageProviderCapabilityResolver;
        $this->resolver = new ProductGalleryGenerationModeResolver;
    }

    public function test_auto_with_reference_support_selects_parent_child(): void
    {
        $caps = $this->capabilities->resolve('google', 'gemini-2.5-flash-image');
        $result = $this->resolver->resolve('auto', $caps);

        $this->assertSame(ProductGalleryGenerationMode::ParentChild, $result->resolved);
        $this->assertTrue($result->parentChildAvailable);
    }

    public function test_auto_without_reference_selects_sprite(): void
    {
        $caps = $this->capabilities->resolve('google', 'imagen-4.0-generate-001');
        $result = $this->resolver->resolve('auto', $caps);

        $this->assertSame(ProductGalleryGenerationMode::Sprite, $result->resolved);
        $this->assertSame('auto_provider_reference_unsupported', $result->reason);
    }

    public function test_parent_child_unsupported_falls_back_sprite(): void
    {
        $caps = $this->capabilities->resolve('google', 'imagen-4.0-generate-001');
        $result = $this->resolver->resolve('parent_child', $caps);

        $this->assertSame(ProductGalleryGenerationMode::ParentChild, $result->requested);
        $this->assertSame(ProductGalleryGenerationMode::Sprite, $result->resolved);
        $this->assertSame('provider_reference_unsupported', $result->reason);
    }

    public function test_parent_child_supported_keeps_parent_child(): void
    {
        $caps = $this->capabilities->resolve('google', 'gemini-2.5-flash-image');
        $result = $this->resolver->resolve('parent_child', $caps);

        $this->assertSame(ProductGalleryGenerationMode::ParentChild, $result->resolved);
        $this->assertSame('configured_parent_child', $result->reason);
    }

    public function test_sprite_always_sprite(): void
    {
        $caps = $this->capabilities->resolve('google', 'gemini-2.5-flash-image');
        $result = $this->resolver->resolve('sprite', $caps);

        $this->assertSame(ProductGalleryGenerationMode::Sprite, $result->resolved);
    }

    public function test_orchestrator_routes_unsupported_parent_child_to_sprite(): void
    {
        $orchestrator = new ProductGalleryModeOrchestrator($this->capabilities, $this->resolver);
        $decision = $orchestrator->decide('parent_child', 'google', 'imagen-4.0-generate-001');

        $this->assertSame('sprite', $decision['route']);
        $this->assertFalse($decision['supports_reference_image']);
    }
}
