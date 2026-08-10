<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Media\Services\GeminiMediaGenerationService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ImageProviderCapabilityResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryGenerationModeResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryModeOrchestrator;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryReferenceImageResolver;
use Omnichannel\Addons\Media\Support\ProductGallery\ImageProviderCapabilities;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReferenceImagePayload;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProductGalleryParentChildRuntimeIntegrationTest extends TestCase
{
    public function test_gemini_native_reference_transport_method_exists(): void
    {
        $this->assertTrue(method_exists(GeminiMediaGenerationService::class, 'generateNativeImageWithReferences'));
    }

    public function test_capability_gemini_native_supported_when_transport_ready(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve('gemini', 'gemini-2.5-flash-image');
        $this->assertSame(ImageProviderCapabilities::STATUS_SUPPORTED, $caps->supportStatus);
        $this->assertTrue($caps->allowsParentChild());
        $this->assertTrue($caps->referenceTransportReady);
    }

    public function test_capability_imagen_unsupported(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve('gemini', 'imagen-4.0-generate-001');
        $this->assertSame(ImageProviderCapabilities::STATUS_UNSUPPORTED, $caps->supportStatus);
        $this->assertFalse($caps->allowsParentChild());
    }

    public function test_capability_empty_model_unknown_auto_sprite(): void
    {
        $caps = (new ImageProviderCapabilityResolver)->resolve('gemini', '');
        $this->assertSame(ImageProviderCapabilities::STATUS_UNKNOWN, $caps->supportStatus);

        $resolution = (new ProductGalleryGenerationModeResolver)->resolve('auto', $caps);
        $this->assertSame(ProductGalleryGenerationMode::Sprite, $resolution->resolved);
        $this->assertSame('auto_provider_reference_unknown', $resolution->reason);
    }

    public function test_reference_resolver_missing_file(): void
    {
        $media = new \Omnichannel\Addons\Media\Models\SeoMedia;
        $media->forceFill(['id' => 1, 'path' => 'missing/does-not-exist-'.uniqid('', true).'.png']);
        $payload = (new ProductGalleryReferenceImageResolver)->resolveFromMedia($media, 'gemini', 'gemini-2.5-flash-image');
        $this->assertFalse($payload->isUsable());
        $this->assertSame('reference_media_missing', $payload->meta['error_code'] ?? null);
    }

    public function test_reference_payload_log_context_has_no_base64(): void
    {
        $payload = new ProductGalleryReferenceImagePayload(
            transportType: ProductGalleryReferenceImagePayload::TRANSPORT_BASE64,
            mimeType: 'image/png',
            base64: 'AAAA',
            sourceMediaId: 9,
            byteSize: 4,
        );
        $log = $payload->toLogContext();
        $this->assertArrayNotHasKey('base64', $log);
        $encoded = json_encode($log) ?: '';
        $this->assertStringNotContainsString('AAAA', $encoded);
    }

    public function test_feature_flag_without_container_is_safe_kill_switch(): void
    {
        // Pure PHPUnit has no Laravel config() — Feature catches and returns false.
        $this->assertFalse(ProductGalleryParentChildFeature::enabled());
    }

    public function test_orchestrator_routes_supported_parent_child(): void
    {
        $orchestrator = new ProductGalleryModeOrchestrator(
            new ImageProviderCapabilityResolver,
            new ProductGalleryGenerationModeResolver,
        );
        $decision = $orchestrator->decide('parent_child', 'gemini', 'gemini-2.5-flash-image');
        $this->assertSame('parent_child', $decision['route']);
        $this->assertTrue($decision['supports_reference_image']);
    }

    public function test_native_image_request_builds_inline_parts(): void
    {
        $method = new ReflectionMethod(GeminiMediaGenerationService::class, 'requestGeminiNativeImage');
        $this->assertSame(4, $method->getNumberOfParameters());
    }

    public function test_adapter_and_job_classes_exist(): void
    {
        $this->assertTrue(class_exists(\Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter::class));
        $this->assertTrue(class_exists(\Omnichannel\Addons\Media\Jobs\RunProductGalleryParentChildJob::class));
        $this->assertTrue(class_exists(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryParentChildDispatchService::class));
        $this->assertTrue(class_exists(\Omnichannel\Addons\Media\Console\ProductGalleryParentChildCanaryCommand::class));
    }
}
