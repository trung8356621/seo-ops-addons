<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SeoWatermarkOverlayStorage
{
    public function directoryForSite(Site $site): string
    {
        $domain = trim((string) $site->domain);
        $slug = Str::slug(str_replace(['.', ':', '/'], '-', $domain));

        if ($slug === '') {
            $slug = 'site-' . $site->id;
        }

        return 'uploads/watermarks/overlays/' . $slug;
    }

    /**
     * Xóa toàn bộ overlay cũ của domain (tránh file rác random).
     */
    public function clearDirectory(string $relativeDir): void
    {
        $dir = ltrim($relativeDir, '/');
        if ($dir === '' || ! Storage::disk('public')->exists($dir)) {
            return;
        }

        Storage::disk('public')->deleteDirectory($dir);
    }

    /**
     * @param  array<string, UploadedFile>  $overlayFiles
     * @return array<string, array{path: string, width: int, height: int, ratio: float}>
     */
    public function saveVariants(string $relativeDir, array $overlayFiles): array
    {
        $baseDir = ltrim($relativeDir, '/');
        Storage::disk('public')->makeDirectory($baseDir);

        $variants = [];

        foreach ($overlayFiles as $key => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $key)) ?: 'custom';
            $dims = SeoWatermarkOverlayRatioCatalog::exportDimensionsForKey($key)
                ?? ['width' => 2000, 'height' => 1500, 'ratio' => 4 / 3];

            $relative = $baseDir . '/' . $key . '.png';
            Storage::disk('public')->put($relative, (string) file_get_contents($file->getRealPath()));

            $variants[$key] = [
                'path' => $relative,
                'width' => $dims['width'],
                'height' => $dims['height'],
                'ratio' => $dims['ratio'],
            ];
        }

        return $variants;
    }

    /**
     * Dọn thư mục overlay theo site_id cũ (numeric).
     */
    public function clearLegacySiteIdDirectory(int $siteId): void
    {
        $legacy = 'uploads/watermarks/overlays/' . $siteId;
        if (Storage::disk('public')->exists($legacy)) {
            Storage::disk('public')->deleteDirectory($legacy);
        }
    }

    /**
     * Danh sách overlay đã lưu (URL public) cho tab xem trước editor.
     *
     * @param  array<string, mixed>  $designConfig
     * @return list<array{key: string, label: string, url: string, width: int, height: int}>
     */
    public function variantsForEditor(array $designConfig): array
    {
        $variants = $designConfig['overlay_variants'] ?? [];
        if (! is_array($variants) || $variants === []) {
            return [];
        }

        $out = [];

        foreach (SeoWatermarkOverlayRatioCatalog::PRESETS as $preset) {
            $key = (string) $preset['key'];
            $meta = $variants[$key] ?? null;
            if (! is_array($meta)) {
                continue;
            }

            $path = ltrim(trim((string) ($meta['path'] ?? '')), '/');
            if ($path === '' || ! Storage::disk('public')->exists($path)) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => (string) $preset['label'],
                'url' => '/storage/' . $path,
                'width' => (int) ($meta['width'] ?? 0),
                'height' => (int) ($meta['height'] ?? 0),
            ];
        }

        return $out;
    }
}
