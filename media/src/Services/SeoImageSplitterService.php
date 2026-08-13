<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\WordPress\Services\WordPressMediaLibraryService;

final class SeoImageSplitterService
{
    public function __construct(
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoMediaWpEditStagingService $wpStaging,
        private readonly WordPressMediaLibraryService $wpMediaLibrary,
    ) {}

    /**
     * @return array{
     *     url: string,
     *     name: string,
     *     seo_media_id: int,
     *     wp_attachment_id: int,
     *     site_id: int|null
     * }
     */
    public function resolveSource(
        ?int $siteId,
        ?int $seoMediaId,
        ?int $wpAttachmentId,
        ?string $slug,
    ): array {
        if ($seoMediaId !== null && $seoMediaId > 0) {
            $media = SeoMedia::query()->find($seoMediaId);
            if ($media === null) {
                throw new \InvalidArgumentException('Không tìm thấy ảnh Laravel (seo_media_id).');
            }

            if ($siteId !== null && $siteId > 0 && (int) ($media->site_id ?? 0) > 0 && (int) $media->site_id !== $siteId) {
                throw new \InvalidArgumentException('Ảnh không thuộc domain đã chọn.');
            }

            return $this->mapResolvedMedia($media);
        }

        if ($wpAttachmentId !== null && $wpAttachmentId > 0) {
            if ($siteId === null || $siteId <= 0) {
                throw new \InvalidArgumentException('Thiếu site_id khi mở ảnh WordPress.');
            }

            $site = Site::query()->findOrFail($siteId);

            $existing = SeoMedia::query()
                ->where('site_id', $site->id)
                ->where('wp_attachment_id', $wpAttachmentId)
                ->orderByDesc('id')
                ->first();

            if ($existing !== null && Storage::disk('public')->exists((string) $existing->path)) {
                return $this->mapResolvedMedia($existing);
            }

            $wpRow = $this->wpMediaLibrary->fetchAttachmentById($site, $wpAttachmentId);
            if ($wpRow === null || trim((string) ($wpRow['url'] ?? '')) === '') {
                throw new \InvalidArgumentException('Không tải được ảnh WordPress #'.$wpAttachmentId.'.');
            }

            $media = $this->wpStaging->ensureStagingMedia($site, [
                'wp_attachment_id' => $wpAttachmentId,
                'url' => (string) $wpRow['url'],
                'slug' => trim((string) ($slug ?: ($wpRow['slug'] ?? ''))),
                'kind' => 'wordpress',
            ]);

            return $this->mapResolvedMedia($media);
        }

        throw new \InvalidArgumentException('Thiếu seo_media_id hoặc wp_attachment_id.');
    }

    /**
     * @param  list<UploadedFile>  $pieceFiles
     * @return array{success: bool, saved: list<array{id: int, url: string, slug: string}>, deleted_original: bool, message: string}
     */
    public function savePiecesAndDeleteOriginal(
        Site $site,
        array $pieceFiles,
        ?int $articleId,
        ?int $originalSeoMediaId,
        bool $deleteOriginal = true,
    ): array {
        if ($pieceFiles === []) {
            throw new \InvalidArgumentException('Chưa có ảnh con để lưu.');
        }

        $saved = [];

        foreach ($pieceFiles as $file) {
            $media = $this->mediaStorage->storeUpload(
                $file,
                (int) $site->id,
                $articleId,
                'image_split',
            );

            $saved[] = [
                'id' => (int) $media->id,
                'url' => $media->publicUrl(),
                'slug' => (string) $media->slug,
            ];
        }

        $deleted = $deleteOriginal
            ? $this->deleteOriginalMedia($site, $originalSeoMediaId)
            : false;

        return [
            'success' => true,
            'saved' => $saved,
            'deleted_original' => $deleted,
            'message' => sprintf(
                'Đã lưu %d ảnh vào thư viện%s.',
                count($saved),
                $deleted ? ' và xóa ảnh gốc' : '',
            ),
        ];
    }

    /**
     * @return array{url: string, name: string, seo_media_id: int, wp_attachment_id: int, site_id: int|null, article_id: int|null}
     */
    private function mapResolvedMedia(SeoMedia $media): array
    {
        $articleId = $media->firstArticleId();

        return [
            'url' => $this->normalizePublicUrl($media->publicUrl()),
            'name' => (string) ($media->slug ?: pathinfo((string) $media->filename, PATHINFO_FILENAME) ?: 'image'),
            'seo_media_id' => (int) $media->id,
            'wp_attachment_id' => (int) ($media->wp_attachment_id ?? 0),
            'site_id' => (int) ($media->site_id ?? 0) > 0 ? (int) $media->site_id : null,
            'article_id' => $articleId,
        ];
    }

    private function deleteOriginalMedia(Site $site, ?int $originalSeoMediaId): bool
    {
        if ($originalSeoMediaId === null || $originalSeoMediaId <= 0) {
            return false;
        }

        $media = SeoMedia::query()->find($originalSeoMediaId);
        if ($media === null) {
            return false;
        }

        if ((int) ($media->site_id ?? 0) > 0 && (int) $media->site_id !== (int) $site->id) {
            return false;
        }

        $path = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $media->delete();

        return true;
    }

    private function normalizePublicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/storage/')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && str_starts_with($path, '/storage/')) {
            return $path;
        }

        return $url;
    }
}
