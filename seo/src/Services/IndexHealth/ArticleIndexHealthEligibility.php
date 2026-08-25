<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\IndexHealth;

use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Eligibility: observed WP publish + valid public URL.
 */
final class ArticleIndexHealthEligibility
{
    public function __construct(
        private readonly ArticleIndexCanonicalUrlResolver $urls = new ArticleIndexCanonicalUrlResolver,
    ) {}

    public function isEligible(SeoArticle $article): bool
    {
        $article->loadMissing('wordpressLink');

        $status = strtolower(trim((string) ($article->wordpressLink?->observed_post_status ?? '')));
        if ($status !== 'publish') {
            return false;
        }

        return $this->urls->resolve($article) !== null;
    }

    /**
     * Scope builder for published observed articles (caller adds site/access filters).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticle>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Omnichannel\Addons\Content\Models\SeoArticle>
     */
    public function scopeEligible($query)
    {
        return $query->whereHas('wordpressLink', static function ($link): void {
            $link->where('observed_post_status', 'publish');
        })->where(function ($q): void {
            $q->whereHas('wordpressLink', static function ($link): void {
                $link->whereNotNull('observed_permalink')
                    ->where('observed_permalink', '!=', '');
            })->orWhereHas('articleMetas', static function ($meta): void {
                $meta->where('meta_key', 'wp_permalink')
                    ->whereNotNull('meta_value')
                    ->where('meta_value', '!=', '');
            });
        });
    }
}
