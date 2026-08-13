<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleMediaService;
use Omnichannel\Addons\WordPress\Services\WordPressLocalMediaSyncService;
use Illuminate\Support\Facades\Storage;

final class ArticleMediaLocalService
{
    public const META_FEATURED_URL = 'wp_featured_image_url';

    public const META_FEATURED_ATTACHMENT_ID = 'wp_featured_attachment_id';

    public const META_PRODUCT_GALLERY = 'wp_product_gallery';

    public const META_PRODUCT_GALLERY_IDS = 'wp_product_gallery_attachment_ids';

    public const META_MEDIA_PENDING_SYNC = 'wp_media_pending_sync';

    public const META_FEATURED_CLEAR_PENDING = 'wp_featured_clear_pending';

    public function applyFeaturedLocal(SeoArticle $article, int $attachmentId, string $url): void
    {
        $url = trim($url);
        if ($attachmentId <= 0 || $url === '') {
            return;
        }

        $article->loadMissing('articleMetas');
        $existingUrl = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_FEATURED_URL)?->meta_value ?? ''));
        $existingId = (int) ($article->articleMetas
            ->firstWhere('meta_key', self::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);

        if ($existingUrl === $url && $existingId === $attachmentId) {
            $this->markMediaPendingSync($article);
            app(ArticleFeaturedImageProjection::class)->syncAvailable(
                $article,
                $url,
                $attachmentId,
                \Omnichannel\Addons\Content\Support\ArticleFeaturedImageSource::EDITOR_LOCAL,
            );

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_FEATURED_URL],
            ['meta_value' => $url],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_FEATURED_ATTACHMENT_ID],
            ['meta_value' => (string) $attachmentId],
        );
        $article->articleMetas()->where('meta_key', self::META_FEATURED_CLEAR_PENDING)->delete();
        $this->markMediaPendingSync($article);
        $article->unsetRelation('articleMetas');
        app(ArticleFeaturedImageProjection::class)->rebuildAndPersist($article);
    }

    public function clearFeaturedLocal(SeoArticle $article): void
    {
        $article->articleMetas()->whereIn('meta_key', [
            self::META_FEATURED_URL,
            self::META_FEATURED_ATTACHMENT_ID,
        ])->delete();
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_FEATURED_CLEAR_PENDING],
            ['meta_value' => '1'],
        );
        $article->unsetRelation('articleMetas');
        $this->markMediaPendingSync($article);
        app(ArticleFeaturedImageProjection::class)->clear($article);
    }

    /**
     * @return list<array{id: int, url: string, source?: string, asset_key?: string}>
     */
    public function appendGalleryLocal(SeoArticle $article, int $attachmentId, string $url): array
    {
        $url = trim($url);
        if ($attachmentId <= 0 || $url === '') {
            return $this->resolveGallery($article);
        }

        $gallery = $this->resolveGallery($article);
        foreach ($gallery as $item) {
            if ((int) ($item['id'] ?? 0) === $attachmentId) {
                return $gallery;
            }
        }

        $gallery[] = [
            'id' => $attachmentId,
            'url' => $url,
        ];

        $ids = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $gallery);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PRODUCT_GALLERY],
            ['meta_value' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PRODUCT_GALLERY_IDS],
            ['meta_value' => json_encode($ids, JSON_UNESCAPED_UNICODE)],
        );
        $this->markMediaPendingSync($article);

        return $gallery;
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function resolveProductAlbum(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');

        $featuredUrl = trim((string) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_URL)?->meta_value ?? ''));
        $featuredId = (int) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);

        $album = [];
        if ($featuredUrl !== '') {
            $album[] = [
                'id' => max(0, $featuredId),
                'url' => $featuredUrl,
                'source' => $this->sourceFromUrl($featuredUrl),
                'asset_key' => $this->assetKeyFromParts(max(0, $featuredId), $featuredUrl, $this->sourceFromUrl($featuredUrl)),
            ];
        }

        foreach ($this->resolveGallery($article) as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            $id = (int) ($item['id'] ?? 0);
            if ($url === '') {
                continue;
            }

            $exists = collect($album)->contains(
                static fn (array $row): bool => ((int) ($row['id'] ?? 0) > 0 && (int) ($row['id'] ?? 0) === $id)
                    || (string) ($row['url'] ?? '') === $url
            );
            if ($exists) {
                continue;
            }

            $album[] = [
                'id' => max(0, $id),
                'url' => $url,
                'source' => $item['source'] ?? $this->sourceFromUrl($url),
                'asset_key' => $item['asset_key'] ?? $this->assetKeyFromParts(max(0, $id), $url, (string) ($item['source'] ?? $this->sourceFromUrl($url))),
            ];
        }

        return $album;
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function appendProductAlbumLocal(SeoArticle $article, int $attachmentId, string $url): array
    {
        $url = trim($url);
        if ($attachmentId <= 0 || $url === '') {
            return $this->resolveProductAlbum($article);
        }

        $article->unsetRelation('articleMetas');
        $album = $this->resolveProductAlbum($article);
        foreach ($album as $item) {
            if ((int) ($item['id'] ?? 0) === $attachmentId || (string) ($item['url'] ?? '') === $url) {
                return $album;
            }
        }

        $album[] = [
            'id' => $attachmentId,
            'url' => $url,
        ];

        return $this->saveProductAlbumLocal($article, $album);
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    public function applyWordPressUrlMap(SeoArticle $article, array $urlMap): int
    {
        if ($urlMap === []) {
            return 0;
        }

        $article->loadMissing('articleMetas');
        $updated = 0;

        $featuredUrl = trim((string) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_URL)?->meta_value ?? ''));
        if ($featuredUrl !== '' && isset($urlMap[$featuredUrl])) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => self::META_FEATURED_URL],
                ['meta_value' => $urlMap[$featuredUrl]],
            );
            $updated++;
        }

        $gallery = $this->resolveGallery($article);
        $galleryChanged = false;
        foreach ($gallery as $index => $item) {
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '' || ! isset($urlMap[$url])) {
                continue;
            }

            $gallery[$index]['url'] = $urlMap[$url];
            $galleryChanged = true;
        }

        if ($galleryChanged) {
            $ids = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $gallery);
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => self::META_PRODUCT_GALLERY],
                ['meta_value' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => self::META_PRODUCT_GALLERY_IDS],
                ['meta_value' => json_encode($ids, JSON_UNESCAPED_UNICODE)],
            );
            $updated++;
        }

        return $updated;
    }

    public function resolveLocalRefIdFromImageUrl(int $siteId, string $url): int
    {
        $url = trim($url);
        if ($url === '') {
            return 0;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return 0;
        }

        // Absolute /storage/... or relative path under seo_media.
        $relative = '';
        if (str_contains($path, '/storage/uploads/seo_media/')) {
            $relative = ltrim(str_replace('/storage/', '', $path), '/');
        } elseif (str_starts_with(ltrim($path, '/'), 'uploads/seo_media/')) {
            $relative = ltrim($path, '/');
        } elseif (str_contains($path, '/uploads/seo_media/')) {
            $pos = strpos($path, 'uploads/seo_media/');
            $relative = $pos !== false ? substr($path, $pos) : '';
        }

        if ($relative === '') {
            return 0;
        }

        // Strip query-like suffixes accidentally left in path.
        $relative = explode('?', $relative, 2)[0];

        $query = SeoMedia::query()->where('path', $relative);
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $media = $query->first();
        if ($media !== null) {
            return (int) $media->id;
        }

        // Basename fallback (same filename under seo_media).
        $basename = basename($relative);
        if ($basename === '' || $basename === '.' || $basename === '/') {
            return 0;
        }

        $fallback = SeoMedia::query()->where('path', 'like', '%/'.$basename);
        if ($siteId > 0) {
            $fallback->where('site_id', $siteId);
        }

        $media = $fallback->orderByDesc('id')->first();

        return $media !== null ? (int) $media->id : 0;
    }

    /**
     * @param  list<string>  $orderedUrls
     * @return list<array{id: int, url: string}>
     */
    public function reorderProductAlbumLocal(SeoArticle $article, array $orderedUrls): array
    {
        $orderedUrls = array_values(array_filter(array_map(
            static fn ($url): string => trim((string) $url),
            $orderedUrls
        )));
        if ($orderedUrls === []) {
            return $this->resolveProductAlbum($article);
        }

        $current = $this->resolveProductAlbum($article);
        if ($current === []) {
            return [];
        }

        $bucket = [];
        foreach ($current as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $bucket[$url] ??= [];
            $bucket[$url][] = [
                'id' => max(0, (int) ($item['id'] ?? 0)),
                'url' => $url,
            ];
        }

        $result = [];
        foreach ($orderedUrls as $url) {
            if (! isset($bucket[$url]) || $bucket[$url] === []) {
                continue;
            }
            $result[] = array_shift($bucket[$url]);
        }

        foreach ($bucket as $items) {
            foreach ($items as $item) {
                $result[] = $item;
            }
        }

        return $this->saveProductAlbumLocal($article, $result);
    }

    /**
     * @param  list<array{id?: int, url?: string, source?: string, asset_key?: string}>  $album
     * @return list<array{id: int, url: string, source: string, asset_key: string}>
     */
    public function saveProductAlbumLocal(SeoArticle $article, array $album): array
    {
        $article->loadMissing('articleMetas');
        $existingIdsByUrl = $this->existingProductAlbumIdsByUrl($article);
        $siteId = (int) ($article->site_id ?? 0);

        $normalized = [];
        foreach ($album as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $id = $this->resolveIncomingProductAlbumRefId($item, $url, $existingIdsByUrl, $siteId);
            $source = $this->normalizeMediaSource((string) ($item['source'] ?? $this->sourceFromUrl($url)));
            $assetKey = trim((string) ($item['asset_key'] ?? $item['assetKey'] ?? ''));
            if ($assetKey === '') {
                $assetKey = $this->assetKeyFromParts($id, $url, $source);
            }
            $exists = collect($normalized)->contains(
                static fn (array $row): bool => (string) ($row['asset_key'] ?? '') === $assetKey
                    || (string) ($row['url'] ?? '') === $url
            );
            if ($exists) {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'url' => $url,
                'source' => $source,
                'asset_key' => $assetKey,
            ];
        }

        if ($normalized === []) {
            $article->articleMetas()->whereIn('meta_key', [
                self::META_FEATURED_URL,
                self::META_FEATURED_ATTACHMENT_ID,
                self::META_PRODUCT_GALLERY,
                self::META_PRODUCT_GALLERY_IDS,
            ])->delete();

            $this->markMediaPendingSync($article);
            app(ArticleFeaturedImageProjection::class)->clear($article);

            return [];
        }

        $featured = $normalized[0];
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_FEATURED_URL],
            ['meta_value' => $featured['url']],
        );
        if ((int) ($featured['id'] ?? 0) > 0) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => self::META_FEATURED_ATTACHMENT_ID],
                ['meta_value' => (string) ((int) $featured['id'])],
            );
        } else {
            $article->articleMetas()->where('meta_key', self::META_FEATURED_ATTACHMENT_ID)->delete();
        }

        $gallery = array_slice($normalized, 1);
        $galleryIds = array_values(array_filter(array_map(
            static fn (array $item): int => max(0, (int) ($item['id'] ?? 0)),
            $gallery
        )));

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PRODUCT_GALLERY],
            ['meta_value' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PRODUCT_GALLERY_IDS],
            ['meta_value' => json_encode($galleryIds, JSON_UNESCAPED_UNICODE)],
        );

        $this->markMediaPendingSync($article);
        $article->unsetRelation('articleMetas');
        app(ArticleFeaturedImageProjection::class)->rebuildAndPersist($article);

        return $normalized;
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function removeProductAlbumItemByUrl(SeoArticle $article, string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return $this->resolveProductAlbum($article);
        }

        $album = array_values(array_filter(
            $this->resolveProductAlbum($article),
            static fn (array $item): bool => trim((string) ($item['url'] ?? '')) !== $url
        ));

        return $this->saveProductAlbumLocal($article, $album);
    }

    /**
     * @return array{attempted: bool, success: bool, message: string, synced_local_media_ids: list<int>}
     */
    public function pushPendingMediaToWordPress(SeoArticle $article): array
    {
        if (! $this->hasPendingMediaSync($article)) {
            return [
                'attempted' => false,
                'success' => true,
                'message' => '',
                'synced_local_media_ids' => [],
            ];
        }

        $article->loadMissing('articleMetas');
        $mediaService = app(WordPressArticleMediaService::class);
        $localMediaSync = app(WordPressLocalMediaSyncService::class);
        $messages = [];
        $syncErrors = [];
        $syncedLocalMediaIds = [];
        $ok = true;

        $featuredRefId = (int) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        $featuredUrl = trim((string) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_URL)?->meta_value ?? ''));
        $clearFeatured = trim((string) ($article->articleMetas->firstWhere('meta_key', self::META_FEATURED_CLEAR_PENDING)?->meta_value ?? '')) === '1';
        if ($featuredRefId > 0) {
            $resolved = $this->resolveWordPressAttachmentId($article, $featuredRefId, $localMediaSync, $featuredUrl);
            if ($resolved['seo_media_id'] !== null) {
                $syncedLocalMediaIds[] = (int) $resolved['seo_media_id'];
            }
            if (! ($resolved['success'] ?? false) || (int) ($resolved['attachment_id'] ?? 0) <= 0) {
                $ok = false;
                if (filled($resolved['message'] ?? null)) {
                    $syncErrors[] = (string) $resolved['message'];
                }
            } else {
                $featuredWpId = (int) $resolved['attachment_id'];
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => self::META_FEATURED_ATTACHMENT_ID],
                    ['meta_value' => (string) $featuredWpId],
                );
                $result = $mediaService->setFeaturedImage($article, $featuredWpId);
                $ok = $ok && ($result['success'] ?? false);
                if (filled($result['message'] ?? null)) {
                    $messages[] = (string) $result['message'];
                }
            }
        } elseif ($clearFeatured) {
            $result = $mediaService->clearFeaturedImage($article);
            $ok = $ok && ($result['success'] ?? false);
            if (filled($result['message'] ?? null)) {
                $messages[] = (string) $result['message'];
            }
        }

        $galleryRefs = $this->resolveGalleryAttachmentRefs($article);
        if ($galleryRefs !== []) {
            $galleryWpIds = [];
            foreach ($galleryRefs as $ref) {
                $resolved = $this->resolveWordPressAttachmentId(
                    $article,
                    (int) ($ref['id'] ?? 0),
                    $localMediaSync,
                    (string) ($ref['url'] ?? ''),
                );
                if ($resolved['seo_media_id'] !== null) {
                    $syncedLocalMediaIds[] = (int) $resolved['seo_media_id'];
                }
                if (! ($resolved['success'] ?? false) || (int) ($resolved['attachment_id'] ?? 0) <= 0) {
                    $ok = false;
                    if (filled($resolved['message'] ?? null)) {
                        $syncErrors[] = (string) $resolved['message'];
                    }

                    continue;
                }

                $galleryWpIds[] = (int) $resolved['attachment_id'];
            }

            $galleryWpIds = array_values(array_unique(array_filter($galleryWpIds, static fn (int $id): bool => $id > 0)));
            if ($galleryWpIds !== []) {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => self::META_PRODUCT_GALLERY_IDS],
                    ['meta_value' => json_encode($galleryWpIds, JSON_UNESCAPED_UNICODE)],
                );
                $result = $mediaService->setProductGallery($article, $galleryWpIds);
                $ok = $ok && ($result['success'] ?? false);
                if (filled($result['message'] ?? null)) {
                    $messages[] = (string) $result['message'];
                }
            }
        }

        if ($syncErrors !== []) {
            $messages = array_merge($messages, $syncErrors);
        }

        if ($ok) {
            $this->clearMediaPendingSync($article);
        }

        return [
            'attempted' => true,
            'success' => $ok,
            'message' => implode(' ', array_filter($messages)),
            'synced_local_media_ids' => array_values(array_unique(array_filter(array_map(
                static fn ($id): int => (int) $id,
                $syncedLocalMediaIds,
            )))),
        ];
    }

    /**
     * @return array{success: bool, attachment_id: int, seo_media_id: int|null, message: string}
     */
    private function resolveWordPressAttachmentId(
        SeoArticle $article,
        int $refId,
        WordPressLocalMediaSyncService $localMediaSync,
        string $url = '',
    ): array {
        if ($refId <= 0) {
            return [
                'success' => false,
                'attachment_id' => 0,
                'seo_media_id' => null,
                'message' => 'ID ảnh không hợp lệ.',
            ];
        }

        $media = SeoMedia::query()->whereKey($refId)->first();
        if ($media instanceof SeoMedia) {
            $existingWpAttachmentId = (int) ($media->wp_attachment_id ?? 0);
            if ($existingWpAttachmentId > 0) {
                return [
                    'success' => true,
                    'attachment_id' => $existingWpAttachmentId,
                    'seo_media_id' => (int) $media->id,
                    'message' => '',
                ];
            }

            $result = $localMediaSync->syncAttachmentRef($article, $refId);

            return [
                'success' => (bool) ($result['success'] ?? false),
                'attachment_id' => (int) ($result['attachment_id'] ?? 0),
                'seo_media_id' => isset($result['seo_media_id']) ? (int) $result['seo_media_id'] : null,
                'message' => (string) ($result['message'] ?? ''),
            ];
        }

        if ($this->isWordPressMediaUrl($url)) {
            return [
                'success' => true,
                'attachment_id' => $refId,
                'seo_media_id' => null,
                'message' => '',
            ];
        }

        return [
            'success' => true,
            'attachment_id' => $refId,
            'seo_media_id' => null,
            'message' => '',
        ];
    }

    public function hasPendingMediaSync(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');

        if (trim((string) ($article->articleMetas->firstWhere('meta_key', self::META_MEDIA_PENDING_SYNC)?->meta_value ?? '')) === '1') {
            return true;
        }

        if ($this->hasStoredFeaturedOrGalleryRefs($article)) {
            return true;
        }

        return $this->featuredNeedsWordPressWebpPush($article);
    }

    private function hasStoredFeaturedOrGalleryRefs(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');

        $featuredRefId = (int) ($article->articleMetas
            ->firstWhere('meta_key', self::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredRefId > 0) {
            return true;
        }

        return $this->resolveGalleryAttachmentIds($article) !== [];
    }

    private function featuredNeedsWordPressWebpPush(SeoArticle $article): bool
    {
        $article->loadMissing('site', 'articleMetas');
        $site = $article->site;
        if ($site === null) {
            return false;
        }

        $optimization = app(SeoImageOptimizationService::class);
        $config = $optimization->resolveForSite((int) $site->id);
        if (! (bool) $config->auto_convert_webp) {
            return false;
        }

        $featuredRefId = (int) ($article->articleMetas
            ->firstWhere('meta_key', self::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredRefId <= 0) {
            return false;
        }

        $featuredUrl = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_FEATURED_URL)?->meta_value ?? ''));
        if ($featuredUrl !== '' && $optimization->isWebpUrl($featuredUrl)) {
            return false;
        }

        $media = SeoMedia::query()->whereKey($featuredRefId)->first();
        if (! $media instanceof SeoMedia) {
            return false;
        }

        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return false;
        }

        $absolutePath = Storage::disk('public')->path($path);

        return $optimization->needsWordPressWebpBackfill(
            $config,
            $absolutePath,
            $featuredUrl !== '' ? $featuredUrl : null,
        );
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function resolveGallery(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_PRODUCT_GALLERY)?->meta_value ?? '';
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? app(WordPressArticleContentService::class)->normalizeProductGallery($decoded)
            : [];
    }

    /**
     * @return list<int>
     */
    public function resolveGalleryAttachmentIds(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_PRODUCT_GALLERY_IDS)?->meta_value ?? '';
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map(static fn ($id): int => (int) $id, $decoded), static fn (int $id): bool => $id > 0));
            }
        }

        return array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $this->resolveGallery($article),
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function resolveGalleryAttachmentRefs(SeoArticle $article): array
    {
        $refs = [];
        foreach ($this->resolveGallery($article) as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $refs[] = [
                'id' => $id,
                'url' => trim((string) ($item['url'] ?? '')),
            ];
        }

        if ($refs !== []) {
            return $refs;
        }

        return array_map(
            static fn (int $id): array => ['id' => $id, 'url' => ''],
            $this->resolveGalleryAttachmentIds($article),
        );
    }

    /**
     * @return array<string, int>
     */
    private function existingProductAlbumIdsByUrl(SeoArticle $article): array
    {
        $map = [];
        foreach ($this->resolveProductAlbum($article) as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            $id = (int) ($row['id'] ?? 0);
            if ($url !== '' && $id > 0) {
                $map[$url] = $id;
            }
        }

        return $map;
    }

    /**
     * Giữ ID đã lưu khi client chỉ gửi URL (tránh xóa gallery attachment trước sync WP).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $existingIdsByUrl
     */
    private function resolveIncomingProductAlbumRefId(
        array $item,
        string $url,
        array $existingIdsByUrl,
        int $siteId,
    ): int {
        $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
        $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? 0));
        $id = max(0, (int) ($item['id'] ?? 0));

        if ($id <= 0) {
            $id = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;
        }

        if ($id <= 0 && isset($existingIdsByUrl[$url])) {
            $id = $existingIdsByUrl[$url];
        }

        if ($id <= 0 && $siteId > 0) {
            $id = $this->resolveLocalRefIdFromImageUrl($siteId, $url);
        }

        return max(0, $id);
    }

    private function markMediaPendingSync(SeoArticle $article): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_MEDIA_PENDING_SYNC],
            ['meta_value' => '1'],
        );
    }

    private function normalizeMediaSource(string $source): string
    {
        $source = strtolower(trim($source));

        return match ($source) {
            'wp' => 'wordpress',
            'wordpress', 'local', 'generated', 'uploaded' => $source,
            default => 'unknown',
        };
    }

    private function sourceFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'unknown';
        }

        if (str_contains($url, '/wp-content/uploads/')) {
            return 'wordpress';
        }

        if (str_contains($url, '/storage/uploads/seo_media/')
            || str_contains($url, '/storage/seo/')
            || str_contains($url, '/seo-media/')
            || str_contains($url, '/storage/')) {
            return 'local';
        }

        return 'uploaded';
    }

    private function isWordPressMediaUrl(string $url): bool
    {
        return str_contains(strtolower($url), '/wp-content/uploads/');
    }

    private function assetKeyFromParts(int $id, string $url, string $source): string
    {
        $source = $this->normalizeMediaSource($source);
        if ($source === 'wordpress' && $id > 0) {
            return 'wp:'.$id;
        }

        if (in_array($source, ['local', 'generated', 'uploaded'], true) && $id > 0) {
            return 'local:'.$id;
        }

        return 'url:'.substr(hash('sha256', $url), 0, 16);
    }

    private function clearMediaPendingSync(SeoArticle $article): void
    {
        $article->articleMetas()->whereIn('meta_key', [
            self::META_MEDIA_PENDING_SYNC,
            self::META_FEATURED_CLEAR_PENDING,
        ])->delete();
    }

    public function linkSeoMediaToArticle(SeoMedia $media, SeoArticle $article): void
    {
        $updates = [];
        $siteId = (int) ($article->site_id ?? 0);
        $articleId = (int) ($article->id ?? 0);

        if ($siteId > 0 && (int) ($media->site_id ?? 0) <= 0) {
            $updates['site_id'] = $siteId;
        }

        if ($articleId > 0) {
            $articleIds = SeoMedia::normalizeArticleIds($media->article_id);
            if (! in_array($articleId, $articleIds, true)) {
                $articleIds[] = $articleId;
                $updates['article_id'] = array_values(array_unique($articleIds));
            }
        }

        if ($updates !== []) {
            $media->update($updates);
        }
    }

    public function appendGeneratedImageToProductAlbum(
        SeoArticle $article,
        SeoMedia $media,
        ?string $url = null,
    ): bool {
        if ((string) ($article->type ?? '') !== 'product') {
            return false;
        }

        $url = trim($url ?? $media->publicUrl());
        if ($url === '' || (int) $media->id <= 0) {
            return false;
        }

        $this->linkSeoMediaToArticle($media, $article);
        $article->unsetRelation('articleMetas');
        $beforeCount = count($this->resolveProductAlbum($article));
        $after = $this->appendProductAlbumLocal($article, (int) $media->id, $url);

        return count($after) > $beforeCount;
    }

    /**
     * Replace product album with Mode 1 gallery items (AI children or original fallback).
     *
     * @param  list<array{id?: int, url?: string}>  $album
     * @return list<array{id: int, url: string}>
     */
    public function replaceProductAlbumLocal(SeoArticle $article, array $album): array
    {
        if ((string) ($article->type ?? '') !== 'product') {
            return $this->resolveProductAlbum($article);
        }

        foreach ($album as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $media = SeoMedia::query()->find($id);
            if ($media instanceof SeoMedia) {
                $this->linkSeoMediaToArticle($media, $article);
            }
        }

        $article->unsetRelation('articleMetas');

        return $this->saveProductAlbumLocal($article, $album);
    }
}
