<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoWpMediaEditedPending;
use Illuminate\Support\Facades\Storage;

final class SeoWpMediaEditedPendingService
{
    /**
     * Ghi nhận ảnh WP đã chỉnh sửa (chưa đồng bộ lên WordPress).
     */
    public function recordPendingEdit(SeoMedia $media, ?string $originalWpUrl = null): SeoWpMediaEditedPending
    {
        $wpAttachmentId = (int) ($media->wp_attachment_id ?? 0);
        $siteId = (int) ($media->site_id ?? 0);

        if ($wpAttachmentId <= 0 || $siteId <= 0) {
            throw new \InvalidArgumentException('Ảnh không liên kết WordPress để lưu bản chỉnh sửa.');
        }

        $path = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($path === '') {
            throw new \InvalidArgumentException('Ảnh không có đường dẫn lưu trữ.');
        }

        return SeoWpMediaEditedPending::query()->updateOrCreate(
            [
                'site_id' => $siteId,
                'wp_attachment_id' => $wpAttachmentId,
            ],
            [
                'seo_media_id' => (int) $media->id,
                'path' => $path,
                'original_wp_url' => $originalWpUrl !== null && $originalWpUrl !== ''
                    ? mb_substr($originalWpUrl, 0, 2048)
                    : null,
                'edited_at' => now(),
            ],
        );
    }

    public function findForAttachment(int $siteId, int $wpAttachmentId): ?SeoWpMediaEditedPending
    {
        if ($siteId <= 0 || $wpAttachmentId <= 0) {
            return null;
        }

        return SeoWpMediaEditedPending::query()
            ->where('site_id', $siteId)
            ->where('wp_attachment_id', $wpAttachmentId)
            ->first();
    }

    public function hasPendingEdit(int $siteId, int $wpAttachmentId): bool
    {
        $pending = $this->findForAttachment($siteId, $wpAttachmentId);
        if ($pending === null) {
            return false;
        }

        return $this->pendingFileExists($pending);
    }

    public function resolveStagingMediaFromPending(int $siteId, int $wpAttachmentId): ?SeoMedia
    {
        $pending = $this->findForAttachment($siteId, $wpAttachmentId);
        if ($pending === null || ! $this->pendingFileExists($pending)) {
            return null;
        }

        $media = SeoMedia::query()->find((int) $pending->seo_media_id);

        return $media instanceof SeoMedia ? $media : null;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    public function applyPendingToImageRow(int $siteId, array $image): array
    {
        $wpId = (int) ($image['wp_attachment_id'] ?? $image['id'] ?? 0);
        if ($siteId <= 0 || $wpId <= 0) {
            return $image;
        }

        $pending = $this->findForAttachment($siteId, $wpId);
        if ($pending === null) {
            return $image;
        }

        $media = $this->resolveMediaForPending($pending);
        if ($media === null) {
            return $image;
        }

        $cacheBust = $pending->edited_at?->timestamp ?? time();
        $image['url'] = $media->publicUrl() . '?t=' . $cacheBust;
        $image['seo_media_id'] = (int) $media->id;
        $image['has_pending_wp_edit'] = true;

        return $image;
    }

    /**
     * Gắn URL/file Laravel cho ảnh WP có bản chỉnh sửa chưa đồng bộ.
     *
     * @param  list<array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    public function applyPendingEditsToWordPressImages(int $siteId, array $images): array
    {
        if ($siteId <= 0 || $images === []) {
            return $images;
        }

        foreach ($images as $index => $image) {
            $images[$index] = $this->applyPendingToImageRow($siteId, $image);
        }

        return $images;
    }

    /**
     * Xóa bản ghi sau khi đồng bộ hoặc khôi phục gốc trên WordPress.
     */
    public function clearPendingEdit(int $siteId, int $wpAttachmentId): void
    {
        if ($siteId <= 0 || $wpAttachmentId <= 0) {
            return;
        }

        SeoWpMediaEditedPending::query()
            ->where('site_id', $siteId)
            ->where('wp_attachment_id', $wpAttachmentId)
            ->delete();
    }

    public function canSyncPending(int $siteId, int $wpAttachmentId): bool
    {
        return $this->hasPendingEdit($siteId, $wpAttachmentId);
    }

    private function resolveMediaForPending(SeoWpMediaEditedPending $pending): ?SeoMedia
    {
        if (! $this->pendingFileExists($pending)) {
            return null;
        }

        $media = $pending->relationLoaded('seoMedia')
            ? $pending->seoMedia
            : SeoMedia::query()->find((int) $pending->seo_media_id);

        return $media instanceof SeoMedia ? $media : null;
    }

    private function pendingFileExists(SeoWpMediaEditedPending $pending): bool
    {
        $path = ltrim(str_replace('\\', '/', (string) $pending->path), '/');
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return true;
        }

        $media = SeoMedia::query()->find((int) $pending->seo_media_id);
        if ($media === null) {
            return false;
        }

        $mediaPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');

        return $mediaPath !== '' && Storage::disk('public')->exists($mediaPath);
    }
}
