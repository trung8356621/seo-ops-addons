<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ProductGallery\ImageProviderCapabilities;

/**
 * Central resolver — capability from adapter+model reality, not provider name slogans.
 *
 * Reference is supported only when:
 * - Gemini native image model (generateContent multimodal), AND
 * - GeminiMediaGenerationService::generateNativeImageWithReferences exists (transport ready).
 * Imagen predict path = unsupported.
 * Empty model = unknown (auto → sprite).
 */
final class ImageProviderCapabilityResolver
{
    public function resolve(?string $provider, ?string $model): ImageProviderCapabilities
    {
        $provider = strtolower(trim((string) $provider));
        $model = trim((string) $model);
        $slug = $model !== '' ? GoogleAiModelRegistry::normalizeSlug($model) : '';
        $transportReady = method_exists(
            \Omnichannel\Addons\Media\Services\GeminiMediaGenerationService::class,
            'generateNativeImageWithReferences',
        );

        if ($slug === '' && $provider === '') {
            return new ImageProviderCapabilities(
                supportsReferenceImage: false,
                provider: $provider,
                model: $slug,
                supportStatus: ImageProviderCapabilities::STATUS_UNKNOWN,
                referenceTransportReady: $transportReady,
            );
        }

        if ($slug === '') {
            return new ImageProviderCapabilities(
                supportsReferenceImage: false,
                provider: $provider !== '' ? $provider : 'google',
                model: '',
                supportStatus: ImageProviderCapabilities::STATUS_UNKNOWN,
                referenceTransportReady: $transportReady,
            );
        }

        if (GoogleAiModelRegistry::isImagenModel($slug)) {
            return new ImageProviderCapabilities(
                supportsReferenceImage: false,
                supportsImageEdit: false,
                supportsMultiReference: false,
                supportsSeed: true,
                provider: $provider !== '' ? $provider : 'google',
                model: $slug,
                supportStatus: ImageProviderCapabilities::STATUS_UNSUPPORTED,
                referenceTransportReady: false,
            );
        }

        $isGeminiImage = GoogleAiModelRegistry::isGeminiNativeImageModel($slug)
            || GoogleAiModelRegistry::categoryOf($slug) === GoogleAiModelRegistry::CATEGORY_IMAGE_GEMINI;

        if ($isGeminiImage && $transportReady) {
            return new ImageProviderCapabilities(
                supportsReferenceImage: true,
                supportsImageEdit: true,
                supportsMultiReference: true,
                supportsSeed: false,
                provider: $provider !== '' ? $provider : 'google',
                model: $slug,
                supportStatus: ImageProviderCapabilities::STATUS_SUPPORTED,
                referenceTransportReady: true,
            );
        }

        if ($isGeminiImage && ! $transportReady) {
            return new ImageProviderCapabilities(
                supportsReferenceImage: false,
                provider: $provider !== '' ? $provider : 'google',
                model: $slug,
                supportStatus: ImageProviderCapabilities::STATUS_UNSUPPORTED,
                referenceTransportReady: false,
            );
        }

        return new ImageProviderCapabilities(
            supportsReferenceImage: false,
            provider: $provider,
            model: $slug,
            supportStatus: ImageProviderCapabilities::STATUS_UNSUPPORTED,
            referenceTransportReady: false,
        );
    }
}
