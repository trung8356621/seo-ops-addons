<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Alignment;
use Intervention\Image\Laravel\Facades\Image;

final class SeoWatermarkDesignApplicator
{
    public const OVERLAY_EXPORT_MAX = SeoWatermarkOverlayRatioCatalog::EXPORT_MAX;

    public function __construct(
        private readonly SeoWatermarkOverlayStorage $overlayStorage,
    ) {}

    public function hasOverlay(SeoWatermarkSetting $setting): bool
    {
        return $this->overlayVariants($setting) !== [];
    }

    public function applyOverlayToAbsolutePath(string $absolutePath, SeoWatermarkSetting $setting): bool
    {
        if (! is_file($absolutePath)) {
            return false;
        }

        $image = Image::decodePath($absolutePath);
        $width = $image->width();
        $height = $image->height();
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $variants = $this->overlayVariants($setting);
        $resolved = SeoWatermarkOverlayRatioCatalog::resolveBestVariantKey($width, $height, $variants);
        if ($resolved === null) {
            return false;
        }

        $overlayRelative = ltrim(trim((string) ($resolved['meta']['path'] ?? '')), '/');
        if ($overlayRelative === '' || ! Storage::disk('public')->exists($overlayRelative)) {
            return false;
        }

        $overlayAbsolute = Storage::disk('public')->path($overlayRelative);
        if (! is_file($overlayAbsolute)) {
            return false;
        }

        $overlay = Image::decodePath($overlayAbsolute);
        $overlay->resize(width: $width, height: $height);
        $image->insert($overlay, 0, 0, Alignment::TOP_LEFT, 1.0);
        $image->save($absolutePath);

        return true;
    }

    /**
     * @param  array<string, UploadedFile>  $overlayFiles keyed by ratio key (16x9, 9x16, …)
     * @param  array<string, mixed>  $designConfig
     * @return array<string, mixed>
     */
    public function storeOverlayVariants(Site $site, array $overlayFiles, array $designConfig): array
    {
        $this->overlayStorage->clearLegacySiteIdDirectory((int) $site->id);

        $baseDir = $this->overlayStorage->directoryForSite($site);
        $this->overlayStorage->clearDirectory($baseDir);

        $variants = $this->overlayStorage->saveVariants($baseDir, $overlayFiles);

        $designConfig['overlay_variants'] = $variants;
        $designConfig['overlay_export_max'] = self::OVERLAY_EXPORT_MAX;
        $designConfig['overlay_storage_dir'] = $baseDir;

        $primary = $variants['16x9'] ?? reset($variants);
        if (is_array($primary)) {
            $designConfig['overlay_path'] = $primary['path'];
            $designConfig['overlay_ref_width'] = (int) $primary['width'];
            $designConfig['overlay_ref_height'] = (int) $primary['height'];
        }

        return $designConfig;
    }

    /**
     * @param  array<string, mixed>  $designConfig
     * @return array<string, mixed>
     */
    public function storeOverlayUpload(Site $site, UploadedFile $file, array $designConfig): array
    {
        return $this->storeOverlayVariants($site, ['16x9' => $file], $designConfig);
    }

    /**
     * @return array<string, array{path: string, width: int, height: int, ratio?: float}>
     */
    private function overlayVariants(SeoWatermarkSetting $setting): array
    {
        $design = is_array($setting->design_config) ? $setting->design_config : [];
        $variants = $design['overlay_variants'] ?? null;

        if (is_array($variants) && $variants !== []) {
            $normalized = [];
            foreach ($variants as $key => $meta) {
                if (! is_array($meta)) {
                    continue;
                }
                $path = ltrim(trim((string) ($meta['path'] ?? '')), '/');
                if ($path === '' || ! Storage::disk('public')->exists($path)) {
                    continue;
                }
                $normalized[(string) $key] = [
                    'path' => $path,
                    'width' => (int) ($meta['width'] ?? 1),
                    'height' => (int) ($meta['height'] ?? 1),
                    'ratio' => isset($meta['ratio']) ? (float) $meta['ratio'] : null,
                ];
            }

            if ($normalized !== []) {
                return $normalized;
            }
        }

        $legacy = ltrim(trim((string) ($design['overlay_path'] ?? '')), '/');
        if ($legacy !== '' && Storage::disk('public')->exists($legacy)) {
            return [
                'legacy' => [
                    'path' => $legacy,
                    'width' => (int) ($design['overlay_ref_width'] ?? self::OVERLAY_EXPORT_MAX),
                    'height' => (int) ($design['overlay_ref_height'] ?? 1125),
                ],
            ];
        }

        return [];
    }

}
