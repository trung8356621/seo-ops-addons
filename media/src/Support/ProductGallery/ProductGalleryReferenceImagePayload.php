<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

/**
 * Typed reference payload for Mode 2 child (and optional parent) generation.
 */
final class ProductGalleryReferenceImagePayload
{
    public const TRANSPORT_BASE64 = 'base64_inline';

    public const TRANSPORT_UNSUPPORTED = 'unsupported';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $transportType,
        public readonly string $mimeType,
        public readonly ?string $base64,
        public readonly int $sourceMediaId,
        public readonly int $byteSize = 0,
        public readonly array $meta = [],
    ) {}

    public function isUsable(): bool
    {
        return $this->transportType === self::TRANSPORT_BASE64
            && is_string($this->base64)
            && $this->base64 !== ''
            && $this->mimeType !== '';
    }

    /**
     * @return array{mime_type: string, base64: string}
     */
    public function toGeminiInlinePart(): array
    {
        return [
            'mime_type' => $this->mimeType,
            'base64' => (string) $this->base64,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'transport_type' => $this->transportType,
            'mime_type' => $this->mimeType,
            'source_media_id' => $this->sourceMediaId,
            'byte_size' => $this->byteSize,
            'meta' => $this->meta,
        ];
    }
}
