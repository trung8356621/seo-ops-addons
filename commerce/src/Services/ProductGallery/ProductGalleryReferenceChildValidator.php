<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic Mode 2 child validation — no vision identity scoring.
 */
final class ProductGalleryReferenceChildValidator
{
    public function __construct(
        private readonly int $minPx = 64,
    ) {}

    /**
     * @param  list<SeoMedia>  $alreadyAccepted
     * @return array{ok: bool, reason: string, errors: list<string>}
     */
    public function validate(SeoMedia $child, array $alreadyAccepted = []): array
    {
        $path = $this->absolutePath($child);
        if ($path === null) {
            return ['ok' => false, 'reason' => 'missing_file', 'errors' => ['file_unreadable']];
        }

        $acceptedPaths = [];
        foreach ($alreadyAccepted as $existing) {
            $existingPath = $this->absolutePath($existing);
            if ($existingPath !== null) {
                $acceptedPaths[] = $existingPath;
            }
        }

        return $this->validateLocalFile($path, $acceptedPaths);
    }

    /**
     * Path-based checks for unit tests / callers without Storage bootstrap.
     *
     * @param  list<string>  $alreadyAcceptedPaths
     * @return array{ok: bool, reason: string, errors: list<string>}
     */
    public function validateLocalFile(string $path, array $alreadyAcceptedPaths = []): array
    {
        $errors = [];

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return ['ok' => false, 'reason' => 'missing_file', 'errors' => ['file_unreadable']];
        }

        $size = @getimagesize($path);
        if (! is_array($size)) {
            return ['ok' => false, 'reason' => 'not_image', 'errors' => ['not_an_image']];
        }

        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);
        if ($width < $this->minPx || $height < $this->minPx) {
            $errors[] = 'too_small';
        }

        $aspect = $height > 0 ? ($width / $height) : 0.0;
        if ($aspect < 0.45 || $aspect > 2.2) {
            $errors[] = 'invalid_aspect';
        }

        if ($this->isNearlyBlank($path, $width, $height)) {
            $errors[] = 'blank';
        }

        $hash = hash_file('sha1', $path) ?: null;
        if ($hash !== null) {
            foreach ($alreadyAcceptedPaths as $existingPath) {
                if (! is_string($existingPath) || $existingPath === '' || ! is_file($existingPath)) {
                    continue;
                }
                if ($existingPath === $path || (hash_file('sha1', $existingPath) ?: null) === $hash) {
                    $errors[] = 'duplicate_of_existing_child';
                    break;
                }
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'reason' => $errors[0],
                'errors' => array_values(array_unique($errors)),
            ];
        }

        return ['ok' => true, 'reason' => 'ok', 'errors' => []];
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
        $stepX = max(1, intdiv(max(1, $width), 24));
        $stepY = max(1, intdiv(max(1, $height), 24));
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
                if (((0.299 * $r) + (0.587 * $g) + (0.114 * $b)) >= 248) {
                    $white++;
                }
            }
        }
        imagedestroy($image);

        return $total > 0 && ($white / $total) >= 0.92;
    }
}
