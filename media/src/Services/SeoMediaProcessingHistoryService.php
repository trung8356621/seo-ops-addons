<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use Omnichannel\Addons\Media\Models\SeoWpMediaBackup;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

final class SeoMediaProcessingHistoryService
{
    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{source: string, media_ref_id: int}|null
     */
    public function resolveMediaRef(array $imageRow): ?array
    {
        $kind = strtolower(trim((string) ($imageRow['kind'] ?? '')));

        if ($kind === 'wordpress' || ($kind === '' && (int) ($imageRow['wp_attachment_id'] ?? 0) > 0)) {
            $id = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);

            return $id > 0
                ? ['source' => SeoMediaProcessingHistory::SOURCE_WORDPRESS, 'media_ref_id' => $id]
                : null;
        }

        if (in_array($kind, ['local', 'generated'], true) || (int) ($imageRow['seo_media_id'] ?? 0) > 0) {
            $id = (int) ($imageRow['seo_media_id'] ?? $imageRow['id'] ?? 0);

            return $id > 0
                ? ['source' => SeoMediaProcessingHistory::SOURCE_LOCAL, 'media_ref_id' => $id]
                : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $imageRow
     */
    public function findForImage(Site $site, array $imageRow): ?SeoMediaProcessingHistory
    {
        $ref = $this->resolveMediaRef($imageRow);
        if ($ref === null) {
            return null;
        }

        return $this->find((int) $site->id, $ref['source'], $ref['media_ref_id']);
    }

    public function find(int $siteId, string $source, int $mediaRefId): ?SeoMediaProcessingHistory
    {
        return SeoMediaProcessingHistory::query()
            ->where('site_id', $siteId)
            ->where('source', $source)
            ->where('media_ref_id', $mediaRefId)
            ->first();
    }

    public function ensureBackup(
        int $siteId,
        string $source,
        int $mediaRefId,
        string $originalUrl,
        string $sourceAbsolutePath,
    ): SeoMediaProcessingHistory {
        $history = SeoMediaProcessingHistory::query()->firstOrNew([
            'site_id' => $siteId,
            'source' => $source,
            'media_ref_id' => $mediaRefId,
        ]);

        if ($history->backupExists()) {
            return $history;
        }

        $extension = strtolower(pathinfo(parse_url($originalUrl, PHP_URL_PATH) ?: $sourceAbsolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (! in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            $extension = 'jpg';
        }

        $folder = $source === SeoMediaProcessingHistory::SOURCE_WORDPRESS
            ? 'uploads/wp-media-backups'
            : 'uploads/seo-media-backups';

        $relative = sprintf('%s/%d/%d-original.%s', $folder, $siteId, $mediaRefId, $extension);

        Storage::disk('public')->put($relative, (string) file_get_contents($sourceAbsolutePath));

        $mime = $this->guessMime($extension);

        $history->fill([
            'backup_path' => $relative,
            'original_url' => mb_substr($originalUrl, 0, 2048),
            'mime_type' => $mime,
        ]);
        $history->save();

        if ($source === SeoMediaProcessingHistory::SOURCE_WORDPRESS) {
            SeoWpMediaBackup::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'wp_attachment_id' => $mediaRefId,
                ],
                [
                    'backup_path' => $relative,
                    'original_url' => mb_substr($originalUrl, 0, 2048),
                    'mime_type' => $mime,
                ],
            );
        }

        return $history;
    }

    public function markWatermarked(int $siteId, string $source, int $mediaRefId): void
    {
        $history = SeoMediaProcessingHistory::query()->firstOrNew([
            'site_id' => $siteId,
            'source' => $source,
            'media_ref_id' => $mediaRefId,
        ]);

        $history->fill([
            'is_watermarked' => true,
            'watermarked_at' => now(),
            'restored_at' => null,
        ]);
        $history->save();
    }

    /**
     * Ghi nhận đồng bộ bản chỉnh sửa lên WordPress (không phải đóng dấu).
     */
    public function markEditorSync(int $siteId, string $source, int $mediaRefId): void
    {
        $history = SeoMediaProcessingHistory::query()->firstOrNew([
            'site_id' => $siteId,
            'source' => $source,
            'media_ref_id' => $mediaRefId,
        ]);

        $history->fill([
            'is_watermarked' => false,
            'watermarked_at' => null,
            'restored_at' => null,
        ]);
        $history->save();
    }

    public function markOptimized(int $siteId, string $source, int $mediaRefId): void
    {
        $history = SeoMediaProcessingHistory::query()->firstOrNew([
            'site_id' => $siteId,
            'source' => $source,
            'media_ref_id' => $mediaRefId,
        ]);

        $history->fill([
            'is_optimized' => true,
            'optimized_at' => now(),
            'restored_at' => null,
        ]);
        $history->save();
    }

    public function markRestored(int $siteId, string $source, int $mediaRefId): void
    {
        $history = $this->find($siteId, $source, $mediaRefId);
        if ($history === null) {
            return;
        }

        $history->update(['restored_at' => now()]);
    }

    public function canRestore(?SeoMediaProcessingHistory $history): bool
    {
        return $history !== null
            && $history->isCurrentlyModified()
            && $history->backupExists();
    }

    public function canOptimize(?SeoMediaProcessingHistory $history, string $url): bool
    {
        if ($this->isWebpUrl($url)) {
            return false;
        }

        if ($history === null) {
            return false;
        }

        if ($history->isCurrentlyModified() && $history->is_optimized) {
            return false;
        }

        if (! $history->is_watermarked && ! $history->is_optimized) {
            return false;
        }

        return true;
    }

    public function backupAbsolutePath(?SeoMediaProcessingHistory $history): ?string
    {
        if ($history === null || ! $history->backupExists()) {
            return null;
        }

        return Storage::disk('public')->path(ltrim((string) $history->backup_path, '/'));
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{can_restore: bool, can_optimize: bool, status: string}
     */
    public function previewState(Site $site, array $imageRow): array
    {
        $url = (string) ($imageRow['url'] ?? '');
        $history = $this->findForImage($site, $imageRow);
        $wpAttachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);

        $hasPendingEdit = ! empty($imageRow['has_pending_wp_edit']);
        if (! $hasPendingEdit && $wpAttachmentId > 0) {
            $hasPendingEdit = app(SeoWpMediaEditedPendingService::class)
                ->hasPendingEdit((int) $site->id, $wpAttachmentId);
        }

        if ($hasPendingEdit) {
            return [
                'can_restore' => $this->canRestore($history),
                'can_optimize' => $this->canOptimize($history, $url),
                'status' => 'edited_pending',
            ];
        }

        $canRestore = $this->canRestore($history);
        $canOptimize = $this->canOptimize($history, $url);

        $status = 'original';
        if ($history !== null && $history->isCurrentlyModified()) {
            if ($history->is_optimized) {
                $status = 'optimized';
            } elseif ($history->is_watermarked && ! $this->hasMisleadingWatermarkFlag($site, $wpAttachmentId)) {
                $status = 'watermarked';
            }
        } elseif ($history !== null && ($history->is_watermarked || $history->is_optimized)) {
            $status = 'restored';
        }

        return [
            'can_restore' => $canRestore,
            'can_optimize' => $canOptimize,
            'status' => $status,
        ];
    }

    /**
     * Cờ is_watermarked nhầm khi đồng bộ chỉnh sửa (legacy) — ảnh có bản staging editor.
     */
    private function hasMisleadingWatermarkFlag(Site $site, int $wpAttachmentId): bool
    {
        if ($wpAttachmentId <= 0) {
            return false;
        }

        return SeoMedia::query()
            ->where('site_id', (int) $site->id)
            ->where('wp_attachment_id', $wpAttachmentId)
            ->where('source', SeoMediaWpEditStagingService::SOURCE_WP_EDIT_STAGING)
            ->exists();
    }

    public function localBackupRelativePath(SeoMedia $media, ?string $extension = null): string
    {
        if ($media->site_id === null || $media->id === null) {
            return '';
        }

        if ($extension === null) {
            $current = strtolower(pathinfo((string) $media->path, PATHINFO_EXTENSION) ?: 'jpg');
            $extension = $current === 'jpeg' ? 'jpg' : ($current !== '' ? $current : 'jpg');
        }

        return sprintf(
            'uploads/seo-media-backups/%d/%d-original.%s',
            (int) $media->site_id,
            (int) $media->id,
            $extension,
        );
    }

    private function guessMime(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function isWebpUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return str_ends_with(strtolower($path), '.webp');
    }
}
