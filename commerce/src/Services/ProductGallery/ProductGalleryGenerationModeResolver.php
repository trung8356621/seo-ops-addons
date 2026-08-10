<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Support\ProductGallery\ImageProviderCapabilities;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryModeResolution;

final class ProductGalleryGenerationModeResolver
{
    /**
     * @param  string  $configuredMode  sprite | parent_child | auto | manual
     */
    public function resolve(
        string $configuredMode,
        ImageProviderCapabilities $capabilities,
    ): ProductGalleryModeResolution {
        $raw = strtolower(trim($configuredMode));
        if ($raw === '') {
            try {
                $raw = strtolower(trim((string) config('seo-content-ai.product_gallery.default_mode', 'sprite')));
            } catch (\Throwable) {
                $raw = 'sprite';
            }
        }

        $available = $capabilities->allowsParentChild();

        if ($raw === 'manual') {
            return new ProductGalleryModeResolution(
                requested: ProductGalleryGenerationMode::Manual,
                resolved: ProductGalleryGenerationMode::Manual,
                reason: 'configured_manual',
                parentChildAvailable: $available,
            );
        }

        if ($raw === 'sprite') {
            return new ProductGalleryModeResolution(
                requested: ProductGalleryGenerationMode::Sprite,
                resolved: ProductGalleryGenerationMode::Sprite,
                reason: 'configured_sprite',
                parentChildAvailable: $available,
            );
        }

        if ($raw === 'parent_child' || $raw === 'parent_children') {
            if ($available) {
                return new ProductGalleryModeResolution(
                    requested: ProductGalleryGenerationMode::ParentChild,
                    resolved: ProductGalleryGenerationMode::ParentChild,
                    reason: 'configured_parent_child',
                    parentChildAvailable: true,
                );
            }

            return new ProductGalleryModeResolution(
                requested: ProductGalleryGenerationMode::ParentChild,
                resolved: ProductGalleryGenerationMode::Sprite,
                reason: 'provider_reference_unsupported',
                parentChildAvailable: false,
            );
        }

        // auto / unknown capability → sprite (safe)
        if ($available) {
            return new ProductGalleryModeResolution(
                requested: ProductGalleryGenerationMode::ParentChild,
                resolved: ProductGalleryGenerationMode::ParentChild,
                reason: 'auto_provider_supports_reference',
                parentChildAvailable: true,
            );
        }

        $reason = $capabilities->supportStatus === ImageProviderCapabilities::STATUS_UNKNOWN
            ? 'auto_provider_reference_unknown'
            : 'auto_provider_reference_unsupported';

        return new ProductGalleryModeResolution(
            requested: ProductGalleryGenerationMode::Sprite,
            resolved: ProductGalleryGenerationMode::Sprite,
            reason: $reason,
            parentChildAvailable: false,
        );
    }
}
