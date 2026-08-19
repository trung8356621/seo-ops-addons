<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Illuminate\Database\Eloquent\Builder;

/**
 * Semantic scope for "eligible MCP SEO content" (target entities).
 *
 * Source of truth: `article_meta.wp_post_type`.
 * Default eligible: wp_post_type IN ('post', 'product').
 * Excluded: 'page', custom CPTs, and any non-eligible type.
 *
 * Legacy fallback (no wp_post_type meta): articles.type=product → product,
 * articles.type=article/null/empty → post (eligible).
 *
 * Notes:
 * - "sourceArticle" in link maps is NOT constrained by this scope; only the
 *   "target" content entity is filtered out.
 * - Categories/product categories are treated as summary entities elsewhere.
 */
final class McpEligibleContentScope
{
    public const META_KEY = 'wp_post_type';

    /** @var list<string> */
    public const ELIGIBLE_WP_POST_TYPES = ['post', 'product'];

    /**
     * Apply eligible SEO-content target constraints to an articles query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function applyToSeoArticleTarget(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', 'trash')
            ->whereNull('deleted_at')
            ->where(static function (Builder $q): void {
                $q->whereIn('type', ['article', 'product'])
                    ->orWhereNull('type')
                    ->orWhere('type', '');
            })
            ->where(static function (Builder $q): void {
                // Explicit wp_post_type meta must be in eligible list
                $q->whereHas('articleMetas', static function (Builder $metaQ): void {
                    $metaQ->where('meta_key', self::META_KEY)
                        ->whereIn('meta_value', self::ELIGIBLE_WP_POST_TYPES);
                })
                // Legacy: no wp_post_type meta → fallback to eligible (old records pre-migration)
                ->orWhereDoesntHave('articleMetas', static function (Builder $metaQ): void {
                    $metaQ->where('meta_key', self::META_KEY);
                });
            });
    }
}
