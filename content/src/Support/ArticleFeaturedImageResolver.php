<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Content\Support\ArticleFeaturedImageSource;
use Omnichannel\Addons\Content\Support\ArticleFeaturedImageStatus;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use App\Support\RuntimeLogger;

/**
 * Rebuild featured projection from stored Laravel sources only.
 * Never HTTP, never body parse, never content-image gallery meta as featured.
 * (Content images ≠ featured image.)
 *
 * Precedence (Editor-aligned):
 * 1. editor_local meta (URL + attachment id → SeoMedia)
 * 2. product album[0] when post type product
 * 3. wp_snapshot URL meta alone
 * 4. absent when no featured signals
 *
 * @phpstan-type RebuildResult array{
 *     status: string,
 *     url: ?string,
 *     media_id: ?int,
 *     source: string,
 *     conflict: bool,
 *     candidates: list<string>
 * }
 */
final class ArticleFeaturedImageResolver
{
    /**
     * @return RebuildResult
     */
    public function rebuildFromStoredSources(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');

        $candidates = [];
        $urlMeta = $this->metaValue($article, ArticleMediaLocalService::META_FEATURED_URL);
        $refId = (int) $this->metaValue($article, ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID);

        $fromEditor = $this->resolveEditorLocal($article, $urlMeta, $refId);
        if ($fromEditor !== null) {
            $candidates[] = ArticleFeaturedImageSource::EDITOR_LOCAL;
        }

        $fromAlbum = null;
        if ($this->supportsProductGallery($article)) {
            $fromAlbum = $this->resolveProductAlbumFeatured($article);
            if ($fromAlbum !== null) {
                $candidates[] = ArticleFeaturedImageSource::PRODUCT_ALBUM;
            }
        }

        $fromWpSnapshot = null;
        if ($urlMeta !== '' && $fromEditor === null) {
            $fromWpSnapshot = [
                'url' => $this->normalizeUrl($urlMeta),
                'media_id' => $refId > 0 ? $refId : null,
                'source' => ArticleFeaturedImageSource::WP_SNAPSHOT,
            ];
            $candidates[] = ArticleFeaturedImageSource::WP_SNAPSHOT;
        }

        $winner = $fromEditor ?? $fromAlbum ?? $fromWpSnapshot;
        $conflict = count(array_unique($candidates)) > 1;

        if ($winner === null) {
            if ($urlMeta === '' && $refId <= 0) {
                return [
                    'status' => ArticleFeaturedImageStatus::ABSENT,
                    'url' => null,
                    'media_id' => null,
                    'source' => ArticleFeaturedImageSource::CLEARED,
                    'conflict' => false,
                    'candidates' => [],
                ];
            }

            // Meta present but unresolvable (orphan id / empty URL).
            return [
                'status' => ArticleFeaturedImageStatus::UNKNOWN,
                'url' => null,
                'media_id' => $refId > 0 ? $refId : null,
                'source' => ArticleFeaturedImageSource::EDITOR_LOCAL,
                'conflict' => $conflict,
                'candidates' => $candidates,
            ];
        }

        if ($conflict) {
            $context = [
                'article_id' => (int) $article->getKey(),
                'candidates' => $candidates,
                'chosen_source' => $winner['source'],
            ];
            if (app()->runningInConsole()) {
                \Illuminate\Support\Facades\Log::warning('seo.featured_image.conflict', $context);
            } else {
                RuntimeLogger::warning('seo.featured_image.conflict', $context);
            }
        }

        $url = trim((string) ($winner['url'] ?? ''));
        if ($url === '') {
            return [
                'status' => ArticleFeaturedImageStatus::UNKNOWN,
                'url' => null,
                'media_id' => isset($winner['media_id']) ? (int) $winner['media_id'] : null,
                'source' => (string) $winner['source'],
                'conflict' => $conflict,
                'candidates' => $candidates,
            ];
        }

        return [
            'status' => ArticleFeaturedImageStatus::AVAILABLE,
            'url' => $url,
            'media_id' => isset($winner['media_id']) ? (int) $winner['media_id'] : null,
            'source' => $conflict
                ? ArticleFeaturedImageSource::CONFLICT_RESOLVED
                : (string) $winner['source'],
            'conflict' => $conflict,
            'candidates' => $candidates,
        ];
    }

    /**
     * List read of stored projection columns only — no meta / SeoMedia / HTTP.
     *
     * @return array{status: string, url: ?string, media_id: ?int, source: ?string}
     */
    public function forList(SeoArticle $article): array
    {
        // Media addon is sole owner of featured projection state.
        $article->loadMissing('featuredMediaState');
        $state = $article->featuredMediaState;

        $status = trim((string) ($state?->status ?? ''));
        if ($status === '' || ! in_array($status, ArticleFeaturedImageStatus::all(), true)) {
            $status = ArticleFeaturedImageStatus::UNKNOWN;
        }
        $url = trim((string) ($state?->display_url ?? ''));
        $mediaId = $state?->media_id !== null ? (int) $state->media_id : null;
        $source = trim((string) ($state?->source ?? ''));

        if ($status === ArticleFeaturedImageStatus::AVAILABLE && $url === '') {
            $status = ArticleFeaturedImageStatus::UNKNOWN;
        }

        return [
            'status' => $status,
            'url' => $status === ArticleFeaturedImageStatus::AVAILABLE ? $url : null,
            'media_id' => $mediaId !== null && $mediaId > 0 ? $mediaId : null,
            'source' => $source !== '' ? $source : null,
        ];
    }

    /** @deprecated use forList / rebuildFromStoredSources */
    public function urlForList(SeoArticle $article): ?string
    {
        return $this->forList($article)['url'];
    }

    /** @deprecated use forList */
    public function forArticle(SeoArticle $article): ?array
    {
        $row = $this->forList($article);
        if ($row['status'] !== ArticleFeaturedImageStatus::AVAILABLE || $row['url'] === null) {
            return null;
        }

        return [
            'url' => $row['url'],
            'media_id' => $row['media_id'],
            'source' => (string) ($row['source'] ?? 'meta'),
            'status' => $row['status'],
        ];
    }

    /**
     * @param  iterable<mixed>  $articles
     */
    public function primeForArticles(iterable $articles): void
    {
        // Projection columns are on the article row — no batch SeoMedia needed for list GET.
    }

    /**
     * @return array{url: string, media_id: ?int, source: string}|null
     */
    private function resolveEditorLocal(SeoArticle $article, string $urlMeta, int $refId): ?array
    {
        $siteId = (int) ($article->site_id ?? 0);
        $media = $this->findSeoMedia($siteId, $refId, $urlMeta);

        if ($media instanceof SeoMedia) {
            $canonical = trim($media->publicUrl());
            if ($canonical !== '') {
                return [
                    'url' => $canonical,
                    'media_id' => (int) $media->getKey(),
                    'source' => ArticleFeaturedImageSource::SEO_MEDIA,
                ];
            }
        }

        if ($urlMeta !== '') {
            return [
                'url' => $this->normalizeUrl($urlMeta),
                'media_id' => $media instanceof SeoMedia
                    ? (int) $media->getKey()
                    : ($refId > 0 ? $refId : null),
                'source' => ArticleFeaturedImageSource::EDITOR_LOCAL,
            ];
        }

        return null;
    }

    /**
     * @return array{url: string, media_id: ?int, source: string}|null
     */
    private function resolveProductAlbumFeatured(SeoArticle $article): ?array
    {
        $album = app(ArticleMediaLocalService::class)->resolveProductAlbum($article);
        if ($album === []) {
            return null;
        }

        $url = trim((string) ($album[0]['url'] ?? ''));
        $id = (int) ($album[0]['id'] ?? 0);
        if ($url === '') {
            return null;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $media = $this->findSeoMedia($siteId, $id, $url);
        if ($media instanceof SeoMedia) {
            $canonical = trim($media->publicUrl());
            if ($canonical !== '') {
                return [
                    'url' => $canonical,
                    'media_id' => (int) $media->getKey(),
                    'source' => ArticleFeaturedImageSource::PRODUCT_ALBUM,
                ];
            }
        }

        return [
            'url' => $this->normalizeUrl($url),
            'media_id' => $id > 0 ? $id : null,
            'source' => ArticleFeaturedImageSource::PRODUCT_ALBUM,
        ];
    }

    private function findSeoMedia(int $siteId, int $refId, string $url): ?SeoMedia
    {
        if ($refId > 0) {
            $byPk = SeoMedia::query()->whereKey($refId)->first();
            if ($byPk instanceof SeoMedia) {
                return $byPk;
            }

            if ($siteId > 0) {
                $byWp = SeoMedia::query()
                    ->where('site_id', $siteId)
                    ->where('wp_attachment_id', $refId)
                    ->orderByDesc('id')
                    ->first();
                if ($byWp instanceof SeoMedia) {
                    return $byWp;
                }
            }
        }

        if ($siteId <= 0 || $url === '') {
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

    private function supportsProductGallery(SeoArticle $article): bool
    {
        $postType = ArticlePostTypeResolver::resolve($article);

        return SeoProjectTask::normalizePostType($postType) === 'product';
    }

    private function metaValue(SeoArticle $article, string $key): string
    {
        return trim((string) ($article->articleMetas->firstWhere('meta_key', $key)?->meta_value ?? ''));
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '/')) {
            return $url;
        }
        if (str_starts_with($url, 'storage/')) {
            return '/'.$url;
        }
        if (str_contains($url, 'uploads/seo_media/') || str_contains($url, 'seo_media/')) {
            return '/storage/'.ltrim($url, '/');
        }

        return $url;
    }
}
