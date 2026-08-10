<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;

/**
 * Thin album adapter for Mode 2 coordinator (keeps selection persist path centralized).
 */
final class ArticleMediaLocalServiceBridge
{
    public function __construct(
        private readonly ArticleMediaLocalService $album,
    ) {}

    /**
     * @param  list<int>  $mediaIds
     */
    public function replaceAlbum(SeoArticle $article, array $mediaIds): void
    {
        $items = [];
        foreach ($mediaIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $media = SeoMedia::query()->find($id);
            if (! $media instanceof SeoMedia) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'url' => $media->publicUrl(),
            ];
        }

        if ($items === []) {
            return;
        }

        $this->album->replaceProductAlbumLocal($article, $items);
    }
}
