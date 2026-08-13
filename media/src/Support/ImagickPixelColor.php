<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support;

/**
 * ImagickPixel::getColor() đổi chữ ký theo bản extension:
 * - Imagick ≥3.7 (PHP 8): getColor(int $normalized = 0)
 * - Bản cũ hơn: getColor(bool $normalized = false)
 * Truyền bool true vào stub mới → TypeError → pipeline Imagick chết, fallback chậm.
 */
final class ImagickPixelColor
{
    /**
     * @return array{r: float|int, g: float|int, b: float|int, a?: float|int}
     */
    public static function normalized(\ImagickPixel $pixel): array
    {
        try {
            /** @var array{r: float|int, g: float|int, b: float|int, a?: float|int} $color */
            $color = $pixel->getColor(1);

            return $color;
        } catch (\TypeError) {
            /** @var array{r: float|int, g: float|int, b: float|int, a?: float|int} $color */
            $color = $pixel->getColor(true);

            return $color;
        }
    }
}
