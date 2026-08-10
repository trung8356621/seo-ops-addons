<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;

final class MediaLibraryArticleResolver
{
    /**
     * wp_attachment_id => article_id
     *
     * @return array<int, int>
     */
    public function attachmentToArticleMap(int $siteId): array
    {
        $map = [];

        $articleIds = SeoArticle::query()
            ->where('site_id', $siteId)
            ->pluck('id');

        if ($articleIds->isEmpty()) {
            return $map;
        }

        $metas = ArticleMeta::query()
            ->whereIn('article_id', $articleIds)
            ->whereIn('meta_key', [
                ArticlePostImagesService::META_KEY,
                ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID,
            ])
            ->get(['article_id', 'meta_key', 'meta_value']);

        foreach ($metas as $meta) {
            $articleId = (int) $meta->article_id;
            if ($articleId <= 0) {
                continue;
            }

            if ($meta->meta_key === ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID) {
                $attachmentId = (int) $meta->meta_value;
                if ($attachmentId > 0) {
                    $map[$attachmentId] = $articleId;
                }

                continue;
            }

            $decoded = json_decode((string) $meta->meta_value, true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $attachmentId = (int) ($row['wp_attachment_id'] ?? $row['wp_id'] ?? 0);
                if ($attachmentId > 0 && ! isset($map[$attachmentId])) {
                    $map[$attachmentId] = $articleId;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $articleIds
     * @return list<int>
     */
    public function wordpressAttachmentIdsForArticles(int $siteId, array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $allowedArticles = array_flip($articleIds);
        $map = $this->attachmentToArticleMap($siteId);
        $attachmentIds = [];

        foreach ($map as $wpAttachmentId => $articleId) {
            if (isset($allowedArticles[(int) $articleId])) {
                $attachmentIds[] = (int) $wpAttachmentId;
            }
        }

        return array_values(array_unique($attachmentIds));
    }

    public function editUrlForArticle(?int $articleId): ?string
    {
        if ($articleId === null || $articleId <= 0) {
            return null;
        }

        if (! SeoArticle::query()->whereKey($articleId)->exists()) {
            return null;
        }

        return ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: true, panel: ArticleResource::panelId());
    }

    /**
     * @param  list<array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    public function enrichImages(int $siteId, array $images): array
    {
        $map = $this->attachmentToArticleMap($siteId);

        foreach ($images as $index => $image) {
            $articleId = null;

            $kind = (string) ($image['kind'] ?? '');
            if ($kind === 'local' || $kind === 'generated') {
                $articleId = $this->resolveLocalArticleId($siteId, $image);
            } else {
                $wpId = (int) ($image['wp_attachment_id'] ?? $image['id'] ?? 0);
                if ($wpId > 0 && isset($map[$wpId])) {
                    $articleId = $map[$wpId];
                }
            }

            $images[$index]['article_id'] = $articleId > 0 ? $articleId : null;
            $images[$index]['article_edit_url'] = $this->editUrlForArticle(
                $articleId > 0 ? $articleId : null,
            );
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function resolveLocalArticleId(int $siteId, array $image): ?int
    {
        $articleId = isset($image['article_id']) ? (int) $image['article_id'] : null;
        if ($articleId !== null && $articleId > 0) {
            return $articleId;
        }

        $mediaId = (int) ($image['seo_media_id'] ?? $image['id'] ?? 0);
        if ($mediaId <= 0) {
            return null;
        }

        $media = SeoMedia::query()
            ->where('site_id', $siteId)
            ->whereKey($mediaId)
            ->first();

        return $media?->firstArticleId();
    }
}
