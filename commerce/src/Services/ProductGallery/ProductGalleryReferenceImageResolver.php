<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReferenceImagePayload;
use Illuminate\Support\Facades\Storage;

/**
 * Build provider-safe reference payloads — never log base64/binary.
 */
final class ProductGalleryReferenceImageResolver
{
    private const MAX_BYTES = 4_000_000;

    /** @var list<string> */
    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function resolveFromMedia(
        SeoMedia $media,
        ?string $provider = null,
        ?string $model = null,
    ): ProductGalleryReferenceImagePayload {
        $path = $this->absolutePath($media);
        if ($path === null) {
            return new ProductGalleryReferenceImagePayload(
                transportType: ProductGalleryReferenceImagePayload::TRANSPORT_UNSUPPORTED,
                mimeType: '',
                base64: null,
                sourceMediaId: (int) $media->id,
                meta: ['error_code' => 'reference_media_missing'],
            );
        }

        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || $bytes === '') {
            return new ProductGalleryReferenceImagePayload(
                transportType: ProductGalleryReferenceImagePayload::TRANSPORT_UNSUPPORTED,
                mimeType: '',
                base64: null,
                sourceMediaId: (int) $media->id,
                meta: ['error_code' => 'reference_media_unreadable'],
            );
        }

        $size = strlen($bytes);
        if ($size > self::MAX_BYTES) {
            return new ProductGalleryReferenceImagePayload(
                transportType: ProductGalleryReferenceImagePayload::TRANSPORT_UNSUPPORTED,
                mimeType: '',
                base64: null,
                sourceMediaId: (int) $media->id,
                byteSize: $size,
                meta: ['error_code' => 'reference_media_too_large', 'max_bytes' => self::MAX_BYTES],
            );
        }

        $mime = $this->detectMime($path, $bytes);
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            return new ProductGalleryReferenceImagePayload(
                transportType: ProductGalleryReferenceImagePayload::TRANSPORT_UNSUPPORTED,
                mimeType: $mime,
                base64: null,
                sourceMediaId: (int) $media->id,
                byteSize: $size,
                meta: ['error_code' => 'reference_mime_invalid'],
            );
        }

        // Gemini native image models: official transport = inline base64 part.
        $normalizedMime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;

        return new ProductGalleryReferenceImagePayload(
            transportType: ProductGalleryReferenceImagePayload::TRANSPORT_BASE64,
            mimeType: $normalizedMime,
            base64: base64_encode($bytes),
            sourceMediaId: (int) $media->id,
            byteSize: $size,
            meta: [
                'provider' => (string) $provider,
                'model' => (string) $model,
            ],
        );
    }

    private function absolutePath(SeoMedia $media): ?string
    {
        $relative = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($relative === '' || str_contains($relative, 'placeholder')) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($relative)) {
                return null;
            }

            return $disk->path($relative);
        } catch (\Throwable) {
            return null;
        }
    }

    private function detectMime(string $path, string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if (is_array($info) && isset($info['mime']) && is_string($info['mime'])) {
            return strtolower($info['mime']);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($bytes);
        if (is_string($detected) && $detected !== '') {
            return strtolower($detected);
        }

        return 'application/octet-stream';
    }
}
