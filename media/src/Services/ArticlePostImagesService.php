<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\WordPress\Support\WordPressImageUrl;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class ArticlePostImagesService
{
    public const META_KEY = 'wp_post_images';

    /**
     * Count images from cached `wp_post_images` meta only.
     * Never resolves HTML or calls WordPress — safe for Article List rows.
     */
    public function countCachedForArticle(SeoArticle $article): int
    {
        return count($this->normalizeList($this->getMetaJson($article)));
    }

    /**
     * Prefer cached meta; otherwise resolve from HTML (may hit WordPress).
     * Do not use on Article List GET — use {@see countCachedForArticle()} instead.
     */
    public function countForArticle(SeoArticle $article): int
    {
        $cached = $this->getMetaJson($article);
        if ($cached !== []) {
            return count($this->normalizeList($cached));
        }

        return count($this->resolveForArticle($article));
    }

    public function resolveForArticle(SeoArticle $article): array
    {
        $cached = $this->getMetaJson($article);
        if ($cached !== []) {
            return $this->enrichWithSeoMediaUrls($article, $this->normalizeList($cached));
        }

        $html = trim((string) app(WordPressArticleContentService::class)->resolveEditorHtml($article));
        if ($html === '') {
            return [];
        }

        return $this->enrichWithSeoMediaUrls($article, $this->extractFromHtml($html));
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     */
    public function persistForArticle(SeoArticle $article, array $images): void
    {
        $normalized = $this->normalizeList($images);
        if ($normalized === []) {
            $article->articleMetas()->where('meta_key', self::META_KEY)->delete();

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            [
                'meta_value' => json_encode(
                    $normalized,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            ],
        );
    }

    public function syncFromHtml(SeoArticle $article, string $html): void
    {
        $existing = $this->normalizeList($this->getMetaJson($article));
        $extracted = $this->extractFromHtml($html);
        $merged = $this->mergePreservingWpIds($existing, $extracted);

        $this->persistForArticle($article, $merged);
        $this->attachMediaUsageFromRows($article, $merged);
    }

    /**
     * Ảnh trong bài cho modal chọn ảnh (tab «Trong bài»).
     *
     * @return array{
     *     images: list<array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     error: string|null,
     * }
     */
    public function fetchForMediaPicker(
        SeoArticle $article,
        int $page = 1,
        ?string $search = null,
        int $perPage = 48,
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $rows = $this->resolveForArticle($article);
        $seoMediaByWpId = $this->seoMediaIdMapForArticle($article, $rows);

        $mapped = [];
        foreach ($rows as $index => $row) {
            $fullUrl = $this->resolvePickerFullUrl($row);
            if ($fullUrl === '') {
                continue;
            }

            $thumbUrl = $this->resolvePickerThumbUrl($row, $fullUrl);

            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            $seoMediaId = $wpId > 0 ? (int) ($seoMediaByWpId[$wpId] ?? 0) : 0;
            if ($seoMediaId <= 0) {
                $seoMediaId = $this->resolveSeoMediaIdFromSrc($article, $fullUrl, $seoMediaByWpId);
            }

            $slug = trim((string) ($row['slug'] ?? ''));
            $alt = trim((string) ($row['alt'] ?? ''));

            $mapped[] = [
                'id' => $wpId > 0 ? $wpId : ($seoMediaId > 0 ? $seoMediaId : $index + 1),
                'seo_media_id' => $seoMediaId > 0 ? $seoMediaId : null,
                'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                'slug' => $slug,
                'url' => $fullUrl,
                'thumb_url' => $thumbUrl,
                'alt' => $alt !== '' ? $alt : $slug,
                'sort_at' => $index,
            ];
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $mapped = array_values(array_filter(
                $mapped,
                static function (array $image) use ($needle): bool {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        (string) ($image['slug'] ?? ''),
                        (string) ($image['alt'] ?? ''),
                        (string) ($image['url'] ?? ''),
                    ])));

                    return str_contains($haystack, $needle);
                },
            ));
        }

        $total = count($mapped);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $images = array_slice($mapped, $offset, $perPage);

        return [
            'images' => $images,
            'total' => $total,
            'total_pages' => $totalPages,
            'page' => $page,
            'error' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fromSync
     */
    public function importFromSyncItem(SeoArticle $article, array $fromSync): void
    {
        $images = $fromSync['post_images'] ?? null;
        if (! is_array($images) || $images === []) {
            return;
        }

        $this->persistForArticle($article, $this->normalizeList($images));
    }

    /**
     * Chèn ảnh post_images vào các section <h2> chưa có <img> (dùng khi restore từ WordPress).
     *
     * @param  array<int, array<string, mixed>>  $postImages
     */
    public function injectIntoEmptySections(SeoArticle $article, string $html, array $postImages): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $normalized = $this->normalizeList($postImages);
        if ($normalized === []) {
            return $html;
        }

        $imgInHtml = $this->countImagesWithSrc($html);
        if ($imgInHtml >= count($normalized)) {
            return $html;
        }

        $items = [];
        foreach ($normalized as $row) {
            $url = trim((string) ($row['wp_url'] ?? $row['src'] ?? ''));
            if ($url === '') {
                continue;
            }

            $items[] = [
                'id' => 0,
                'url' => $url,
                'wp_attachment_id' => (int) ($row['wp_attachment_id'] ?? 0),
                'alt' => trim((string) ($row['alt'] ?? '')),
            ];
        }

        if ($items === []) {
            return $html;
        }

        $result = app(ArticleProductGalleryDistributeService::class)
            ->insertImagesToEmptySections($html, $items, $article);

        return $result['inserted'] > 0 ? $result['html'] : $html;
    }

    /**
     * Nguồn editor: raw `post_content` (không dùng `the_content` — tránh &#x entity + markup frontend).
     * Fallback rendered chỉ khi raw rỗng. Decode entity UTF-8 rồi inject post_images nếu thiếu img.
     *
     * @param  array<int, array<string, mixed>>  $postImages
     */
    public function prepareEditorHtmlFromWordPressSources(
        SeoArticle $article,
        string $rawContent,
        string $renderedBody,
        array $postImages = [],
    ): string {
        $html = $this->preferImageRichHtml(trim($rawContent), trim($renderedBody));
        if ($html === '') {
            return '';
        }

        return $this->injectIntoEmptySections($article, $html, $postImages);
    }

    /**
     * Luôn ưu tiên raw. Chỉ lấy rendered khi raw trống (rồi decode entity).
     * Không thay cả bài bằng `the_content` chỉ vì đếm được nhiều thẻ img hơn.
     */
    public function preferImageRichHtml(string $rawContent, string $renderedBody): string
    {
        $html = trim($rawContent) !== '' ? trim($rawContent) : trim($renderedBody);

        return $this->decodeHtmlEntitiesPreservingMarkup($html);
    }

    public function decodeHtmlEntitiesPreservingMarkup(string $html): string
    {
        $html = trim($html);
        if ($html === '' || ! str_contains($html, '&')) {
            return $html;
        }

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function countImagesWithSrc(string $html): int
    {
        if ($html === '') {
            return 0;
        }

        return preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\'][^"\']+["\']/iu', $html) ?: 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    public function normalizeList(array $images): array
    {
        $result = [];

        foreach ($images as $index => $image) {
            if (! is_array($image)) {
                continue;
            }

            $src = trim((string) ($image['src'] ?? ''));
            if ($src === '') {
                continue;
            }

            $wpId = (int) ($image['wp_attachment_id'] ?? $image['wp_id'] ?? 0);
            $wpUrl = trim((string) ($image['wp_url'] ?? $image['wordpress_url'] ?? $image['source_url'] ?? ''));
            $localSrc = trim((string) ($image['local_src'] ?? ''));
            if ($localSrc === '' && WordPressImageUrl::isLocalSeoMediaSrc($src)) {
                $localSrc = $src;
            }
            if ($wpUrl === '' && ! WordPressImageUrl::isLocalSeoMediaSrc($src)) {
                $wpUrl = $src;
            }

            if (! WordPressImageUrl::isLocalSeoMediaSrc($src)) {
                $wpUrl = WordPressImageUrl::toFullSize($wpUrl);
                $src = $wpUrl !== '' ? $wpUrl : WordPressImageUrl::toFullSize($src);
            }

            $slug = trim((string) ($image['slug'] ?? ''));
            if ($slug === '') {
                $slug = WordPressImageUrl::slugFromUrl($src);
            }

            $result[] = [
                'key' => trim((string) ($image['key'] ?? '')) !== ''
                    ? (string) $image['key']
                    : ($wpId > 0 ? 'wp_'.$wpId : 'img_'.$index),
                'block_id' => trim((string) ($image['block_id'] ?? '')),
                'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                'seo_media_id' => (int) ($image['seo_media_id'] ?? $image['seoMediaId'] ?? 0) > 0
                    ? (int) ($image['seo_media_id'] ?? $image['seoMediaId'])
                    : null,
                'src' => $src,
                'slug' => $slug,
                'alt' => (string) ($image['alt'] ?? ''),
                'title' => (string) ($image['title'] ?? ''),
                'caption' => (string) ($image['caption'] ?? ''),
                'align' => (string) ($image['align'] ?? 'none'),
                'wp_url' => $wpUrl,
                'local_src' => $localSrc,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractFromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $items = [];
        $seen = [];

        $this->collectFromGutenbergComments($html, $items, $seen);

        $internalErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $wrapped = '<?xml encoding="utf-8" ?><div>'.$html.'</div>';
        if (@$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            $xpath = new DOMXPath($doc);
            $nodes = $xpath->query('//img');
            if ($nodes !== false) {
                foreach ($nodes as $img) {
                    if (! $img instanceof DOMElement) {
                        continue;
                    }

                    $src = trim((string) $img->getAttribute('src'));
                    if ($src === '') {
                        continue;
                    }

                    $srcKey = $this->normalizeSrcKey($src);
                    if (isset($seen[$srcKey])) {
                        continue;
                    }
                    $seen[$srcKey] = true;

                    $figure = $img->parentNode instanceof DOMElement
                    && strtolower($img->parentNode->tagName) === 'figure'
                        ? $img->parentNode
                        : null;

                    $wpId = $this->resolveAttachmentIdFromImg($img, $src);

                    $items[] = [
                        'key' => $wpId > 0 ? 'wp_'.$wpId : 'src_'.md5($srcKey),
                        'block_id' => '',
                        'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                        'src' => $src,
                        'slug' => $this->slugFromUrl($src),
                        'alt' => trim((string) $img->getAttribute('alt')),
                        'title' => trim((string) $img->getAttribute('title')),
                        'caption' => $figure
                            ? trim((string) $figure->getElementsByTagName('figcaption')->item(0)?->textContent)
                            : '',
                        'align' => $this->alignFromElement($figure ?? $img),
                    ];
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $this->normalizeList($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $extracted
     * @return array<int, array<string, mixed>>
     */
    private function mergePreservingWpIds(array $existing, array $extracted): array
    {
        if ($existing === []) {
            return $extracted;
        }

        $byWpId = [];
        $bySrc = [];
        foreach ($existing as $row) {
            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            if ($wpId > 0) {
                $byWpId[$wpId] = $row;
            }
            $srcKey = $this->normalizeSrcKey((string) ($row['src'] ?? ''));
            if ($srcKey !== '') {
                $bySrc[$srcKey] = $row;
            }
        }

        $merged = [];
        foreach ($extracted as $row) {
            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            $srcKey = $this->normalizeSrcKey((string) ($row['src'] ?? ''));

            $base = null;
            if ($wpId > 0 && isset($byWpId[$wpId])) {
                $base = $byWpId[$wpId];
            } elseif ($srcKey !== '' && isset($bySrc[$srcKey])) {
                $base = $bySrc[$srcKey];
            }

            if ($base !== null) {
                $row['wp_attachment_id'] = $row['wp_attachment_id'] ?? $base['wp_attachment_id'];
                if (trim((string) ($row['slug'] ?? '')) === '' && filled($base['slug'] ?? null)) {
                    $row['slug'] = $base['slug'];
                }
                if (trim((string) ($row['wp_url'] ?? '')) === '' && filled($base['wp_url'] ?? null)) {
                    $row['wp_url'] = $base['wp_url'];
                }
                if (trim((string) ($row['local_src'] ?? '')) === '' && filled($base['local_src'] ?? null)) {
                    if (WordPressImageUrl::isLocalSeoMediaSrc((string) ($row['src'] ?? ''))) {
                        $row['local_src'] = $base['local_src'];
                    }
                }
            }

            $merged[] = $row;
        }

        return $this->normalizeList($merged);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, true>  $seen
     */
    private function collectFromGutenbergComments(string $html, array &$items, array &$seen): void
    {
        if (! preg_match_all('/<!--\s*wp:image\s+(\{.*?\})\s*-->/s', $html, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $json = json_decode((string) ($match[1] ?? ''), true);
            if (! is_array($json)) {
                continue;
            }

            $wpId = (int) ($json['id'] ?? 0);
            if ($wpId <= 0) {
                continue;
            }

            $key = 'wp_'.$wpId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'key' => $key,
                'block_id' => '',
                'wp_attachment_id' => $wpId,
                'src' => '',
                'slug' => '',
                'alt' => '',
                'title' => '',
                'caption' => '',
                'align' => 'none',
            ];
        }
    }

    private function resolveAttachmentIdFromImg(DOMElement $img, string $src): int
    {
        $class = (string) $img->getAttribute('class');
        if (preg_match('/\bwp-image-(\d+)\b/', $class, $m)) {
            return (int) $m[1];
        }

        $dataId = (int) $img->getAttribute('data-id');
        if ($dataId > 0) {
            return $dataId;
        }

        return 0;
    }

    private function alignFromElement(DOMElement $el): string
    {
        $class = (string) $el->getAttribute('class');
        if (str_contains($class, 'alignfull')) {
            return 'full';
        }
        if (str_contains($class, 'alignright')) {
            return 'right';
        }
        if (str_contains($class, 'aligncenter')) {
            return 'center';
        }
        if (str_contains($class, 'alignleft')) {
            return 'left';
        }

        return 'none';
    }

    private function slugFromUrl(string $src): string
    {
        return WordPressImageUrl::slugFromUrl($src);
    }

    private function normalizeSrcKey(string $src): string
    {
        $path = (string) parse_url($src, PHP_URL_PATH);

        return strtolower(rtrim($path, '/'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithSeoMediaUrls(SeoArticle $article, array $images): array
    {
        if ($images === []) {
            return [];
        }

        $seoMediaByKey = $this->seoMediaIdMapForArticle($article, $images);

        $wpIds = array_values(array_unique(array_filter(
            array_map(
                static fn (array $row): int => (int) ($row['wp_attachment_id'] ?? 0),
                $images
            ),
            static fn (int $id): bool => $id > 0,
        )));

        $byWpId = [];
        if ($wpIds !== []) {
            $medias = SeoMedia::query()
                ->where('site_id', (int) $article->site_id)
                ->whereIn('wp_attachment_id', $wpIds)
                ->get();

            foreach ($medias as $media) {
                $wpId = (int) ($media->wp_attachment_id ?? 0);
                if ($wpId <= 0) {
                    continue;
                }

                $wpUrl = trim((string) ($media->getAttribute('wp_url') ?? ''));
                $localSrc = trim((string) $media->publicUrl());

                if ($wpUrl !== '') {
                    $wpUrl = WordPressImageUrl::toFullSize($wpUrl);
                }

                if (! isset($byWpId[$wpId])) {
                    $byWpId[$wpId] = [
                        'wp_url' => $wpUrl,
                        'local_src' => $localSrc,
                    ];
                } else {
                    if ($byWpId[$wpId]['wp_url'] === '' && $wpUrl !== '') {
                        $byWpId[$wpId]['wp_url'] = $wpUrl;
                    }
                    if ($byWpId[$wpId]['local_src'] === '' && $localSrc !== '') {
                        $byWpId[$wpId]['local_src'] = $localSrc;
                    }
                }
            }
        }

        $resolvedIds = [];

        $mapped = array_map(function (array $row) use ($article, $byWpId, $seoMediaByKey, &$resolvedIds): array {
            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            if ($wpId > 0 && isset($byWpId[$wpId])) {
                $fromMedia = $byWpId[$wpId];
                if (trim((string) ($row['wp_url'] ?? '')) === '' && $fromMedia['wp_url'] !== '') {
                    $row['wp_url'] = $fromMedia['wp_url'];
                }
                if (trim((string) ($row['local_src'] ?? '')) === '' && $fromMedia['local_src'] !== '') {
                    $row['local_src'] = $fromMedia['local_src'];
                }

                if (! WordPressImageUrl::isLocalSeoMediaSrc((string) ($row['src'] ?? ''))) {
                    $fullWp = WordPressImageUrl::toFullSize(trim((string) ($row['wp_url'] ?? '')));
                    if ($fullWp !== '') {
                        $row['src'] = $fullWp;
                    } else {
                        $row['src'] = WordPressImageUrl::toFullSize((string) ($row['src'] ?? ''));
                    }
                }
            }

            $fullUrl = $this->resolvePickerFullUrl($row);
            $seoMediaId = (int) ($row['seo_media_id'] ?? 0);
            if ($seoMediaId <= 0 && $wpId > 0) {
                $seoMediaId = (int) ($seoMediaByKey[$wpId] ?? 0);
            }
            if ($seoMediaId <= 0 && $fullUrl !== '') {
                $seoMediaId = $this->resolveSeoMediaIdFromSrc($article, $fullUrl, $seoMediaByKey);
            }

            if ($seoMediaId > 0) {
                $row['seo_media_id'] = $seoMediaId;
                $resolvedIds[$seoMediaId] = true;
            }

            return $row;
        }, $images);

        if ($resolvedIds === []) {
            return $mapped;
        }

        $mediaById = SeoMedia::query()
            ->whereIn('id', array_keys($resolvedIds))
            ->get()
            ->keyBy('id');

        return array_map(function (array $row) use ($mediaById): array {
            $seoMediaId = (int) ($row['seo_media_id'] ?? 0);
            if ($seoMediaId <= 0 || ! $mediaById->has($seoMediaId)) {
                return $row;
            }

            /** @var SeoMedia $media */
            $media = $mediaById->get($seoMediaId);
            $currentUrl = trim((string) $media->publicUrl());
            $currentSlug = trim((string) $media->slug);

            if ($currentSlug !== '') {
                $row['slug'] = $currentSlug;
            }

            if ($currentUrl !== '') {
                $row['local_src'] = $currentUrl;
                if (WordPressImageUrl::isLocalSeoMediaSrc((string) ($row['src'] ?? ''))) {
                    $row['src'] = $currentUrl;
                }
            }

            return $row;
        }, $mapped);
    }

    private function isLocalSeoMediaSrc(string $src): bool
    {
        return WordPressImageUrl::isLocalSeoMediaSrc($src);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolvePickerFullUrl(array $row): string
    {
        $local = trim((string) ($row['local_src'] ?? ''));
        if ($local !== '') {
            return $local;
        }

        $wp = WordPressImageUrl::toFullSize(trim((string) ($row['wp_url'] ?? '')));
        if ($wp !== '' && ! WordPressImageUrl::isLocalSeoMediaSrc($wp)) {
            return $wp;
        }

        $src = WordPressImageUrl::toFullSize(trim((string) ($row['src'] ?? '')));

        return $src !== '' && ! WordPressImageUrl::isLocalSeoMediaSrc($src) ? $src : ($local !== '' ? $local : $src);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolvePickerThumbUrl(array $row, string $fullUrl): string
    {
        $local = trim((string) ($row['local_src'] ?? ''));
        if ($local !== '') {
            return $local;
        }

        foreach ([
            trim((string) ($row['wp_url'] ?? '')),
            trim((string) ($row['src'] ?? '')),
        ] as $candidate) {
            if ($candidate === '' || WordPressImageUrl::isLocalSeoMediaSrc($candidate)) {
                continue;
            }

            if (WordPressImageUrl::isScaledVariant($candidate)) {
                return $candidate;
            }
        }

        return WordPressImageUrl::toPreviewSize($fullUrl);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int> wp_attachment_id => seo_media.id
     */
    private function seoMediaIdMapForArticle(SeoArticle $article, array $rows): array
    {
        $article->loadMissing('site');
        $siteId = (int) ($article->site_id ?? 0);

        $wpIds = array_values(array_unique(array_filter(
            array_map(
                static fn (array $row): int => (int) ($row['wp_attachment_id'] ?? 0),
                $rows,
            ),
            static fn (int $id): bool => $id > 0,
        )));

        $query = SeoMedia::query()->where(function ($q) use ($article, $siteId, $wpIds): void {
            $q->where('article_id', (int) $article->id);

            if ($wpIds !== []) {
                $q->orWhere(function ($sub) use ($siteId, $wpIds): void {
                    $sub->whereIn('wp_attachment_id', $wpIds);
                    if ($siteId > 0) {
                        $sub->where('site_id', $siteId);
                    }
                });
            }
        });

        $map = [];
        foreach ($query->with('mediaMetas')->get(['id', 'slug', 'path']) as $media) {
            $wpId = (int) ($media->wp_attachment_id ?? 0);
            if ($wpId > 0) {
                $map[$wpId] = (int) $media->id;
            }

            $path = strtolower(trim((string) $media->path));
            if ($path !== '') {
                $map['path:'.$path] = (int) $media->id;
            }

            $slug = trim((string) $media->slug);
            if ($slug !== '') {
                $map['slug:'.strtolower($slug)] = (int) $media->id;
            }
        }

        return $map;
    }

    /**
     * @param  array<int|string, int>  $seoMediaByWpId
     */
    private function resolveSeoMediaIdFromSrc(SeoArticle $article, string $url, array $seoMediaByWpId): int
    {
        $relativePath = $this->normalizeStorageRelativePath($url);
        if ($relativePath !== '' && isset($seoMediaByWpId['path:'.$relativePath])) {
            $id = (int) $seoMediaByWpId['path:'.$relativePath];
            $this->touchMediaUsage($article, $id);

            return $id;
        }

        $slug = $this->slugFromUrl($url);
        if ($slug !== '' && isset($seoMediaByWpId['slug:'.strtolower($slug)])) {
            $id = (int) $seoMediaByWpId['slug:'.strtolower($slug)];
            $this->touchMediaUsage($article, $id);

            return $id;
        }

        if (! $this->isLocalSeoMediaSrc($url)) {
            return 0;
        }

        $basename = basename($relativePath !== '' ? $relativePath : $url);
        $media = SeoMedia::query()
            ->where('site_id', (int) $article->site_id)
            ->where(function ($query) use ($article, $basename): void {
                $query->where('article_id', (int) $article->id)
                    ->orWhere('filename', $basename);
            })
            ->where('path', 'like', '%'.addcslashes($basename, '%_\\').'%')
            ->orderByDesc('id')
            ->value('id');

        $id = $media !== null ? (int) $media : 0;
        $this->touchMediaUsage($article, $id);

        return $id;
    }

    private function normalizeStorageRelativePath(string $url): string
    {
        $path = strtolower(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/storage/')) {
            return ltrim(substr($path, strlen('/storage/')), '/');
        }

        if (str_starts_with($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        return ltrim($path, '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function attachMediaUsageFromRows(SeoArticle $article, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $byWpId = $this->seoMediaIdMapForArticle($article, $rows);
        foreach ($rows as $row) {
            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            if ($wpId > 0 && isset($byWpId[$wpId])) {
                $this->touchMediaUsage($article, (int) $byWpId[$wpId]);

                continue;
            }

            $url = $this->resolvePickerFullUrl($row);
            if ($url !== '') {
                $this->resolveSeoMediaIdFromSrc($article, $url, $byWpId);
            }
        }
    }

    private function touchMediaUsage(SeoArticle $article, int $seoMediaId): void
    {
        if ($seoMediaId <= 0) {
            return;
        }

        $media = SeoMedia::query()->find($seoMediaId);
        if (! $media instanceof SeoMedia) {
            return;
        }

        $payload = [];
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId > 0 && (int) ($media->site_id ?? 0) <= 0) {
            $payload['site_id'] = $siteId;
        }

        $articleId = (int) ($article->id ?? 0);
        if ($articleId > 0) {
            $articleIds = SeoMedia::normalizeArticleIds($media->article_id);
            if (! in_array($articleId, $articleIds, true)) {
                $articleIds[] = $articleId;
                $payload['article_id'] = $articleIds;
            }
        }

        if ($payload !== []) {
            $media->update($payload);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMetaJson(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', self::META_KEY)?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
