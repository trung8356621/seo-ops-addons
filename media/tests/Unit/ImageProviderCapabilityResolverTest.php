<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ImageProviderCapabilityResolver;
use Omnichannel\Addons\Media\Support\ProductGallery\ImageProviderCapabilities;
use PHPUnit\Framework\TestCase;

final class ImageProviderCapabilityResolverTest extends TestCase
{
    public function test_gemini_image_supports_reference(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve('google', 'gemini-2.5-flash-image');

        $this->assertTrue($caps->supportsReferenceImage);
        $this->assertTrue($caps->allowsParentChild());
        $this->assertTrue($caps->supportsImageEdit);
        $this->assertSame(ImageProviderCapabilities::STATUS_SUPPORTED, $caps->supportStatus);
        $this->assertTrue($caps->referenceTransportReady);
    }

    public function test_imagen_does_not_support_reference(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve('google', 'imagen-4.0-generate-001');

        $this->assertFalse($caps->supportsReferenceImage);
        $this->assertFalse($caps->allowsParentChild());
        $this->assertTrue($caps->supportsSeed);
        $this->assertSame(ImageProviderCapabilities::STATUS_UNSUPPORTED, $caps->supportStatus);
    }

    public function test_unknown_fails_closed(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve('other', 'mystery-model');

        $this->assertFalse($caps->supportsReferenceImage);
        $this->assertSame(ImageProviderCapabilities::STATUS_UNSUPPORTED, $caps->supportStatus);
    }

    public function test_empty_fails_closed_as_unknown(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve(null, null);

        $this->assertFalse($caps->allowsParentChild());
        $this->assertSame(ImageProviderCapabilities::STATUS_UNKNOWN, $caps->supportStatus);
    }
}
