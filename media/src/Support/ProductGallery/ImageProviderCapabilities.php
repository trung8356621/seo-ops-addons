<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Provider image capability contract — Mode 2 availability gate.
 */
final class ImageProviderCapabilities
{
    public const STATUS_SUPPORTED = 'supported';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public readonly bool $supportsReferenceImage,
        public readonly bool $supportsImageEdit = false,
        public readonly bool $supportsMultiReference = false,
        public readonly bool $supportsSeed = false,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly string $supportStatus = self::STATUS_UNSUPPORTED,
        public readonly bool $referenceTransportReady = false,
    ) {}

    public function allowsParentChild(): bool
    {
        return $this->supportStatus === self::STATUS_SUPPORTED
            && $this->supportsReferenceImage
            && $this->referenceTransportReady;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'supports_reference_image' => $this->supportsReferenceImage,
            'supports_image_edit' => $this->supportsImageEdit,
            'supports_multi_reference' => $this->supportsMultiReference,
            'supports_seed' => $this->supportsSeed,
            'provider' => $this->provider,
            'model' => $this->model,
            'support_status' => $this->supportStatus,
            'reference_transport_ready' => $this->referenceTransportReady,
        ];
    }
}
