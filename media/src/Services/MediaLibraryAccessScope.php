<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class MediaLibraryAccessScope
{
    public function __construct(
        private readonly MediaLibraryArticleResolver $articleResolver,
    ) {}

    /**
     * Staff content_manager: chỉ xem media của bài viết thuộc quyền sở hữu.
     *
     * @return list<int>|null null = không giới hạn theo bài viết
     */
    public function restrictedArticleIdsForSite(int $siteId): ?array
    {
        if (! SeoAccessControl::isContentManager()) {
            return null;
        }

        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return [];
        }

        return ArticleResource::applyArticleAccessScopes(
            SeoArticle::query()->where('site_id', $siteId)->select('id'),
            includeGlobalSiteScope: false,
            includeReviewScope: false,
            includeContentManagerOwnershipScope: true,
        )
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>|null null = không giới hạn
     */
    public function restrictedWordPressAttachmentIds(int $siteId, ?array $articleIds = null): ?array
    {
        $articleIds ??= $this->restrictedArticleIdsForSite($siteId);
        if ($articleIds === null) {
            return null;
        }

        if ($articleIds === []) {
            return [];
        }

        return $this->articleResolver->wordpressAttachmentIdsForArticles($siteId, $articleIds);
    }

    /**
     * Modal chọn ảnh: mặc định giới hạn theo bài staff; khi search thì mở rộng toàn thư viện WP của domain.
     *
     * @return list<int>|null
     */
    public function pickerWordPressAttachmentRestrictions(int $siteId, ?string $search): ?array
    {
        if (trim((string) $search) !== '') {
            return null;
        }

        return $this->restrictedWordPressAttachmentIds($siteId);
    }
}
