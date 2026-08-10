<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

final class SeoWatermarkOverlayRatioCatalog
{
    public const EXPORT_MAX = 2000;

    /** @var list<array{key: string, label: string, rw: int, rh: int}> */
    public const PRESETS = [
        ['key' => '16x9', 'label' => '16:9 — Desktop / hero', 'rw' => 16, 'rh' => 9],
        ['key' => '4x3', 'label' => '4:3 — Ảnh / tablet ngang', 'rw' => 4, 'rh' => 3],
        ['key' => '3x2', 'label' => '3:2 — Máy ảnh', 'rw' => 3, 'rh' => 2],
        ['key' => '1x1', 'label' => '1:1 — Vuông', 'rw' => 1, 'rh' => 1],
        ['key' => '9x16', 'label' => '9:16 — Mobile / Story', 'rw' => 9, 'rh' => 16],
        ['key' => '3x4', 'label' => '3:4 — Portrait nhỏ', 'rw' => 3, 'rh' => 4],
        ['key' => '2x3', 'label' => '2:3 — Portrait', 'rw' => 2, 'rh' => 3],
        ['key' => '21x9', 'label' => '21:9 — Ultrawide', 'rw' => 21, 'rh' => 9],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::PRESETS, 'key');
    }

    /**
     * @return array{width: int, height: int, ratio: float}
     */
    public static function exportDimensionsForRatio(int $rw, int $rh): array
    {
        $max = self::EXPORT_MAX;

        if ($rw >= $rh) {
            $width = $max;
            $height = max(1, (int) round($max * $rh / $rw));
        } else {
            $height = $max;
            $width = max(1, (int) round($max * $rw / $rh));
        }

        return [
            'width' => $width,
            'height' => $height,
            'ratio' => $rw / max(1, $rh),
        ];
    }

    /**
     * @return array{width: int, height: int, ratio: float}|null
     */
    public static function exportDimensionsForKey(string $key): ?array
    {
        foreach (self::PRESETS as $preset) {
            if ($preset['key'] === $key) {
                return self::exportDimensionsForRatio($preset['rw'], $preset['rh']);
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{path: string, width: int, height: int, ratio?: float}>  $variants
     * @return array{key: string, meta: array{path: string, width: int, height: int, ratio?: float}}|null
     */
    public static function resolveBestVariantKey(int $targetWidth, int $targetHeight, array $variants): ?array
    {
        if ($variants === []) {
            return null;
        }

        $targetRatio = $targetWidth / max(1, $targetHeight);
        $bestKey = null;
        $bestMeta = null;
        $bestDiff = PHP_FLOAT_MAX;

        foreach ($variants as $key => $meta) {
            $path = ltrim(trim((string) ($meta['path'] ?? '')), '/');
            if ($path === '') {
                continue;
            }

            $w = max(1, (int) ($meta['width'] ?? 1));
            $h = max(1, (int) ($meta['height'] ?? 1));
            $ratio = (float) ($meta['ratio'] ?? ($w / $h));
            $diff = abs(log($targetRatio) - log(max(0.01, $ratio)));

            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestKey = $key;
                $bestMeta = $meta;
            }
        }

        if ($bestKey === null || $bestMeta === null) {
            return null;
        }

        return ['key' => $bestKey, 'meta' => $bestMeta];
    }
}
