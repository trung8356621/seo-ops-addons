<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\Site;

final class SeoMediaImageEditorResolverService
{
    public function __construct(
        private readonly SeoMediaWpEditStagingService $staging,
        private readonly SeoWpMediaEditedPendingService $editedPending,
    ) {}

    /**
     * Chuẩn bị seo_media_id và URL trình chỉnh sửa (tương tự MediaLibrary::openImageEditor).
     *
     * @param  array<string, mixed>  $imageRow
     * @return array{seo_media_id: int, editor_url: string}
     */
    public function resolve(Site $site, array $imageRow): array
    {
        $kind = strtolower(trim((string) ($imageRow['kind'] ?? '')));
        $imageId = (int) ($imageRow['seo_media_id'] ?? 0);
        $wpAttachmentId = (int) ($imageRow['wp_attachment_id'] ?? $imageRow['id'] ?? 0);

        if ($kind === 'generated') {
            throw new \InvalidArgumentException('Ảnh Gen AI không hỗ trợ chỉnh sửa tại đây.');
        }

        if ($kind === 'wordpress' || ($imageId <= 0 && $wpAttachmentId > 0)) {
            $pendingMedia = $this->editedPending->resolveStagingMediaFromPending((int) $site->id, $wpAttachmentId);
            $media = $pendingMedia ?? $this->staging->ensureStagingMedia($site, $imageRow);
            $imageId = (int) $media->id;
        } elseif ($imageId <= 0) {
            $imageId = $this->resolveLocalMediaIdFromUrl((int) $site->id, (string) ($imageRow['url'] ?? ''));
        }

        if ($imageId > 0) {
            $media = SeoMedia::query()->whereKey($imageId)->first();

            if ($media === null) {
                throw new \InvalidArgumentException('Không tìm thấy ảnh trên Laravel.');
            }

            $mediaSiteId = (int) ($media->site_id ?? 0);
            if ($mediaSiteId > 0 && $mediaSiteId !== (int) $site->id) {
                throw new \InvalidArgumentException('Ảnh không thuộc domain đã chọn.');
            }

            if ($mediaSiteId <= 0) {
                $media->update(['site_id' => (int) $site->id]);
            }
        } else {
            throw new \InvalidArgumentException('Ảnh chưa có bản lưu trên Laravel.');
        }

        return [
            'seo_media_id' => $imageId,
            'editor_url' => self::editorUrl($imageId),
        ];
    }

    public static function editorUrl(int $mediaId, ?string $tab = null): string
    {
        $params = ['media' => $mediaId];
        if (filled($tab)) {
            $params['tab'] = $tab;
        }

        return route(
            'filament.seo.pages.media-image-editor',
            SeoConnectionContext::mergePanelRouteParameters($params),
        );
    }

    private function resolveLocalMediaIdFromUrl(int $siteId, string $url): int
    {
        $url = trim($url);
        if ($url === '') {
            return 0;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! str_contains($path, '/storage/uploads/seo_media/')) {
            return 0;
        }

        $relative = ltrim(str_replace('/storage/', '', $path), '/');
        if ($relative === '') {
            return 0;
        }

        $media = SeoMedia::query()
            ->where('site_id', $siteId)
            ->where('path', $relative)
            ->first();

        return $media !== null ? (int) $media->id : 0;
    }
}
