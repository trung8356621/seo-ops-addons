<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;

/**
 * SEO-owned writer for seo_article_profiles + temporary articles.* projection.
 */
final class SeoArticleProfileWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(SeoArticle $article, array $attributes): void
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0) {
            return;
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')) {
            SeoArticleProfile::query()->updateOrCreate(
                ['article_id' => $articleId],
                $attributes,
            );
        }
    }
}
