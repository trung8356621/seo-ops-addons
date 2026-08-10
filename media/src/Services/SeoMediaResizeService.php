<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use Omnichannel\Addons\Media\Support\SeoImagePipeline;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class SeoMediaResizeService
{
    public function __construct(
        private readonly SeoMediaProcessingHistoryService $processingHistory,
        private readonly WordPressMediaWatermarkService $wpMedia,
        private readonly SeoImageOptimizationService $optimization,
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoImagePipeline $imagePipeline,
    ) {}

    /**
     * @return array{success: bool, url: string, message: string}
     */
    public function resizeLocal(SeoMedia $media, ?int $width, ?int $height): array
    {
        $url = $media->publicUrl();

        if (! $this->hasValidDimensionInput($width, $height)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Nhập Width hoặc Height (px).',
            ];
        }

        $disk = Storage::disk('public');
        $relativePath = ltrim(str_replace('\\', '/', (string) $media->path), '/');

        if ($relativePath === '' || ! $disk->exists($relativePath)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không tìm thấy file ảnh trên server.',
            ];
        }

        $absolutePath = $disk->path($relativePath);
        $siteId = (int) ($media->site_id ?? 0);
        $mediaId = (int) $media->id;

        $this->processingHistory->ensureBackup(
            $siteId,
            SeoMediaProcessingHistory::SOURCE_LOCAL,
            $mediaId,
            $url,
            $absolutePath,
        );

        $resized = $this->resizeAbsolutePath($absolutePath, $width, $height);
        if (! $resized['applied']) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không resize được ảnh.',
            ];
        }

        $newRelative = $this->optimization->absoluteToPublicRelative($resized['absolute_path']);
        $media->update([
            'path' => $newRelative,
            'url' => $this->mediaStorage->urlForPath($newRelative),
            'filename' => basename($newRelative),
        ]);

        if ((int) ($media->wp_attachment_id ?? 0) > 0 && $siteId > 0) {
            app(SeoWpMediaEditedPendingService::class)->recordPendingEdit($media->fresh());
            $media->update(['wp_synced_at' => null]);
        }

        return [
            'success' => true,
            'url' => $this->cacheBustUrl($media->fresh()->publicUrl()),
            'message' => sprintf(
                'Đã resize %dx%d px.',
                $resized['width'],
                $resized['height'],
            ),
        ];
    }

    /**
     * Resize ảnh trong bộ nhớ — dùng trước khi lưu file để tránh nén kép.
     *
     * @return array{
     *     success: bool,
     *     binary: string,
     *     width: int,
     *     height: int,
     *     extension: string,
     *     message: string,
     * }
     */
    public function resizeBinary(string $binary, ?int $width, ?int $height, string $extension = 'jpg'): array
    {
        if ($binary === '') {
            return $this->failedBinaryResize($extension, 'Ảnh rỗng.');
        }

        if (! $this->hasValidDimensionInput($width, $height)) {
            return $this->failedBinaryResize($extension, 'Nhập Width hoặc Height (px).');
        }

        $extension = $this->normalizeExtension($extension);
        $tempPath = $this->createTempImagePath('memory-resize', $extension);

        try {
            file_put_contents($tempPath, $binary);

            $resized = $this->resizeAbsolutePath($tempPath, $width, $height);
            if (! $resized['applied']) {
                return $this->failedBinaryResize($extension, 'Không resize được ảnh.');
            }

            $outputBinary = file_get_contents($resized['absolute_path']);
            if (! is_string($outputBinary) || $outputBinary === '') {
                return $this->failedBinaryResize($extension, 'Không đọc được ảnh sau resize.');
            }

            return [
                'success' => true,
                'binary' => $outputBinary,
                'width' => $resized['width'],
                'height' => $resized['height'],
                'extension' => $extension,
                'message' => sprintf('Đã resize %dx%d px.', $resized['width'], $resized['height']),
            ];
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, url: string, message: string}
     */
    public function resizeWordPress(Site $site, array $imageRow, ?int $width, ?int $height): array
    {
        $url = trim((string) ($imageRow['url'] ?? ''));
        $attachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);

        if (! $this->hasValidDimensionInput($width, $height)) {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Nhập Width hoặc Height (px).',
            ];
        }

        if ($attachmentId <= 0 || $url === '') {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Thiếu ID ảnh WordPress.',
            ];
        }

        $tempPath = $this->createTempImagePath('wp-resize', $this->extensionFromUrl($url));
        if ($tempPath === '') {
            return [
                'success' => false,
                'url' => $url,
                'message' => 'Không tạo được file tạm trên server.',
            ];
        }

        $workingPath = $tempPath;

        try {
            $response = Http::timeout(90)->get($url);
            if (! $response->successful()) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không tải được ảnh từ WordPress (HTTP '.$response->status().').',
                ];
            }

            $binary = $response->body();
            if ($binary === '') {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Ảnh WordPress trả về rỗng.',
                ];
            }

            file_put_contents($tempPath, $binary);

            $this->processingHistory->ensureBackup(
                (int) $site->id,
                SeoMediaProcessingHistory::SOURCE_WORDPRESS,
                $attachmentId,
                $url,
                $tempPath,
            );

            $resized = $this->resizeAbsolutePath($tempPath, $width, $height);
            if (! $resized['applied']) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => 'Không resize được ảnh.',
                ];
            }

            $workingPath = $resized['absolute_path'];

            $mime = $this->optimization->mimeFromPath($workingPath);
            $outcome = $this->wpMedia->replaceAttachmentFromLocalFile(
                $site,
                $attachmentId,
                $workingPath,
                $mime,
            );

            if (! ($outcome['success'] ?? false)) {
                return [
                    'success' => false,
                    'url' => $url,
                    'message' => (string) ($outcome['message'] ?? 'Không cập nhật được ảnh trên WordPress.'),
                ];
            }

            $newUrl = trim((string) ($outcome['url'] ?? ''));
            if ($newUrl === '') {
                $newUrl = $url;
            }

            return [
                'success' => true,
                'url' => $this->cacheBustUrl($newUrl),
                'message' => sprintf(
                    'Đã resize %dx%d px trên WordPress.',
                    $resized['width'],
                    $resized['height'],
                ),
            ];
        } finally {
            if ($workingPath !== '' && is_file($workingPath)) {
                @unlink($workingPath);
            }
        }
    }

    private function hasValidDimensionInput(?int $width, ?int $height): bool
    {
        return ($width !== null && $width > 0) || ($height !== null && $height > 0);
    }

    /**
     * @return array{applied: bool, absolute_path: string, width: int, height: int}
     */
    private function resizeAbsolutePath(string $absolutePath, ?int $targetWidth, ?int $targetHeight): array
    {
        if (! is_file($absolutePath)) {
            return $this->failedAbsoluteResize($absolutePath);
        }

        $width = $targetWidth !== null && $targetWidth > 0 ? $targetWidth : null;
        $height = $targetHeight !== null && $targetHeight > 0 ? $targetHeight : null;

        if ($width === null && $height === null) {
            return $this->failedAbsoluteResize($absolutePath);
        }

        $result = $this->imagePipeline->resizeFile($absolutePath, $width, $height);
        if (! $result['applied']) {
            return $this->failedAbsoluteResize($absolutePath);
        }

        return [
            'applied' => true,
            'absolute_path' => $absolutePath,
            'width' => $result['width'],
            'height' => $result['height'],
        ];
    }

    private function createTempImagePath(string $prefix, string $extension): string
    {
        $extension = $this->normalizeExtension($extension);
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $extension = 'jpg';
        }

        $dir = storage_path('app/temp/wp-media-resize');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$prefix.'-'.uniqid('', true).'.'.$extension;
    }

    private function extensionFromUrl(string $url): string
    {
        return $this->normalizeExtension(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg');
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower($extension);
        if ($extension === 'jpeg') {
            return 'jpg';
        }

        return $extension;
    }

    /**
     * @return array{
     *     success: bool,
     *     binary: string,
     *     width: int,
     *     height: int,
     *     extension: string,
     *     message: string,
     * }
     */
    private function failedBinaryResize(string $extension, string $message): array
    {
        return [
            'success' => false,
            'binary' => '',
            'width' => 0,
            'height' => 0,
            'extension' => $this->normalizeExtension($extension),
            'message' => $message,
        ];
    }

    /**
     * @return array{applied: bool, absolute_path: string, width: int, height: int}
     */
    private function failedAbsoluteResize(string $absolutePath): array
    {
        return [
            'applied' => false,
            'absolute_path' => $absolutePath,
            'width' => 0,
            'height' => 0,
        ];
    }

    private function cacheBustUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'t='.time();
    }
}
