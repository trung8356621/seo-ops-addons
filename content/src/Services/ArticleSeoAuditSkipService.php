<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global SEO Audit skip — article_meta.skip_seo_audit = 1.
 * Agent / Content Project commands reuse this; do not invent a second flag.
 */
final class ArticleSeoAuditSkipService
{
    public const META_KEY = ArticleResource::META_SKIP_SEO_AUDIT;

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public function applyExcludeScope(Builder $query): Builder
    {
        return ArticleResource::applyExcludeSkipSeoAuditScope($query);
    }

    public function isSkipped(SeoArticle $article): bool
    {
        return ArticleResource::articleIsSkipSeoAudit($article);
    }

    /**
     * @return bool true when article is skipped after the call
     */
    public function skip(SeoArticle $article): bool
    {
        if ($this->isSkipped($article)) {
            return true;
        }

        return ArticleResource::toggleSkipSeoAudit($article);
    }

    /**
     * @return bool true when article is still skipped after the call
     */
    public function unskip(SeoArticle $article): bool
    {
        if (! $this->isSkipped($article)) {
            return false;
        }

        return ArticleResource::toggleSkipSeoAudit($article);
    }

    /**
     * @param  list<int>  $articleIds
     * @return array{skipped:int, already_skipped:int, missing:int}
     */
    public function skipMany(array $articleIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $articleIds),
            static fn (int $id): bool => $id > 0,
        )));

        $skipped = 0;
        $already = 0;
        $missing = 0;

        foreach ($ids as $id) {
            $article = SeoArticle::query()->find($id);
            if (! $article instanceof SeoArticle) {
                $missing++;

                continue;
            }

            if ($this->isSkipped($article)) {
                $already++;

                continue;
            }

            $this->skip($article);
            $skipped++;
        }

        return [
            'skipped' => $skipped,
            'already_skipped' => $already,
            'missing' => $missing,
        ];
    }
}
