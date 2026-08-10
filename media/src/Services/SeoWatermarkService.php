<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use Omnichannel\Addons\Media\Models\SeoWatermarkSetting;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Alignment;
use Intervention\Image\Laravel\Facades\Image;
use Intervention\Image\Typography\FontFactory;

class SeoWatermarkService
{
    public function __construct(
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoImageOptimizationService $optimization,
        private readonly SeoWatermarkDesignApplicator $designApplicator,
        private readonly SeoMediaProcessingHistoryService $processingHistory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(?SeoWatermarkSetting $setting): array
    {
        if ($setting === null) {
            return array_merge((new SeoWatermarkSetting())->defaultDesignConfig(), [
                'site_id' => null,
                'type' => SeoWatermarkSetting::TYPE_NONE,
                'auto_watermark' => false,
                'text_content' => 'Bản quyền hình ảnh',
                'text_color' => '#ffffff',
                'text_size' => 20,
                'logo_path' => null,
                'logo_url' => null,
                'logo_width_pct' => 20,
                'position' => 'bottom-right',
                'opacity' => 0.7,
            ]);
        }

        return $setting->toEditorPayload();
    }

    /**
     * @param  array<string, UploadedFile>  $overlayVariants
     */
    public function saveSettings(
        int $siteId,
        array $data,
        ?UploadedFile $logoFile = null,
        ?UploadedFile $overlayFile = null,
        array $overlayVariants = [],
    ): SeoWatermarkSetting {
        $setting = SeoWatermarkSetting::query()->firstOrNew(['site_id' => $siteId]);

        $designConfig = $this->parseDesignConfig($data['design_config'] ?? null);
        if ($designConfig === [] && is_array($setting->design_config)) {
            $designConfig = $setting->design_config;
        }

        $site = Site::query()->find($siteId);

        if ($site instanceof Site && $overlayVariants !== []) {
            $designConfig = $this->designApplicator->storeOverlayVariants($site, $overlayVariants, $designConfig);
        } elseif ($site instanceof Site && $overlayFile !== null) {
            $designConfig = $this->designApplicator->storeOverlayUpload($site, $overlayFile, $designConfig);
        }

        if ($designConfig !== []) {
            $data = array_merge($data, $this->mapDesignConfigToColumns($designConfig));
        }

        $hasNewDesign = $designConfig !== [] || $overlayVariants !== [] || $overlayFile !== null;
        if (array_key_exists('auto_watermark', $data)) {
            $autoWatermark = (bool) $data['auto_watermark'];
        } elseif ($hasNewDesign) {
            $autoWatermark = true;
        } else {
            $autoWatermark = (bool) $setting->auto_watermark;
        }

        $setting->fill([
            'type' => (string) ($data['type'] ?? SeoWatermarkSetting::TYPE_NONE),
            'auto_watermark' => $autoWatermark,
            'text_content' => $data['text_content'] ?? $data['text'] ?? null,
            'text_color' => (string) ($data['text_color'] ?? $data['textColor'] ?? '#ffffff'),
            'text_size' => (int) ($data['text_size'] ?? $data['textSize'] ?? 20),
            'logo_width_pct' => (int) ($data['logo_width_pct'] ?? $data['logoScale'] ?? 20),
            'position' => $this->normalizePosition((string) ($data['position'] ?? $data['presetPos'] ?? 'bottom-right')),
            'opacity' => (float) ($data['opacity'] ?? 0.7),
            'design_config' => $designConfig !== [] ? $designConfig : $setting->design_config,
        ]);

        if ($logoFile !== null) {
            if (filled($setting->logo_path)) {
                Storage::disk('public')->delete((string) $setting->logo_path);
            }

            $path = $logoFile->store('uploads/watermarks', 'public');
            $setting->logo_path = $path;
        }

        $setting->save();

        return $setting->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDesignConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    private function mapDesignConfigToColumns(array $design): array
    {
        $watermarkType = (string) ($design['watermarkType'] ?? 'text');
        $preset = (string) ($design['presetPos'] ?? 'bottom-right');
        $preset = str_replace(['middle-left', 'middle-right'], ['center-left', 'center-right'], $preset);

        return [
            'type' => $watermarkType === 'image' ? SeoWatermarkSetting::TYPE_IMAGE : SeoWatermarkSetting::TYPE_TEXT,
            'text_content' => $design['text'] ?? null,
            'text_color' => $design['textColor'] ?? null,
            'text_size' => $design['textSize'] ?? null,
            'logo_width_pct' => $design['logoScale'] ?? null,
            'position' => $preset,
            'opacity' => $design['opacity'] ?? null,
        ];
    }

    public function applyToPhysicalFile(string $relativePath, SeoWatermarkSetting $setting): bool
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($relativePath)) {
            return false;
        }

        return $this->applyToAbsolutePath($disk->path($relativePath), $setting);
    }

    public function applyToAbsolutePath(string $absolutePath, SeoWatermarkSetting $setting): bool
    {
        if (! is_file($absolutePath) || ! $setting->isConfiguredForApply()) {
            return false;
        }

        if ($this->designApplicator->hasOverlay($setting)) {
            return $this->designApplicator->applyOverlayToAbsolutePath($absolutePath, $setting);
        }

        $image = Image::decodePath($absolutePath);
        $padding = 20;
        $alignment = $this->toAlignment($setting->position);
        $transparency = max(0.1, min(1.0, (float) $setting->opacity));

        if ($setting->type === SeoWatermarkSetting::TYPE_TEXT) {
            $text = trim((string) ($setting->text_content ?? ''));
            if ($text === '') {
                $text = 'Copyright';
            }

            [$x, $y] = $this->textCoordinates(
                $image->width(),
                $image->height(),
                $text,
                (int) $setting->text_size,
                $setting->position,
                $padding,
            );

            $image->text($text, $x, $y, function (FontFactory $font) use ($setting): void {
                $fontPath = $this->resolveFontPath();
                if ($fontPath !== null) {
                    $font->filename($fontPath);
                }
                $font->size((int) $setting->text_size);
                $font->color((string) $setting->text_color);
                $font->align('left', 'top');
            });
        } elseif ($setting->type === SeoWatermarkSetting::TYPE_IMAGE && filled($setting->logo_path)) {
            $logoPath = Storage::disk('public')->path((string) $setting->logo_path);
            if (! is_file($logoPath)) {
                return false;
            }

            $watermark = Image::decodePath($logoPath);
            $targetWidth = (int) max(1, round($image->width() * ((int) $setting->logo_width_pct / 100)));
            $watermark->scaleDown(width: $targetWidth);

            $image->insert(
                $watermark,
                $padding,
                $padding,
                $alignment,
                $transparency,
            );
        } else {
            return false;
        }

        $image->save($absolutePath);

        return true;
    }

    public function saveWatermarkedUpload(
        SeoMedia $media,
        UploadedFile $file,
        string $mode = 'overwrite',
    ): SeoMedia {
        if ($mode === 'new') {
            return $this->mediaStorage->storeUpload(
                $file,
                $media->site_id !== null ? (int) $media->site_id : null,
                $media->firstArticleId(),
                'watermark',
            );
        }

        $disk = Storage::disk('public');
        $path = (string) $media->path;
        if (! $disk->exists($path)) {
            throw new \RuntimeException('File ảnh không tồn tại trên đĩa.');
        }

        $disk->put($path, (string) file_get_contents($file->getRealPath()));

        $media->update([
            'url' => $this->mediaStorage->urlForPath($path),
        ]);

        return $media->fresh();
    }

    public function applyBatchToSite(int $siteId): int
    {
        $result = $this->applyBatchAllForSite($siteId, true);

        return (int) ($result['local_watermark'] ?? 0) + (int) ($result['local_optimize'] ?? 0);
    }

    /**
     * Tối ưu + (tuỳ chọn) đóng dấu toàn bộ ảnh nội bộ và WordPress Media.
     *
     * @return array{
     *     local_watermark: int,
     *     local_optimize: int,
     *     local_skipped: int,
     *     wp_watermark: int,
     *     wp_optimize: int,
     *     wp_skipped: int,
     *     wp_errors: int,
     *     message: string
     * }
     */
    public function applyBatchAllForSite(int $siteId, bool $applyWatermark = true): array
    {
        $optimizationConfig = $this->optimization->resolveForSite($siteId);

        $watermarkSetting = null;
        if ($applyWatermark) {
            $watermarkSetting = SeoWatermarkSetting::query()->where('site_id', $siteId)->first();
            if ($watermarkSetting === null || ! $watermarkSetting->isConfiguredForApply()) {
                return [
                    'local_watermark' => 0,
                    'local_optimize' => 0,
                    'local_skipped' => 0,
                    'wp_watermark' => 0,
                    'wp_optimize' => 0,
                    'wp_skipped' => 0,
                    'wp_errors' => 0,
                    'message' => 'Chưa cấu hình đóng dấu. Lưu tại SEO → Thư viện media → Thiết kế đóng dấu (overlay hoặc text/logo) hoặc bỏ chọn Watermark để chỉ tối ưu.',
                ];
            }
        }

        $stats = [
            'local_watermark' => 0,
            'local_optimize' => 0,
            'local_skipped' => 0,
            'wp_watermark' => 0,
            'wp_optimize' => 0,
            'wp_skipped' => 0,
            'wp_errors' => 0,
            'message' => '',
        ];

        SeoMedia::query()
            ->where('site_id', $siteId)
            ->orderBy('id')
            ->chunkById(50, function ($medias) use ($watermarkSetting, $applyWatermark, $optimizationConfig, &$stats): void {
                foreach ($medias as $media) {
                    $outcome = $this->processLocalMediaFile(
                        $media,
                        $watermarkSetting,
                        $applyWatermark,
                        true,
                        $optimizationConfig,
                    );
                    if ($outcome['watermark']) {
                        $stats['local_watermark']++;
                    }
                    if ($outcome['optimize']) {
                        $stats['local_optimize']++;
                    }
                    if (! $outcome['watermark'] && ! $outcome['optimize']) {
                        $stats['local_skipped']++;
                    }
                }
            });

        $site = \App\Models\Site::query()->find($siteId);
        if (! $site instanceof \App\Models\Site) {
            $stats['message'] = 'Không tìm thấy domain.';

            return $stats;
        }

        $wpResult = app(WordPressMediaWatermarkService::class)->applyBatchToWordPress(
            $site,
            $watermarkSetting,
            $applyWatermark,
            $optimizationConfig,
        );

        $stats['wp_watermark'] = (int) ($wpResult['wp_watermark'] ?? 0);
        $stats['wp_optimize'] = (int) ($wpResult['wp_optimize'] ?? 0);
        $stats['wp_skipped'] = (int) ($wpResult['wp_skipped'] ?? 0);
        $stats['wp_errors'] = (int) ($wpResult['wp_errors'] ?? 0);
        if (filled($wpResult['message'] ?? null)) {
            $stats['message'] = (string) $wpResult['message'];
        }

        return $stats;
    }

    /**
     * @return array{watermark: bool, optimize: bool}
     */
    public function processLocalMediaFile(
        SeoMedia $media,
        ?SeoWatermarkSetting $watermarkSetting,
        bool $applyWatermark,
        bool $applyOptimize,
        \Omnichannel\Addons\Media\Models\SeoImageOptimizationSetting $optimizationConfig,
    ): array {
        $disk = Storage::disk('public');
        $relativePath = (string) $media->path;

        if ($relativePath === '' || ! $disk->exists($relativePath)) {
            return ['watermark' => false, 'optimize' => false];
        }

        $absolutePath = $disk->path($relativePath);
        $didWatermark = false;
        $didOptimize = false;

        if (! $applyWatermark && ! $applyOptimize) {
            return ['watermark' => false, 'optimize' => false];
        }

        if (! $applyWatermark && $applyOptimize && $this->optimization->isWebpPath($absolutePath)) {
            return ['watermark' => false, 'optimize' => false];
        }

        $siteId = (int) ($media->site_id ?? 0);
        $mediaId = (int) $media->id;

        if ($applyWatermark || $applyOptimize) {
            $this->processingHistory->ensureBackup(
                $siteId,
                SeoMediaProcessingHistory::SOURCE_LOCAL,
                $mediaId,
                $media->publicUrl(),
                $absolutePath,
            );
        }

        if ($applyWatermark && $watermarkSetting instanceof SeoWatermarkSetting) {
            if ($this->applyToAbsolutePath($absolutePath, $watermarkSetting)) {
                $didWatermark = true;
                $this->processingHistory->markWatermarked(
                    $siteId,
                    SeoMediaProcessingHistory::SOURCE_LOCAL,
                    $mediaId,
                );
            }
        }

        if ($applyOptimize && ! $this->optimization->isWebpPath($absolutePath)) {
            $optimized = $this->optimization->optimizeAbsolutePath($absolutePath, $optimizationConfig);
            if ($optimized['applied']) {
                $didOptimize = true;
                $newRelative = $this->optimization->absoluteToPublicRelative($optimized['absolute_path']);
                $media->update([
                    'path' => $newRelative,
                    'url' => $this->mediaStorage->urlForPath($newRelative),
                    'filename' => basename($newRelative),
                ]);
                $this->processingHistory->markOptimized(
                    $siteId,
                    SeoMediaProcessingHistory::SOURCE_LOCAL,
                    $mediaId,
                );
            }
        } elseif ($didWatermark) {
            $media->update([
                'url' => $this->mediaStorage->urlForPath($relativePath),
            ]);
        }

        return ['watermark' => $didWatermark, 'optimize' => $didOptimize];
    }

    public function applyToMediaIfEnabled(SeoMedia $media): bool
    {
        if ($media->site_id === null) {
            return false;
        }

        $setting = SeoWatermarkSetting::query()
            ->where('site_id', $media->site_id)
            ->first();

        if (
            $setting === null
            || ! $setting->auto_watermark
            || ! $setting->isConfiguredForApply()
            || ! filled($media->path)
        ) {
            return false;
        }

        $applied = $this->applyToPhysicalFile((string) $media->path, $setting);

        if ($applied) {
            $media->update([
                'url' => $this->mediaStorage->urlForPath((string) $media->path),
            ]);
        }

        return $applied;
    }

    public function normalizePosition(string $position): string
    {
        $position = Str::lower(trim($position));

        return in_array($position, SeoWatermarkSetting::POSITIONS, true)
            ? $position
            : 'bottom-right';
    }

    private function toAlignment(string $position): Alignment
    {
        return Alignment::tryFrom($this->normalizePosition($position)) ?? Alignment::BOTTOM_RIGHT;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function textCoordinates(
        int $width,
        int $height,
        string $text,
        int $fontSize,
        string $position,
        int $padding,
    ): array {
        $textWidth = (int) (strlen($text) * $fontSize * 0.55);
        $textHeight = $fontSize + 8;

        return match ($this->normalizePosition($position)) {
            'top-left' => [$padding, $padding],
            'top-center' => [(int) max($padding, ($width - $textWidth) / 2), $padding],
            'top-right' => [(int) max($padding, $width - $textWidth - $padding), $padding],
            'center-left' => [$padding, (int) max($padding, ($height - $textHeight) / 2)],
            'center' => [(int) max($padding, ($width - $textWidth) / 2), (int) max($padding, ($height - $textHeight) / 2)],
            'center-right' => [(int) max($padding, $width - $textWidth - $padding), (int) max($padding, ($height - $textHeight) / 2)],
            'bottom-left' => [$padding, (int) max($padding, $height - $textHeight - $padding)],
            'bottom-center' => [(int) max($padding, ($width - $textWidth) / 2), (int) max($padding, $height - $textHeight - $padding)],
            default => [(int) max($padding, $width - $textWidth - $padding), (int) max($padding, $height - $textHeight - $padding)],
        };
    }

    public function restoreLocalMedia(SeoMedia $media): bool
    {
        if ($media->site_id === null) {
            return false;
        }

        $history = $this->processingHistory->find(
            (int) $media->site_id,
            SeoMediaProcessingHistory::SOURCE_LOCAL,
            (int) $media->id,
        );

        if (! $this->processingHistory->canRestore($history)) {
            return false;
        }

        $backupAbsolute = $this->processingHistory->backupAbsolutePath($history);
        if ($backupAbsolute === null) {
            return false;
        }

        $disk = Storage::disk('public');
        $relative = (string) $media->path;
        if ($relative === '') {
            return false;
        }

        $disk->put($relative, (string) file_get_contents($backupAbsolute));
        $media->update([
            'url' => $this->mediaStorage->urlForPath($relative),
        ]);

        $this->processingHistory->markRestored(
            (int) $media->site_id,
            SeoMediaProcessingHistory::SOURCE_LOCAL,
            (int) $media->id,
        );

        return true;
    }

    public function localMediaHasBackup(SeoMedia $media): bool
    {
        if ($media->site_id === null) {
            return false;
        }

        return $this->processingHistory->canRestore(
            $this->processingHistory->find(
                (int) $media->site_id,
                SeoMediaProcessingHistory::SOURCE_LOCAL,
                (int) $media->id,
            ),
        );
    }

    private function resolveFontPath(): ?string
    {
        $candidates = [
            resource_path('fonts/DejaVuSans-Bold.ttf'),
            resource_path('fonts/DejaVuSans.ttf'),
            public_path('fonts/Roboto-Bold.ttf'),
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
