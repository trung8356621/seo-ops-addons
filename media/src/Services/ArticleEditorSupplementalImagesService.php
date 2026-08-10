<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;

/**
 * Ảnh ngoài block editor (ảnh đại diện + album sản phẩm) cho tab Hình ảnh.
 *
 * Extracted from EditArticle (Phase 2 perf) so it can be reused both by the
 * Livewire eager meta payload and the lazy `/editor/meta` HTTP endpoint —
 * takes featured URL / product gallery as plain arguments instead of reading
 * Livewire public props.
 */
final class ArticleEditorSupplementalImagesService
{
    /**
     * @param  list<array{id?: int, url?: string}>  $productGallery
     * @return list<array<string, mixed>>
     */
    public function forArticle(SeoArticle $article, string $featuredImageUrl, array $productGallery): array
    {
        $article->loadMissing('articleMetas');

        $rows = [];
        $seen = [];

        $append = static function (array &$rows, array &$seen, array $row): void {
            $src = trim((string) ($row['src'] ?? ''));
            if ($src === '') {
                return;
            }

            $wpId = (int) ($row['wp_attachment_id'] ?? 0);
            $seoId = (int) ($row['seo_media_id'] ?? 0);
            $identity = $wpId > 0
                ? 'wp:'.$wpId
                : ($seoId > 0 ? 'seo:'.$seoId : 'src:'.mb_strtolower($src));
            if (isset($seen[$identity])) {
                return;
            }
            $seen[$identity] = true;
            $rows[] = $row;
        };

        $featuredUrl = trim($featuredImageUrl);
        $featuredId = (int) ($article->articleMetas->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($featuredUrl !== '') {
            $featuredRefs = $this->resolveRefIds($article, $featuredUrl, $featuredId);
            $append($rows, $seen, [
                'key' => $featuredId > 0 ? 'featured_wp_'.$featuredId : 'featured_src_'.md5($featuredUrl),
                'block_id' => '',
                'wp_attachment_id' => $featuredRefs['wp_attachment_id'],
                'seo_media_id' => $featuredRefs['seo_media_id'],
                'src' => $featuredUrl,
                'wp_url' => str_contains($featuredUrl, '/storage/uploads/seo_media/') ? '' : $featuredUrl,
                'local_src' => str_contains($featuredUrl, '/storage/uploads/seo_media/') ? $featuredUrl : '',
                'slug' => trim((string) pathinfo(parse_url($featuredUrl, PHP_URL_PATH) ?? $featuredUrl, PATHINFO_FILENAME)),
                'alt' => '',
                'title' => '',
                'caption' => '',
                'align' => 'none',
                'origin' => 'featured',
                'origin_label' => 'Anh dai dien',
            ]);
        }

        foreach ($productGallery as $idx => $item) {
            $url = trim((string) ($item['url'] ?? ''));
            $id = (int) ($item['id'] ?? 0);
            if ($url === '') {
                continue;
            }

            $refs = $this->resolveRefIds($article, $url, $id);
            $append($rows, $seen, [
                'key' => $id > 0 ? 'gallery_wp_'.$id : 'gallery_src_'.md5($url),
                'block_id' => '',
                'wp_attachment_id' => $refs['wp_attachment_id'],
                'seo_media_id' => $refs['seo_media_id'],
                'src' => $url,
                'wp_url' => str_contains($url, '/storage/uploads/seo_media/') ? '' : $url,
                'local_src' => str_contains($url, '/storage/uploads/seo_media/') ? $url : '',
                'slug' => trim((string) pathinfo(parse_url($url, PHP_URL_PATH) ?? $url, PATHINFO_FILENAME)),
                'alt' => '',
                'title' => '',
                'caption' => '',
                'align' => 'none',
                'origin' => 'gallery',
                'origin_label' => $idx === 0 ? 'Anh dai dien' : 'Album san pham',
            ]);
        }

        return $rows;
    }

    /**
     * @return array{wp_attachment_id: int|null, seo_media_id: int|null}
     */
    private function resolveRefIds(SeoArticle $article, string $url, int $refId): array
    {
        $url = trim($url);
        $refId = max(0, $refId);
        $isLocal = str_contains($url, '/storage/uploads/seo_media/');

        if ($isLocal) {
            $seoId = $refId;
            if ($seoId <= 0) {
                $seoId = app(ArticleMediaLocalService::class)
                    ->resolveLocalRefIdFromImageUrl((int) ($article->site_id ?? 0), $url);
            }

            return [
                'wp_attachment_id' => null,
                'seo_media_id' => $seoId > 0 ? $seoId : null,
            ];
        }

        return [
            'wp_attachment_id' => $refId > 0 ? $refId : null,
            'seo_media_id' => null,
        ];
    }
}
