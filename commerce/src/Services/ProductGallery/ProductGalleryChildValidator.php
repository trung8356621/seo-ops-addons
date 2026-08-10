<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryQuality;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySelectionResult;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGallerySource;
use Omnichannel\Addons\Media\Support\ProductGallery\SpriteValidationResult;
use Illuminate\Support\Facades\Storage;

/**
 * Post-split child validation — deterministic, no AI.
 */
final class ProductGalleryChildValidator
{
    public function __construct(
        private readonly ProductGalleryImageDeduper $deduper,
    ) {}

    /**
     * @param  list<SeoMedia>  $children
     * @return array{
     *     usable_children: list<SeoMedia>,
     *     rejected_children: list<SeoMedia>,
     *     rejected_reasons: array<int, string>
     * }
     */
    public function validateChildren(array $children, int $minPx = 64): array
    {
        $usable = [];
        $rejected = [];
        $reasons = [];
        $hashSeen = [];

        foreach ($children as $child) {
            if (! $child instanceof SeoMedia) {
                continue;
            }

            $id = (int) $child->id;
            $path = $this->absolutePath($child);
            if ($path === null) {
                $rejected[] = $child;
                $reasons[$id] = 'missing_file';

                continue;
            }

            $size = @getimagesize($path);
            if (! is_array($size) || (int) ($size[0] ?? 0) < $minPx || (int) ($size[1] ?? 0) < $minPx) {
                $rejected[] = $child;
                $reasons[$id] = 'too_small';

                continue;
            }

            $width = (int) $size[0];
            $height = (int) $size[1];
            $aspect = $height > 0 ? ($width / $height) : 0.0;
            if ($aspect < 0.45 || $aspect > 2.2) {
                $rejected[] = $child;
                $reasons[$id] = 'invalid_aspect';

                continue;
            }

            $area = $width * $height;
            if ($area < ($minPx * $minPx)) {
                $rejected[] = $child;
                $reasons[$id] = 'fragment';

                continue;
            }

            if ($this->isNearlyBlank($path, $width, $height)) {
                $rejected[] = $child;
                $reasons[$id] = 'blank';

                continue;
            }

            $hash = $this->fileAverageHash($path);
            if ($hash !== null && isset($hashSeen[$hash])) {
                $rejected[] = $child;
                $reasons[$id] = 'duplicate';

                continue;
            }
            if ($hash !== null) {
                $hashSeen[$hash] = true;
            }

            $usable[] = $child;
        }

        return [
            'usable_children' => $usable,
            'rejected_children' => $rejected,
            'rejected_reasons' => $reasons,
        ];
    }

    private function absolutePath(SeoMedia $media): ?string
    {
        $relative = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($relative === '') {
            return null;
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($relative)) {
            return null;
        }

        return $disk->path($relative);
    }

    private function isNearlyBlank(string $path, int $width, int $height): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }
        $binary = @file_get_contents($path);
        if (! is_string($binary) || $binary === '') {
            return true;
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return true;
        }

        $white = 0;
        $total = 0;
        $stepX = max(1, intdiv($width, 24));
        $stepY = max(1, intdiv($height, 24));
        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $total++;
                $rgb = @imagecolorat($image, $x, $y);
                if (! is_int($rgb)) {
                    continue;
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $brightness = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
                if ($brightness >= 248) {
                    $white++;
                }
            }
        }
        imagedestroy($image);

        return $total > 0 && ($white / $total) >= 0.92;
    }

    private function fileAverageHash(string $path): ?string
    {
        $items = $this->deduper->dedupe([['id' => 1, 'url' => 'file://'.$path, 'path' => $path]]);

        // Deduper collapses by hash internally; use sha1 of tiny sample as proxy.
        return is_file($path) ? (hash_file('sha1', $path) ?: null) : null;
    }
}
