<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

/**
 * Lightweight dedupe for AI children + original fallback.
 * URL/id exact + optional average-hash (not perfect, enough for Mode 1).
 */
final class ProductGalleryImageDeduper
{
    /**
     * @param  list<array{id?: int, url?: string, path?: string|null}>  $items
     * @return list<array{id: int, url: string}>
     */
    public function dedupe(array $items): array
    {
        $out = [];
        $seenIds = [];
        $seenUrls = [];
        $seenHashes = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '' && $id <= 0) {
                continue;
            }

            $normalizedUrl = $this->normalizeUrl($url);
            if ($id > 0 && isset($seenIds[$id])) {
                continue;
            }
            if ($normalizedUrl !== '' && isset($seenUrls[$normalizedUrl])) {
                continue;
            }

            $path = isset($item['path']) ? trim((string) $item['path']) : '';
            $hash = $path !== '' && is_file($path) ? $this->averageHash($path) : null;
            if ($hash !== null && isset($seenHashes[$hash])) {
                continue;
            }

            if ($id > 0) {
                $seenIds[$id] = true;
            }
            if ($normalizedUrl !== '') {
                $seenUrls[$normalizedUrl] = true;
            }
            if ($hash !== null) {
                $seenHashes[$hash] = true;
            }

            $out[] = [
                'id' => max(0, $id),
                'url' => $url,
            ];
        }

        return $out;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return strtolower($url);
        }

        $path = (string) ($parts['path'] ?? '');
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host.$path;
    }

    private function averageHash(string $absolutePath): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return hash_file('sha1', $absolutePath) ?: null;
        }

        $binary = @file_get_contents($absolutePath);
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return hash('sha1', $binary);
        }

        $tiny = imagecreatetruecolor(8, 8);
        if ($tiny === false) {
            imagedestroy($image);

            return hash('sha1', $binary);
        }

        imagecopyresampled($tiny, $image, 0, 0, 0, 0, 8, 8, imagesx($image), imagesy($image));
        imagedestroy($image);

        $sum = 0.0;
        $pixels = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($tiny, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $brightness = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
                $pixels[] = $brightness;
                $sum += $brightness;
            }
        }
        imagedestroy($tiny);

        $avg = $sum / 64.0;
        $bits = '';
        foreach ($pixels as $brightness) {
            $bits .= $brightness >= $avg ? '1' : '0';
        }

        return $bits;
    }
}
