<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;

/**
 * Shared SQL scopes for Focus Keyword article coverage.
 *
 * Effective focus = same contract as SeoAnalyzerService / SeoAuditScanService:
 * 1) article_meta seo_focus_keyword (non-empty trimmed)
 * 2) OR keyword_meta main_article_id → keywords.phrase
 *
 * Denominator = ArticleSeoInventoryPolicy (SEO Data Health / orphan inventory).
 */
final class FocusKeywordCoverageQuery
{
    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public function applySeoInventoryScope(Builder $query): Builder
    {
        $systemTypes = array_map(
            static fn (string $type): string => strtolower($type),
            ArticleSeoInventoryPolicy::SYSTEM_WP_POST_TYPES,
        );

        return $query
            ->whereDoesntHave('articleMetas', static function (Builder $meta): void {
                $meta->where('meta_key', 'wp_is_term')
                    ->whereRaw("LOWER(TRIM(meta_value)) IN ('1', 'true', 'yes')");
            })
            ->where(function (Builder $typeScope) use ($systemTypes): void {
                $typeScope
                    ->whereDoesntHave('articleMetas', static function (Builder $meta): void {
                        $meta->where('meta_key', 'wp_post_type')
                            ->whereNotNull('meta_value')
                            ->whereRaw("TRIM(meta_value) <> ''");
                    })
                    ->orWhereHas('articleMetas', static function (Builder $meta) use ($systemTypes): void {
                        $meta->where('meta_key', 'wp_post_type')
                            ->whereNotNull('meta_value')
                            ->whereRaw("TRIM(meta_value) <> ''")
                            ->whereRaw('LOWER(TRIM(meta_value)) NOT LIKE ?', ['wp\\_%']);
                        if ($systemTypes !== []) {
                            $meta->whereRaw(
                                'LOWER(TRIM(meta_value)) NOT IN ('.implode(',', array_fill(0, count($systemTypes), '?')).')',
                                $systemTypes,
                            );
                        }
                    });
            });
    }

    /**
     * Articles that have an effective focus keyword (meta OR main_article_id phrase).
     *
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public function applyHasEffectiveFocusScope(Builder $query): Builder
    {
        return $query->where(function (Builder $hasKeyword): void {
            $hasKeyword
                ->whereHas('articleMetas', static function (Builder $meta): void {
                    $meta->where('meta_key', 'seo_focus_keyword')
                        ->whereNotNull('meta_value')
                        ->where('meta_value', '!=', '')
                        ->whereRaw("TRIM(meta_value) <> ''");
                })
                ->orWhereExists(static function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('keyword_meta')
                        ->join('keywords', 'keywords.id', '=', 'keyword_meta.keyword_id')
                        ->whereColumn('keyword_meta.meta_value', 'articles.id')
                        ->where('keyword_meta.meta_key', KeywordMetaKey::MainArticleId->value)
                        ->whereNotNull('keywords.phrase')
                        ->where('keywords.phrase', '!=', '')
                        ->whereRaw("TRIM(keywords.phrase) <> ''");
                });
        });
    }

    /**
     * Inverse of {@see applyHasEffectiveFocusScope()}.
     *
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public function applyMissingFocusScope(Builder $query): Builder
    {
        return $query->whereNot(function (Builder $hasKeyword): void {
            $this->applyHasEffectiveFocusScope($hasKeyword);
        });
    }

    /**
     * Eligible SEO inventory for a site.
     *
     * @return Builder<SeoArticle>
     */
    public function eligibleQuery(int $siteId): Builder
    {
        return $this->applySeoInventoryScope(
            SeoArticle::query()->where('articles.site_id', $siteId),
        );
    }

    /**
     * Eligible articles missing effective focus keyword.
     *
     * @return Builder<SeoArticle>
     */
    public function missingEligibleQuery(int $siteId): Builder
    {
        return $this->applyMissingFocusScope($this->eligibleQuery($siteId));
    }

    /**
     * Eligible articles with effective focus keyword.
     *
     * @return Builder<SeoArticle>
     */
    public function coveredEligibleQuery(int $siteId): Builder
    {
        return $this->applyHasEffectiveFocusScope($this->eligibleQuery($siteId));
    }
}
