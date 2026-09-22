<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Seo\Services\SeoAnalyticsScopeSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Shared analytics/reporting article eligibility for Statistics + SEO Audit defaults.
 *
 * When {@see SeoAnalyticsScopeSettingsService::excludePagesFromStatistics()} is true,
 * excludes articles classified as {@see ContentType::Page} via META_CONTENT_TYPE
 * (same approach as SEO Audit). Never filters by SeoProjectTask post_type column.
 *
 * Structural scopes (MCP, sync, publish, editor) must NOT call this.
 */
final class SeoAnalyticsArticleScope
{
    public function __construct(
        private readonly SeoAnalyticsScopeSettingsService $settings,
    ) {}

    public function excludePagesFromStatistics(): bool
    {
        return $this->settings->excludePagesFromStatistics();
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public function applyToArticleQuery(Builder $query): Builder
    {
        if (! $this->settings->excludePagesFromStatistics()) {
            return $query;
        }

        return $query->whereDoesntHave('articleMetas', static function (Builder $metaQ): void {
            $metaQ->where('meta_key', ArticleContentClassification::META_CONTENT_TYPE)
                ->where('meta_value', ContentType::Page->value);
        });
    }

    /**
     * Constrain a query that has an articles.id (or alias) column — e.g. joined article rows.
     *
     * @param  Builder<*>|QueryBuilder  $query
     */
    public function applyToJoinedArticleId(Builder|QueryBuilder $query, string $articleIdColumn = 'articles.id'): void
    {
        if (! $this->settings->excludePagesFromStatistics()) {
            return;
        }

        $query->whereNotExists(function ($sub) use ($articleIdColumn): void {
            $sub->selectRaw('1')
                ->from('article_meta')
                ->whereColumn('article_meta.article_id', $articleIdColumn)
                ->where('article_meta.meta_key', ArticleContentClassification::META_CONTENT_TYPE)
                ->where('article_meta.meta_value', ContentType::Page->value);
        });
    }

    /**
     * Task/archive rows: exclude when linked article is Page; keep null article_id rows.
     *
     * @param  Builder<*>|QueryBuilder  $query
     */
    public function applyToTaskArticleId(Builder|QueryBuilder $query, string $articleIdColumn = 't.article_id'): void
    {
        if (! $this->settings->excludePagesFromStatistics()) {
            return;
        }

        $query->where(function ($outer) use ($articleIdColumn): void {
            $outer
                ->whereNull($articleIdColumn)
                ->orWhereNotExists(function ($sub) use ($articleIdColumn): void {
                    $sub->selectRaw('1')
                        ->from('article_meta')
                        ->whereColumn('article_meta.article_id', $articleIdColumn)
                        ->where('article_meta.meta_key', ArticleContentClassification::META_CONTENT_TYPE)
                        ->where('article_meta.meta_value', ContentType::Page->value);
                });
        });
    }
}
