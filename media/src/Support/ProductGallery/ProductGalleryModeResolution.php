<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Resolved Mode 2/1 choice for a gallery run.
 */
final class ProductGalleryModeResolution
{
    public function __construct(
        public readonly ProductGalleryGenerationMode $requested,
        public readonly ProductGalleryGenerationMode $resolved,
        public readonly string $reason = '',
        public readonly bool $parentChildAvailable = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested->value,
            'resolved' => $this->resolved->value,
            'reason' => $this->reason,
            'parent_child_available' => $this->parentChildAvailable,
        ];
    }
}
