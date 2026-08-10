<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Support\ProductGallery;

final class ProductGalleryParentChildFeature
{
    public static function enabled(): bool
    {
        try {
            return (bool) config('seo-content-ai.product_gallery.parent_child.enabled', true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<int>
     */
    public static function canaryArticleIds(): array
    {
        try {
            $ids = config('seo-content-ai.product_gallery.parent_child.canary_article_ids', []);
        } catch (\Throwable) {
            $ids = [];
        }

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0));
    }

    public static function allowsArticle(int $articleId): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $allow = self::canaryArticleIds();
        if ($allow === []) {
            // Enabled with empty allowlist = all articles (ops must opt-in carefully).
            return true;
        }

        if (in_array($articleId, $allow, true)) {
            return true;
        }

        try {
            $autoAllow = (bool) config('seo-content-ai.product_gallery.canary.auto_allow_fixture_articles', true);
        } catch (\Throwable) {
            $autoAllow = true;
        }

        if (! $autoAllow || $articleId <= 0) {
            return false;
        }

        try {
            $article = \Omnichannel\Addons\Content\Models\SeoArticle::query()->find($articleId);
            if ($article instanceof \Omnichannel\Addons\Content\Models\SeoArticle) {
                return ProductGalleryCanaryAccess::isCanaryArticle($article);
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
