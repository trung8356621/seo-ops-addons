<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\Content\Support\ArticleFeaturedImageStatus;
use Omnichannel\Addons\Content\Support\ArticleFeaturedImageResolver;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;

/**
 * Canonical Article Editor media snapshot (Featured + Gallery + content image counts).
 * Laravel owns persistence; React owns presentation only.
 */
final class ArticleEditorMediaSnapshotService
{
    public const META_SNAPSHOT_VERSION = 'editor_media_snapshot_version';

    public const SCHEMA_VERSION = 1;

    /** @var array<string, SeoMedia|null>|null Request-local lookup for one build() call */
    private ?array $seoMediaByKey = null;

    public function __construct(
        private readonly ArticleMediaLocalService $mediaLocal,
        private readonly ArticlePostImagesService $postImages,
    ) {}

    /**
     * @param  bool  $refresh  When false (bootstrap read), skip article->refresh() + meta reload.
     * @return array<string, mixed>
     */
    public function build(SeoArticle $article, ?User $viewer = null, bool $refresh = true): array
    {
        $article->loadMissing(['articleMetas', 'site']);
        if ($refresh) {
            $article->refresh();
            $article->unsetRelation('articleMetas');
            $article->load('articleMetas');
        }

        $supportsGallery = $this->supportsProductGallery($article);
        $siteId = (int) ($article->site_id ?? 0);
        $this->primeSeoMediaLookup($article, $supportsGallery, $siteId);

        try {
            $featured = $this->buildFeatured($article, $supportsGallery);
            $gallery = $this->buildGallery($article, $supportsGallery, $featured);
            $contentImages = $this->buildContentImagesSummary($article);
            $version = $this->currentVersion($article);

            return [
                'version' => self::SCHEMA_VERSION,
                'snapshot_version' => $version,
                'article_id' => (int) $article->getKey(),
                'document_version' => max(1, (int) ($article->document_version ?? 1)),
                'generated_at' => now()->utc()->toIso8601String(),
                'featured' => $featured,
                'content_images' => $contentImages,
                'gallery' => $gallery,
                'capabilities' => $this->capabilities($article, $viewer, $supportsGallery),
            ];
        } finally {
            $this->seoMediaByKey = null;
        }
    }

    public function currentVersion(SeoArticle $article): int
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas
            ->firstWhere('meta_key', self::META_SNAPSHOT_VERSION)?->meta_value;

        return max(1, (int) $raw);
    }

    public function bumpVersion(SeoArticle $article): int
    {
        $next = $this->currentVersion($article) + 1;
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_SNAPSHOT_VERSION],
            ['meta_value' => (string) $next],
        );
        $article->unsetRelation('articleMetas');

        return $next;
    }

    public function assertExpectedVersion(SeoArticle $article, int|string|null $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $expectedInt = (int) $expected;
        if ($expectedInt <= 0) {
            return;
        }

        $current = $this->currentVersion($article);
        if ($expectedInt !== $current) {
            throw ArticleEditorSessionException::make(
                'media_snapshot_version_conflict',
                'Media snapshot version conflict.',
                [
                    'expected_snapshot_version' => $expectedInt,
                    'snapshot_version' => $current,
                ],
                409,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFeatured(SeoArticle $article, bool $supportsGallery): ?array
    {
        $built = app(ArticleFeaturedImageResolver::class)->rebuildFromStoredSources($article);
        $url = trim((string) ($built['url'] ?? ''));
        $attachmentId = (int) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);

        if ($url === '' && $supportsGallery && $built['status'] !== ArticleFeaturedImageStatus::AVAILABLE) {
            $album = $this->mediaLocal->resolveProductAlbum($article);
            if ($album !== []) {
                $url = trim((string) ($album[0]['url'] ?? ''));
                $attachmentId = (int) ($album[0]['id'] ?? 0);
            }
        }

        if ($url === '') {
            return null;
        }

        return $this->enrichMediaItem([
            'media_id' => $built['media_id'] ?? null,
            'wp_attachment_id' => $attachmentId > 0 ? $attachmentId : null,
            'url' => $url,
            'alt' => '',
            'title' => '',
            'position' => 0,
        ], (int) ($article->site_id ?? 0));
    }

    /**
     * @param  array<string, mixed>|null  $featured
     * @return array{required: bool, items: list<array<string, mixed>>}
     */
    private function buildGallery(SeoArticle $article, bool $supportsGallery, ?array $featured): array
    {
        if (! $supportsGallery) {
            return [
                'required' => false,
                'items' => [],
            ];
        }

        $album = $this->mediaLocal->resolveProductAlbum($article);
        $items = [];
        foreach ($album as $index => $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $attachmentId = (int) ($row['id'] ?? 0);
            $items[] = $this->enrichMediaItem([
                'media_id' => null,
                'wp_attachment_id' => $attachmentId > 0 ? $attachmentId : null,
                'url' => $url,
                'alt' => '',
                'title' => '',
                'position' => $index,
            ], (int) ($article->site_id ?? 0));
        }

        return [
            'required' => true,
            'items' => $items,
        ];
    }

    /**
     * @return array{occurrence_count: int, valid_count: int, invalid_count: int, items: list<array<string, mixed>>}
     */
    private function buildContentImagesSummary(SeoArticle $article): array
    {
        $rows = $this->postImages->resolveForArticle($article);
        $items = [];
        $valid = 0;
        $invalid = 0;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? $row['src'] ?? $row['full_url'] ?? ''));
            $exists = $url !== '' && ! str_starts_with($url, 'blob:');
            if ($exists) {
                $valid++;
            } else {
                $invalid++;
            }
            $items[] = [
                'position' => $index,
                'url' => $url !== '' ? $url : null,
                'exists' => $exists,
                'integrity' => [
                    'status' => $exists ? 'healthy' : 'error',
                    'reasons' => $exists ? [] : ['content_image_missing'],
                ],
            ];
        }

        return [
            'occurrence_count' => count($items),
            'valid_count' => $valid,
            'invalid_count' => $invalid,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function enrichMediaItem(array $base, int $siteId): array
    {
        $url = trim((string) ($base['url'] ?? ''));
        $wpId = (int) ($base['wp_attachment_id'] ?? 0);
        if ($this->isLocalLaravelMediaUrl($url)) {
            $wpId = 0;
        }
        $seoMedia = $this->findSeoMedia($siteId, $wpId, $url);

        $mediaId = $seoMedia instanceof SeoMedia ? (int) $seoMedia->getKey() : null;
        // Featured/gallery meta may store SeoMedia PK in "attachment id" before WP sync.
        // Never emit that as wp_attachment_id for pure Laravel storage URLs.
        if ($seoMedia instanceof SeoMedia) {
            $realWp = (int) ($seoMedia->wp_attachment_id ?? 0);
            $wpId = $realWp > 0 ? $realWp : 0;
        }

        $alt = trim((string) ($seoMedia?->alt_text ?? $seoMedia?->alt ?? $base['alt'] ?? ''));
        $title = trim((string) ($seoMedia?->title ?? $base['title'] ?? ''));
        $filename = $this->filenameFromUrl($url);
        $source = $this->classifySource($seoMedia, $wpId, $url);
        $assetKey = $this->assetKey($source, $mediaId, $wpId, $url, (string) ($base['id'] ?? ''));
        $exists = $url !== '' && ! str_starts_with($url, 'blob:') && ! str_starts_with($url, 'data:');
        $uploadIncomplete = str_starts_with($url, 'blob:') || str_contains($url, 'placeholder-loading');

        $reasons = [];
        $status = 'healthy';
        if (! $exists || $uploadIncomplete) {
            $status = 'error';
            $reasons[] = $uploadIncomplete ? 'featured_upload_incomplete' : 'media_reference_broken';
        } elseif ($alt === '') {
            $status = 'warning';
            $reasons[] = 'featured_alt_missing';
        }

        // WP filename ≠ keyword is NOT a hard error (Phase 2A).

        return [
            'id' => $assetKey,
            'asset_key' => $assetKey,
            'media_id' => $mediaId,
            'wp_attachment_id' => $wpId > 0 ? $wpId : null,
            'source' => $source,
            'url' => $url,
            'thumbnail_url' => $url,
            'filename' => $filename,
            'alt' => $alt,
            'title' => $title,
            'position' => (int) ($base['position'] ?? 0),
            'exists' => $exists && ! $uploadIncomplete,
            'upload_status' => $uploadIncomplete ? 'pending' : ($exists ? 'ready' : 'missing'),
            'integrity' => [
                'status' => $status,
                'reasons' => $reasons,
            ],
        ];
    }

    private function findSeoMedia(int $siteId, int $wpAttachmentId, string $url): ?SeoMedia
    {
        if ($siteId <= 0) {
            return null;
        }

        if ($this->seoMediaByKey !== null) {
            if ($wpAttachmentId > 0) {
                $wpKey = 'wp:'.$siteId.':'.$wpAttachmentId;
                if (array_key_exists($wpKey, $this->seoMediaByKey) && $this->seoMediaByKey[$wpKey] instanceof SeoMedia) {
                    return $this->seoMediaByKey[$wpKey];
                }
            }
            if ($url !== '') {
                $urlKey = 'url:'.$siteId.':'.$url;
                if (array_key_exists($urlKey, $this->seoMediaByKey) && $this->seoMediaByKey[$urlKey] instanceof SeoMedia) {
                    return $this->seoMediaByKey[$urlKey];
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path !== '') {
                    $pathKey = 'url:'.$siteId.':'.$path;
                    if (array_key_exists($pathKey, $this->seoMediaByKey) && $this->seoMediaByKey[$pathKey] instanceof SeoMedia) {
                        return $this->seoMediaByKey[$pathKey];
                    }
                }
            }

            return null;
        }

        if ($wpAttachmentId > 0) {
            $byWp = SeoMedia::query()
                ->where('site_id', $siteId)
                ->where('wp_attachment_id', $wpAttachmentId)
                ->orderByDesc('id')
                ->first();
            if ($byWp instanceof SeoMedia) {
                return $byWp;
            }
        }

        if ($url === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $relativePath = str_starts_with($path, '/storage/')
            ? ltrim(substr($path, strlen('/storage/')), '/')
            : '';

        $byUrl = SeoMedia::query()
            ->where('site_id', $siteId)
            ->where(function ($q) use ($url, $path, $relativePath): void {
                $q->where('url', $url);

                if ($path !== '') {
                    $q->orWhere('url', $path);
                }

                if ($relativePath !== '') {
                    $q->orWhere('path', $relativePath);
                }
            })
            ->orderByDesc('id')
            ->first();

        return $byUrl instanceof SeoMedia ? $byUrl : null;
    }

    /**
     * Batch-load SeoMedia for featured + gallery refs (kills N+1 per enrichMediaItem).
     */
    private function primeSeoMediaLookup(SeoArticle $article, bool $supportsGallery, int $siteId): void
    {
        $this->seoMediaByKey = [];
        if ($siteId <= 0) {
            return;
        }

        $wpIds = [];
        $urls = [];

        $featuredUrl = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_URL)?->meta_value ?? ''));
        $featuredWp = (int) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredWp > 0) {
            $wpIds[] = $featuredWp;
        }
        if ($featuredUrl !== '') {
            $urls[] = $featuredUrl;
        }

        if ($supportsGallery) {
            foreach ($this->mediaLocal->resolveProductAlbum($article) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $wp = (int) ($row['id'] ?? 0);
                $url = trim((string) ($row['url'] ?? ''));
                if ($wp > 0) {
                    $wpIds[] = $wp;
                }
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $wpIds = array_values(array_unique(array_filter($wpIds, static fn (int $id): bool => $id > 0)));
        $urls = array_values(array_unique(array_filter($urls, static fn (string $u): bool => $u !== '')));

        if ($wpIds !== []) {
            $byWp = SeoMedia::query()
                ->where('site_id', $siteId)
                ->whereIn('wp_attachment_id', $wpIds)
                ->orderByDesc('id')
                ->get();
            foreach ($byWp as $media) {
                if (! $media instanceof SeoMedia) {
                    continue;
                }
                $key = 'wp:'.$siteId.':'.(int) $media->wp_attachment_id;
                if (! isset($this->seoMediaByKey[$key])) {
                    $this->seoMediaByKey[$key] = $media;
                }
            }
        }

        if ($urls === []) {
            return;
        }

        $paths = [];
        $relativePaths = [];
        foreach ($urls as $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path !== '') {
                $paths[] = $path;
            }
            if (str_starts_with($path, '/storage/')) {
                $relativePaths[] = ltrim(substr($path, strlen('/storage/')), '/');
            }
        }
        $paths = array_values(array_unique(array_filter($paths)));
        $relativePaths = array_values(array_unique(array_filter($relativePaths)));

        $byUrl = SeoMedia::query()
            ->where('site_id', $siteId)
            ->where(function ($q) use ($urls, $paths, $relativePaths): void {
                $q->whereIn('url', $urls);
                if ($paths !== []) {
                    $q->orWhereIn('url', $paths);
                }
                if ($relativePaths !== []) {
                    $q->orWhereIn('path', $relativePaths);
                }
            })
            ->orderByDesc('id')
            ->get();

        foreach ($byUrl as $media) {
            if (! $media instanceof SeoMedia) {
                continue;
            }
            $mediaUrl = trim((string) ($media->url ?? ''));
            if ($mediaUrl !== '') {
                $this->seoMediaByKey['url:'.$siteId.':'.$mediaUrl] ??= $media;
            }
            $mediaPath = trim((string) ($media->path ?? ''));
            if ($mediaPath !== '') {
                $this->seoMediaByKey['url:'.$siteId.':/storage/'.$mediaPath] ??= $media;
                $this->seoMediaByKey['url:'.$siteId.':'.$mediaPath] ??= $media;
            }
        }
    }

    private function classifySource(?SeoMedia $media, int $wpAttachmentId, string $url): string
    {
        if ($media instanceof SeoMedia) {
            $kind = strtolower(trim((string) ($media->source_type ?? $media->kind ?? '')));
            if (in_array($kind, ['wordpress', 'wp', 'local', 'generated', 'uploaded'], true)) {
                return $kind === 'wp' ? 'wordpress' : $kind;
            }
            if ((int) ($media->wp_attachment_id ?? 0) > 0) {
                return 'wordpress';
            }
            if (trim((string) ($media->ai_job_id ?? '')) !== '' || str_contains(strtolower((string) ($media->status ?? '')), 'generat')) {
                return 'generated';
            }

            return 'local';
        }

        if ($this->isLocalLaravelMediaUrl($url)) {
            return 'local';
        }

        if ($wpAttachmentId > 0 || str_contains($url, '/wp-content/uploads/')) {
            return 'wordpress';
        }

        return $url !== '' ? 'uploaded' : 'unknown';
    }

    private function isLocalLaravelMediaUrl(string $url): bool
    {
        $value = trim($url);
        if ($value === '' || str_contains($value, '/wp-content/uploads/')) {
            return false;
        }

        return str_contains($value, '/storage/uploads/seo_media/')
            || str_contains($value, '/storage/seo/')
            || str_contains($value, '/seo-media/')
            || str_contains($value, '/storage/');
    }

    private function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return '';
        }

        return basename($path);
    }

    private function assetKey(string $source, ?int $mediaId, int $wpAttachmentId, string $url, string $fallback = ''): string
    {
        $source = strtolower(trim($source));

        if ($source === 'wordpress' && $wpAttachmentId > 0) {
            return 'wp:'.$wpAttachmentId;
        }

        if (in_array($source, ['local', 'generated', 'uploaded'], true) && $mediaId !== null && $mediaId > 0) {
            return 'local:'.$mediaId;
        }

        if ($source === 'wordpress' && $mediaId !== null && $mediaId > 0) {
            return 'wp-media:'.$mediaId;
        }

        if ($fallback !== '' && str_contains($fallback, ':')) {
            return $fallback;
        }

        return 'url:'.substr(hash('sha256', $url), 0, 16);
    }

    private function supportsProductGallery(SeoArticle $article): bool
    {
        $postType = ArticlePostTypeResolver::resolve($article);
        if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
            return true;
        }

        return strtolower(trim((string) ($article->articleMetas->firstWhere('meta_key', 'canary_type')?->meta_value ?? ''))) === 'product_gallery';
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(SeoArticle $article, ?User $viewer, bool $supportsGallery): array
    {
        $canEdit = SeoAccessControl::canAccessArticle($article);
        $archived = false;
        try {
            app(ArticleEditorSessionService::class)->assertArticleEditable($article);
        } catch (ArticleEditorSessionException) {
            $archived = true;
            $canEdit = false;
        }

        $canRenameWp = $viewer instanceof User
            && SeoAccessControl::canAccessManagerFeatures();

        return [
            'can_edit_featured' => $canEdit && ! $archived,
            'can_edit_gallery' => $canEdit && ! $archived && $supportsGallery,
            'can_browse_wordpress_media' => $canEdit,
            'can_upload_local_media' => $canEdit && ! $archived,
            'can_rename_wordpress_media' => $canRenameWp && ! $archived,
            'gallery_required' => $supportsGallery,
            'content_project_archived' => $archived,
        ];
    }
}
