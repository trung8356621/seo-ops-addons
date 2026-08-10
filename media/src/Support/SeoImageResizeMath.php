<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

final class SeoImageResizeMath
{
    private const PROGRESSIVE_SCALE_FACTOR = 1.5;

    private const PROGRESSIVE_DOWNSCALE_FACTOR = 0.5;

    /**
     * @return array{width: int, height: int}
     */
    public static function outputDimensions(
        int $originalWidth,
        int $originalHeight,
        ?int $targetWidth,
        ?int $targetHeight,
    ): array {
        if ($targetWidth !== null && $targetHeight !== null) {
            return [
                'width' => max(1, $targetWidth),
                'height' => max(1, $targetHeight),
            ];
        }

        if ($targetWidth !== null) {
            $ratio = $targetWidth / max(1, $originalWidth);

            return [
                'width' => max(1, $targetWidth),
                'height' => max(1, (int) round($originalHeight * $ratio)),
            ];
        }

        if ($targetHeight !== null) {
            $ratio = $targetHeight / max(1, $originalHeight);

            return [
                'width' => max(1, (int) round($originalWidth * $ratio)),
                'height' => max(1, $targetHeight),
            ];
        }

        return [
            'width' => max(1, $originalWidth),
            'height' => max(1, $originalHeight),
        ];
    }

    public static function isUpscale(int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight): bool
    {
        return $targetWidth > $originalWidth || $targetHeight > $originalHeight;
    }

    /**
     * @return list<array{width: int, height: int}>
     */
    public static function progressiveUpscaleSteps(
        int $originalWidth,
        int $originalHeight,
        int $targetWidth,
        int $targetHeight,
    ): array {
        if (! self::isUpscale($originalWidth, $originalHeight, $targetWidth, $targetHeight)) {
            return [
                [
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                ],
            ];
        }

        $steps = [];
        $width = max(1, $originalWidth);
        $height = max(1, $originalHeight);

        while ($width < $targetWidth || $height < $targetHeight) {
            $nextWidth = min(
                $targetWidth,
                max($width + 1, (int) ceil($width * self::PROGRESSIVE_SCALE_FACTOR)),
            );
            $nextHeight = min(
                $targetHeight,
                max($height + 1, (int) ceil($height * self::PROGRESSIVE_SCALE_FACTOR)),
            );

            if ($nextWidth === $width && $nextHeight === $height) {
                break;
            }

            $steps[] = [
                'width' => $nextWidth,
                'height' => $nextHeight,
            ];

            $width = $nextWidth;
            $height = $nextHeight;
        }

        if ($width !== $targetWidth || $height !== $targetHeight) {
            $steps[] = [
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        }

        return $steps;
    }

    /**
     * Thu nhỏ nhiều bước (~50% mỗi lần) khi giảm >2× — giữ nét tốt hơn một bước Lanczos.
     *
     * @return list<array{width: int, height: int}>
     */
    public static function progressiveDownscaleSteps(
        int $originalWidth,
        int $originalHeight,
        int $targetWidth,
        int $targetHeight,
    ): array {
        if (self::isUpscale($originalWidth, $originalHeight, $targetWidth, $targetHeight)) {
            return [
                [
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                ],
            ];
        }

        if ($targetWidth === $originalWidth && $targetHeight === $originalHeight) {
            return [
                [
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                ],
            ];
        }

        $widthRatio = $targetWidth / max(1, $originalWidth);
        $heightRatio = $targetHeight / max(1, $originalHeight);
        $needsProgressive = min($widthRatio, $heightRatio) < (1 / 2);

        if (! $needsProgressive) {
            return [
                [
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                ],
            ];
        }

        $steps = [];
        $width = max(1, $originalWidth);
        $height = max(1, $originalHeight);

        while ($width > $targetWidth || $height > $targetHeight) {
            $nextWidth = (int) max(
                $targetWidth,
                min($width - 1, (int) floor($width * self::PROGRESSIVE_DOWNSCALE_FACTOR)),
            );
            $nextHeight = (int) max(
                $targetHeight,
                min($height - 1, (int) floor($height * self::PROGRESSIVE_DOWNSCALE_FACTOR)),
            );

            if ($nextWidth === $width && $nextHeight === $height) {
                break;
            }

            $steps[] = [
                'width' => $nextWidth,
                'height' => $nextHeight,
            ];

            $width = $nextWidth;
            $height = $nextHeight;
        }

        if ($width !== $targetWidth || $height !== $targetHeight) {
            $steps[] = [
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        }

        return $steps;
    }

    /**
     * @return list<array{width: int, height: int}>
     */
    public static function progressiveScaleSteps(
        int $originalWidth,
        int $originalHeight,
        int $targetWidth,
        int $targetHeight,
    ): array {
        if (self::isUpscale($originalWidth, $originalHeight, $targetWidth, $targetHeight)) {
            return self::progressiveUpscaleSteps($originalWidth, $originalHeight, $targetWidth, $targetHeight);
        }

        return self::progressiveDownscaleSteps($originalWidth, $originalHeight, $targetWidth, $targetHeight);
    }
}
