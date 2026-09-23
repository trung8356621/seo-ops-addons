<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Support;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;

/**
 * WordPress identity for Site Sync: site_id + object namespace + wp id.
 *
 * Content IDs (post/page/product) and term IDs (category/tag/product_cat/…) are
 * independent WP namespaces and may share the same numeric id. Lookups MUST
 * scope by {@see SiteSyncV3Schema::OBJECT_NAMESPACE_CONTENT} vs
 * {@see SiteSyncV3Schema::OBJECT_NAMESPACE_TERM} (stored as article_meta.wp_is_term).
 */
final class SiteSyncWpIdentity
{
    /**
     * @param  Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function scopeNamespace(Builder $query, bool $isTerm): Builder
    {
        return $isTerm
            ? ArticleContentClassification::scopeIsTerm($query, true)
            : ArticleContentClassification::scopeNonTerm($query);
    }

    /**
     * @param  Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function scopeNamespaceName(Builder $query, string $namespace): Builder
    {
        return self::scopeNamespace($query, $namespace === SiteSyncV3Schema::OBJECT_NAMESPACE_TERM);
    }

    public static function find(int $siteId, int $wpId, bool $isTerm): ?SeoArticle
    {
        if ($wpId <= 0 || $siteId <= 0) {
            return null;
        }

        $query = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereWpPostId($wpId)
            ->with(['articleMetas', 'wordpressLink']);

        self::scopeNamespace($query, $isTerm);

        $article = $query->first();

        return $article instanceof SeoArticle ? $article : null;
    }

    /**
     * Content-namespace only — never fall back to a term with the same numeric id.
     */
    public static function findContent(int $siteId, int $wpId): ?SeoArticle
    {
        return self::find($siteId, $wpId, false);
    }

    /**
     * @param  list<int>  $wpIds
     * @return array<int, SeoArticle> wp_id => article (namespace-scoped)
     */
    public static function preloadMap(int $siteId, array $wpIds, bool $isTerm): array
    {
        $unique = [];
        foreach ($wpIds as $wpId) {
            $id = (int) $wpId;
            if ($id > 0) {
                $unique[$id] = $id;
            }
        }
        if ($unique === []) {
            return [];
        }

        $query = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereWpPostIdIn(array_values($unique))
            ->with(['articleMetas', 'wordpressLink']);

        self::scopeNamespace($query, $isTerm);

        $map = [];
        foreach ($query->get() as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $linkWpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($linkWpId > 0) {
                $map[$linkWpId] = $article;
            }
        }

        return $map;
    }

    public static function namespaceFromIsTerm(bool $isTerm): string
    {
        return $isTerm
            ? SiteSyncV3Schema::OBJECT_NAMESPACE_TERM
            : SiteSyncV3Schema::OBJECT_NAMESPACE_CONTENT;
    }
}
