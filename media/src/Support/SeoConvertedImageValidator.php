<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * Validate ảnh sau resize/encode — so sánh signature source vs output.
 * Không blacklist màu đen/trắng đơn thuần (false positive ảnh đồng màu hợp lệ).
 */
final class SeoConvertedImageValidator
{
    public const REASON_MISSING = 'missing_or_empty_file';

    public const REASON_TOO_SMALL = 'file_too_small';

    public const REASON_UNDECODEABLE = 'undecodeable';

    public const REASON_BAD_DIMENSIONS = 'invalid_dimensions';

    public const REASON_DIMENSION_MISMATCH = 'dimension_mismatch';

    /** @deprecated Dùng REASON_FULLY_TRANSPARENT_CANVAS */
    public const REASON_FULLY_TRANSPARENT = 'fully_transparent_canvas';

    public const REASON_FULLY_TRANSPARENT_CANVAS = 'fully_transparent_canvas';

    public const REASON_EMPTY_CANVAS = 'empty_canvas_vs_source';

    public const REASON_CONTENT_COLLAPSED = 'content_collapsed_during_conversion';

    public const REASON_CONTENT_COLLAPSED_UNIFORM = 'content_collapsed_to_uniform_canvas';

    /** @deprecated Không reject ảnh chỉ vì đen — dùng content_collapsed khi source có variance */
    public const REASON_SOLID_BLACK = 'content_collapsed_during_conversion';

    public function __construct(
        private readonly ImageContentSignatureSampler $sampler = new ImageContentSignatureSampler,
    ) {}

    /**
     * @param  array{
     *     expected_width?: int,
     *     expected_height?: int,
     *     source_path?: string,
     *     source_bytes?: string,
     *     source_signature?: ImageContentSignature
     * }|null  $sourceMetadata
     * @return array{
     *     ok: bool,
     *     reason: string,
     *     width: int,
     *     height: int,
     *     bytes: int,
     *     signature?: array<string, int|float|bool>,
     *     source_signature?: array<string, int|float|bool>
     * }
     */
    public function validate(string $path, ?array $sourceMetadata = null): array
    {
        $fail = static function (
            string $reason,
            int $bytes = 0,
            int $width = 0,
            int $height = 0,
            ?ImageContentSignature $signature = null,
            ?ImageContentSignature $sourceSignature = null,
        ): array {
            $payload = [
                'ok' => false,
                'reason' => $reason,
                'width' => $width,
                'height' => $height,
                'bytes' => $bytes,
            ];
            if ($signature !== null) {
                $payload['signature'] = $signature->summary();
            }
            if ($sourceSignature !== null) {
                $payload['source_signature'] = $sourceSignature->summary();
            }

            return $payload;
        };

        if (! is_file($path)) {
            return $fail(self::REASON_MISSING);
        }

        $bytes = (int) filesize($path);
        if ($bytes < 64) {
            return $fail(self::REASON_TOO_SMALL, $bytes);
        }

        $info = @getimagesize($path);
        if (! is_array($info)) {
            return $fail(self::REASON_UNDECODEABLE, $bytes);
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return $fail(self::REASON_BAD_DIMENSIONS, $bytes, $width, $height);
        }

        $expectedWidth = max(0, (int) ($sourceMetadata['expected_width'] ?? 0));
        $expectedHeight = max(0, (int) ($sourceMetadata['expected_height'] ?? 0));
        if ($expectedWidth > 0 && $expectedHeight > 0) {
            if (abs($width - $expectedWidth) > 1 || abs($height - $expectedHeight) > 1) {
                return $fail(self::REASON_DIMENSION_MISMATCH, $bytes, $width, $height);
            }
        }

        if (! $this->canDecode($path)) {
            return $fail(self::REASON_UNDECODEABLE, $bytes, $width, $height);
        }

        $signature = $this->sampler->fromPath($path);
        if ($signature === null) {
            return $fail(self::REASON_UNDECODEABLE, $bytes, $width, $height);
        }

        if ($signature->fullyTransparent) {
            return $fail(
                self::REASON_FULLY_TRANSPARENT_CANVAS,
                $bytes,
                $width,
                $height,
                $signature,
            );
        }

        $sourceSignature = $this->resolveSourceSignature($sourceMetadata);
        if ($sourceSignature !== null) {
            $collapseReason = $this->detectContentCollapse($sourceSignature, $signature);
            if ($collapseReason !== null) {
                return $fail(
                    $collapseReason,
                    $bytes,
                    $width,
                    $height,
                    $signature,
                    $sourceSignature,
                );
            }
        }

        return [
            'ok' => true,
            'reason' => '',
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
            'signature' => $signature->summary(),
            'source_signature' => $sourceSignature?->summary(),
        ];
    }

    /**
     * Validate source trước convert — blank alpha=0 fail ngay.
     *
     * @return array{ok: bool, reason: string, width: int, height: int, bytes: int, signature?: array<string, int|float|bool>}
     */
    public function validateSource(string $path): array
    {
        return $this->validate($path);
    }

    public function signatureFromPath(string $path): ?ImageContentSignature
    {
        return $this->sampler->fromPath($path);
    }

    public function detectImageExtensionFromBytes(string $binary): ?string
    {
        if (strlen($binary) < 12) {
            return null;
        }

        if (strncmp($binary, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'png';
        }

        if (strncmp($binary, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }

        if (strncmp($binary, 'GIF8', 4) === 0) {
            return 'gif';
        }

        if (strncmp($binary, 'RIFF', 4) === 0 && substr($binary, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    /**
     * Source có nội dung/variance nhưng output mất chi tiết / đồng màu.
     */
    public function detectContentCollapse(
        ImageContentSignature $source,
        ImageContentSignature $output,
    ): ?string {
        if (! $source->hasVisibleContent()) {
            return null;
        }

        if ($output->fullyTransparent) {
            return self::REASON_CONTENT_COLLAPSED;
        }

        if ($output->visibleRatio < 0.02 && $source->visibleRatio > 0.1) {
            return self::REASON_CONTENT_COLLAPSED;
        }

        // Source có variance rõ ràng nhưng output gần đồng màu.
        if ($source->hasSignificantVariance() && $output->nearUniform) {
            return self::REASON_CONTENT_COLLAPSED_UNIFORM;
        }

        // Source có chi tiết (luma_std cao) nhưng output variance gần 0.
        if ($source->lumaStd > 0.05 && $output->lumaStd < 0.015) {
            return self::REASON_CONTENT_COLLAPSED;
        }

        if ($source->distinctBuckets >= 6 && $output->distinctBuckets <= 2) {
            return self::REASON_CONTENT_COLLAPSED_UNIFORM;
        }

        return null;
    }

    /**
     * @param  array{
     *     source_path?: string,
     *     source_bytes?: string,
     *     source_signature?: ImageContentSignature
     * }|null  $sourceMetadata
     */
    private function resolveSourceSignature(?array $sourceMetadata): ?ImageContentSignature
    {
        if ($sourceMetadata === null) {
            return null;
        }

        if (($sourceMetadata['source_signature'] ?? null) instanceof ImageContentSignature) {
            return $sourceMetadata['source_signature'];
        }

        $sourcePath = isset($sourceMetadata['source_path']) && is_string($sourceMetadata['source_path'])
            ? $sourceMetadata['source_path']
            : null;
        if ($sourcePath !== null && is_file($sourcePath)) {
            return $this->sampler->fromPath($sourcePath);
        }

        $sourceBytes = isset($sourceMetadata['source_bytes']) && is_string($sourceMetadata['source_bytes'])
            ? $sourceMetadata['source_bytes']
            : null;
        if ($sourceBytes !== null && $sourceBytes !== '') {
            $ext = $this->detectImageExtensionFromBytes($sourceBytes) ?? 'bin';

            return $this->sampler->fromBytes($sourceBytes, $ext);
        }

        return null;
    }

    private function canDecode(string $path): bool
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                $imagick = new \Imagick($path);
                $ok = $imagick->getImageWidth() > 0 && $imagick->getImageHeight() > 0;
                $imagick->clear();
                $imagick->destroy();

                return $ok;
            } catch (\Throwable) {
                // fall through
            }
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $image = match ($extension) {
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        };
        if ($image === false) {
            return false;
        }

        imagedestroy($image);

        return true;
    }
}
