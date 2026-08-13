<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\WordPress\Services\WordPressMediaLibraryService;
use Omnichannel\Addons\WordPress\Support\WordPressImageUrl;
use Omnichannel\Addons\WordPress\Support\WordPressSiteUrlMatcher;
use App\Models\Site;

final class SeoMediaUrlImportResolverService
{
    public function __construct(
        private readonly WordPressSiteUrlMatcher $siteUrlMatcher,
        private readonly WordPressMediaLibraryService $wordPressMediaLibrary,
    ) {}

    /**
     * @return array{
     *     embedded: true,
     *     embed_mode: 'local'|'wordpress',
     *     url: string,
     *     slug: string,
     *     alt_text: string,
     *     id?: int,
     *     wp_attachment_id?: int,
     *     message: string,
     * }|null
     */
    public function resolveEmbeddedImport(?int $siteId, string $remoteUrl): ?array
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '') {
            return null;
        }

        if (WordPressImageUrl::isLocalSeoMediaSrc($remoteUrl)) {
            return $this->resolveLocalLibraryEmbed($siteId, $remoteUrl);
        }

        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return null;
        }

        return $this->resolveEmbeddedImportForSite($site, $remoteUrl);
    }

    /**
     * @return array{
     *     embedded: true,
     *     embed_mode: 'local'|'wordpress',
     *     url: string,
     *     slug: string,
     *     alt_text: string,
     *     id?: int,
     *     wp_attachment_id?: int,
     *     message: string,
     * }|null
     */
    public function resolveEmbeddedImportForSite(Site $site, string $remoteUrl): ?array
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '') {
            return null;
        }

        if (WordPressImageUrl::isLocalSeoMediaSrc($remoteUrl)) {
            return $this->resolveLocalLibraryEmbed((int) $site->id, $remoteUrl);
        }

        if (! $this->siteUrlMatcher->siteUrlMatchesSite($site, $remoteUrl)) {
            return null;
        }

        if (! $this->isEmbeddableWordPressUploadUrl($remoteUrl)) {
            return null;
        }

        return $this->resolveWordPressLibraryEmbed($site, $remoteUrl);
    }

    /**
     * @return array{
     *     embedded: true,
     *     embed_mode: 'local',
     *     url: string,
     *     slug: string,
     *     alt_text: string,
     *     id?: int,
     *     message: string,
     * }|null
     */
    private function resolveLocalLibraryEmbed(?int $siteId, string $remoteUrl): ?array
    {
        $media = $this->findLocalMediaByUrl($siteId, $remoteUrl);
        if (! $media instanceof SeoMedia) {
            return null;
        }

        return [
            'embedded' => true,
            'embed_mode' => 'local',
            'id' => (int) $media->id,
            'url' => $media->publicUrl(),
            'slug' => (string) $media->slug,
            'alt_text' => (string) ($media->alt_text ?? ''),
            'message' => 'Đã nhúng ảnh từ thư viện nội bộ.',
        ];
    }

    /**
     * @return array{
     *     embedded: true,
     *     embed_mode: 'wordpress',
     *     url: string,
     *     slug: string,
     *     alt_text: string,
     *     id?: int,
     *     wp_attachment_id?: int,
     *     message: string,
     * }
     */
    private function resolveWordPressLibraryEmbed(Site $site, string $remoteUrl): array
    {
        $fullUrl = WordPressImageUrl::toFullSize($remoteUrl);
        $slug = WordPressImageUrl::slugFromUrl($fullUrl);
        $attachment = $this->wordPressMediaLibrary->fetchAttachmentBySourceUrl($site, $fullUrl);

        $wpAttachmentId = (int) ($attachment['wp_attachment_id'] ?? $attachment['id'] ?? 0);
        $embedUrl = $fullUrl;
        $altText = '';

        if (is_array($attachment)) {
            $candidateUrl = trim((string) ($attachment['url'] ?? ''));
            if ($candidateUrl !== '') {
                $embedUrl = WordPressImageUrl::toFullSize($candidateUrl);
            }

            $altText = trim((string) ($attachment['alt'] ?? ''));
            $attachmentSlug = trim((string) ($attachment['slug'] ?? ''));
            if ($attachmentSlug !== '') {
                $slug = $attachmentSlug;
            }
        }

        $seoMediaId = 0;
        if ($wpAttachmentId > 0) {
            $local = SeoMedia::query()
                ->where('site_id', (int) $site->id)
                ->where('wp_attachment_id', $wpAttachmentId)
                ->orderByDesc('id')
                ->first();

            if ($local instanceof SeoMedia) {
                $seoMediaId = (int) $local->id;
            }
        }

        $payload = [
            'embedded' => true,
            'embed_mode' => 'wordpress',
            'url' => $embedUrl,
            'slug' => $slug,
            'alt_text' => $altText,
            'message' => 'Đã nhúng ảnh từ thư viện WordPress (không tải lại).',
        ];

        if ($wpAttachmentId > 0) {
            $payload['wp_attachment_id'] = $wpAttachmentId;
        }

        if ($seoMediaId > 0) {
            $payload['id'] = $seoMediaId;
        }

        return $payload;
    }

    private function findLocalMediaByUrl(?int $siteId, string $url): ?SeoMedia
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $relativePath = str_starts_with($path, '/storage/')
            ? ltrim(substr($path, strlen('/storage/')), '/')
            : '';

        $query = SeoMedia::query()
            ->where(function ($builder) use ($url, $path, $relativePath): void {
                $builder->where('url', $url);

                if ($path !== '') {
                    $builder->orWhere('url', $path);
                }

                if ($relativePath !== '') {
                    $builder->orWhere('path', $relativePath);
                }
            });

        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        return $query->orderByDesc('id')->first();
    }

    private function isEmbeddableWordPressUploadUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path !== ''
            && str_contains($path, '/wp-content/uploads/')
            && preg_match('/\.(jpe?g|png|gif|webp)(\?.*)?$/i', $path) === 1;
    }
}
