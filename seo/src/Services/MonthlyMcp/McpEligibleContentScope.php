<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Semantic scope for "eligible MCP SEO content" (target entities).
 *
 * Source of truth: canonical classification meta.
 * Eligible: content_type IN ('post', 'product') AND wp_is_term = 0.
 * Excluded: content_type = 'page' (including CPTs mapped to page) and taxonomy terms.
 *
 * Notes:
 * - Raw CPT slugs (wp_post_type) are integration-only and MUST NOT drive eligibility;
 *   connectors map them to content_type before Article core consumes them.
 * - "sourceArticle" in link maps is NOT constrained by this scope; only the
 *   "target" content entity is filtered out.
 * - Categories/product categories are treated as summary entities elsewhere.
 */
final class McpEligibleContentScope
{
    /** @deprecated Legacy-fallback key for rows the content_type backfill has not reached. */
    public const META_KEY = ArticleContentClassification::META_WP_POST_TYPE;

    /** @var list<string> */
    public const ELIGIBLE_CONTENT_TYPES = [
        ContentType::Post->value,
        ContentType::Product->value,
    ];

    /**
     * @deprecated Use {@see self::ELIGIBLE_CONTENT_TYPES}.
     *
     * @var list<string>
     */
    public const ELIGIBLE_WP_POST_TYPES = self::ELIGIBLE_CONTENT_TYPES;

    /**
     * Apply eligible SEO-content target constraints to an articles query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public static function applyToSeoArticleTarget(Builder $query): Builder
    {
        $query
            ->where('status', '!=', 'trash')
            ->whereNull('deleted_at')
            ->where(static function (Builder $eligible): void {
                $eligible
                    ->whereHas('articleMetas', static function (Builder $metaQ): void {
                        $metaQ->where('meta_key', ArticleContentClassification::META_CONTENT_TYPE)
                            ->whereIn('meta_value', self::ELIGIBLE_CONTENT_TYPES);
                    })
                    // Legacy rows the content_type backfill has not reached yet.
                    ->orWhere(static function (Builder $legacy): void {
                        $legacy
                            ->whereDoesntHave('articleMetas', static function (Builder $metaQ): void {
                                $metaQ->where('meta_key', ArticleContentClassification::META_CONTENT_TYPE);
                            })
                            ->whereDoesntHave('articleMetas', static function (Builder $metaQ): void {
                                $metaQ->where('meta_key', self::META_KEY)
                                    ->whereNotIn('meta_value', self::ELIGIBLE_CONTENT_TYPES);
                            });
                    });
            });

        return ArticleContentClassification::scopeNonTerm($query);
    }
}
