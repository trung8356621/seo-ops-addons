<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SeoMediaWpEditStagingService
{
    public const SOURCE_WP_EDIT_STAGING = 'wp_edit_staging';

    public function __construct(
        private readonly SeoMediaProcessingHistoryService $processingHistory,
        private readonly WordPressMediaWatermarkService $wpMedia,
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoWpMediaEditedPendingService $editedPending,
        private readonly SeoMediaPathAllocator $mediaPathAllocator,
    ) {}

    /**
     * Tải ảnh WordPress về Laravel để chỉnh sửa (tạo hoặc tái sử dụng bản staging).
     *
     * @param  array<string, mixed>  $imageRow
     */
    public function ensureStagingMedia(Site $site, array $imageRow): SeoMedia
    {
        $wpAttachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);
        $url = trim((string) ($imageRow['url'] ?? ''));

        if ($wpAttachmentId <= 0 || $url === '') {
            throw new \InvalidArgumentException('Thiếu ID hoặc URL ảnh WordPress.');
        }

        $fromPending = $this->editedPending->resolveStagingMediaFromPending((int) $site->id, $wpAttachmentId);
        if ($fromPending !== null) {
            return $fromPending;
        }

        $existing = SeoMedia::query()
            ->where('site_id', $site->id)
            ->where('wp_attachment_id', $wpAttachmentId)
            ->where('source', self::SOURCE_WP_EDIT_STAGING)
            ->first();

        if ($existing !== null && Storage::disk('public')->exists((string) $existing->path)) {
            return $existing;
        }

        $binary = $this->downloadBinary($url);
        $extension = $this->extensionFromUrl($url);
        $slug = Str::slug((string) ($imageRow['slug'] ?? 'wp-' . $wpAttachmentId));
        if ($slug === '') {
            $slug = 'wp-' . $wpAttachmentId;
        }

        $tempPath = $this->writeTempFile($binary, $extension);
        try {
            $this->processingHistory->ensureBackup(
                (int) $site->id,
                SeoMediaProcessingHistory::SOURCE_WORDPRESS,
                $wpAttachmentId,
                $url,
                $tempPath,
            );

            $allocated = $this->mediaPathAllocator->allocate(
                'wp-staging-' . $site->id . '-' . $wpAttachmentId,
                $extension,
                $existing?->path,
            );
            $relativePath = $allocated['relative_path'];
            $slug = $allocated['slug'];

            Storage::disk('public')->put($relativePath, $binary);

            if ($existing !== null) {
                $existing->update([
                    'filename' => basename($relativePath),
                    'slug' => $slug,
                    'path' => $relativePath,
                    'url' => $this->mediaStorage->urlForPath($relativePath),
                    'wp_synced_at' => null,
                ]);

                return $existing->fresh();
            }

            return SeoMedia::query()->create([
                'site_id' => $site->id,
                'article_id' => null,
                'wp_attachment_id' => $wpAttachmentId,
                'filename' => basename($relativePath),
                'slug' => $slug,
                'path' => $relativePath,
                'url' => $this->mediaStorage->urlForPath($relativePath),
                'source' => self::SOURCE_WP_EDIT_STAGING,
                'alt_text' => (string) ($imageRow['alt'] ?? ''),
                'wp_synced_at' => null,
            ]);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Đẩy bản staging đã chỉnh sửa lên WordPress (replace-binary).
     */
    public function syncStagingToWordPress(Site $site, SeoMedia $media): array
    {
        $wpAttachmentId = (int) ($media->wp_attachment_id ?? 0);
        if ($wpAttachmentId <= 0 || $media->source !== self::SOURCE_WP_EDIT_STAGING) {
            return [
                'success' => false,
                'url' => $media->publicUrl(),
                'message' => 'Ảnh này không liên kết WordPress để đồng bộ.',
            ];
        }

        $path = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return [
                'success' => false,
                'url' => $media->publicUrl(),
                'message' => 'Không tìm thấy file staging trên server.',
            ];
        }

        $absolute = Storage::disk('public')->path($path);
        $mime = $this->guessMimeFromPath($path);

        $outcome = $this->wpMedia->replaceAttachmentFromLocalFile($site, $wpAttachmentId, $absolute, $mime);

        if (! ($outcome['success'] ?? false)) {
            return $outcome;
        }

        $media->update(['wp_synced_at' => now()]);

        $this->editedPending->clearPendingEdit((int) $site->id, $wpAttachmentId);

        // Đồng bộ bản chỉnh sửa — không ghi nhận là đóng dấu.
        $this->processingHistory->markEditorSync(
            (int) $site->id,
            SeoMediaProcessingHistory::SOURCE_WORDPRESS,
            $wpAttachmentId,
        );

        return [
            'success' => true,
            'url' => $this->cacheBustUrl((string) ($outcome['url'] ?? $media->publicUrl())),
            'message' => (string) ($outcome['message'] ?? 'Đã đồng bộ ảnh lên WordPress.'),
        ];
    }

    /**
     * Sau khi khôi phục ảnh gốc trên WP — reset file staging từ backup Laravel.
     */
    public function resetStagingFromWordPressBackup(Site $site, int $wpAttachmentId): void
    {
        $media = SeoMedia::query()
            ->where('site_id', $site->id)
            ->where('wp_attachment_id', $wpAttachmentId)
            ->where('source', self::SOURCE_WP_EDIT_STAGING)
            ->first();

        if ($media === null) {
            return;
        }

        $history = $this->processingHistory->find(
            (int) $site->id,
            SeoMediaProcessingHistory::SOURCE_WORDPRESS,
            $wpAttachmentId,
        );

        $backupAbsolute = $this->processingHistory->backupAbsolutePath($history);
        if ($backupAbsolute === null || ! is_file($backupAbsolute)) {
            return;
        }

        $path = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($path !== '') {
            Storage::disk('public')->put($path, (string) file_get_contents($backupAbsolute));
        }

        $media->update(['wp_synced_at' => now()]);

        $this->editedPending->clearPendingEdit((int) $site->id, $wpAttachmentId);
    }

    public function canSyncToWordPress(?SeoMedia $media): bool
    {
        if ($media === null) {
            return false;
        }

        $siteId = (int) ($media->site_id ?? 0);
        $wpAttachmentId = (int) ($media->wp_attachment_id ?? 0);

        if ($siteId <= 0 || $wpAttachmentId <= 0) {
            return false;
        }

        return $this->editedPending->hasPendingEdit($siteId, $wpAttachmentId);
    }

    private function downloadBinary(string $url): string
    {
        $response = Http::timeout(90)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('Không tải được ảnh từ WordPress (HTTP ' . $response->status() . ').');
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new \RuntimeException('Ảnh WordPress trả về rỗng.');
        }

        return $binary;
    }

    private function writeTempFile(string $binary, string $extension): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'seo_wp_staging_');
        if ($tempPath === false) {
            throw new \RuntimeException('Không tạo được file tạm.');
        }

        $finalPath = $tempPath . '.' . $extension;
        rename($tempPath, $finalPath);
        file_put_contents($finalPath, $binary);

        return $finalPath;
    }

    private function extensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (! in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            $extension = 'jpg';
        }

        return $extension;
    }

    private function guessMimeFromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');

        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function cacheBustUrl(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'v=' . time();
    }
}
