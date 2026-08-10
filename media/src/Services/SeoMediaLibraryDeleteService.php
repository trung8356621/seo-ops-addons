<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

final class SeoMediaLibraryDeleteService
{
    public function __construct(
        private readonly WordPressMediaLibraryService $wordPressMediaLibrary,
    ) {}

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, message: string, scope?: string}
     */
    public function delete(Site $site, array $imageRow): array
    {
        $kind = strtolower(trim((string) ($imageRow['kind'] ?? '')));

        if ($kind === 'wordpress') {
            return $this->deleteWordPressRow($site, $imageRow);
        }

        $mediaId = (int) ($imageRow['seo_media_id'] ?? $imageRow['id'] ?? 0);
        if ($mediaId <= 0) {
            return [
                'success' => false,
                'message' => 'Không xác định được ảnh để xóa.',
            ];
        }

        $media = SeoMedia::query()
            ->where('site_id', $site->id)
            ->whereKey($mediaId)
            ->first();

        if ($media === null) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy ảnh nội bộ.',
            ];
        }

        return $this->deleteSeoMedia($site, $media);
    }

    /**
     * @param  array<string, mixed>  $imageRow
     * @return array{success: bool, message: string, scope?: string}
     */
    private function deleteWordPressRow(Site $site, array $imageRow): array
    {
        $attachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);
        if ($attachmentId <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu ID ảnh WordPress.',
            ];
        }

        $staging = SeoMedia::query()
            ->where('site_id', $site->id)
            ->where('wp_attachment_id', $attachmentId)
            ->orderByDesc('id')
            ->first();

        if ($staging === null) {
            return $this->wordPressMediaLibrary->deleteAttachment($site, $attachmentId);
        }

        $this->deleteSeoMedia($site, $staging);

        return [
            'success' => true,
            'message' => 'Đã xóa bản staging Laravel (ảnh trên WordPress vẫn giữ nguyên).',
            'scope' => 'staging',
        ];
    }

    /**
     * @return array{success: bool, message: string, scope: string}
     */
    private function deleteSeoMedia(Site $site, SeoMedia $media): array
    {
        $path = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        SeoMediaProcessingHistory::query()
            ->where('site_id', $site->id)
            ->where('source', SeoMediaProcessingHistory::SOURCE_LOCAL)
            ->where('media_ref_id', $media->id)
            ->delete();

        $media->delete();

        return [
            'success' => true,
            'message' => 'Đã xóa ảnh.',
            'scope' => 'local',
        ];
    }
}
