<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

final class ArticlePostTypeResolver
{
    public static function resolve(SeoArticle $article): string
    {
        $article->loadMissing('articleMetas');

        $wpEntity = strtolower(trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'wp_entity')?->meta_value ?? ''
        )));
        if ($wpEntity === 'term') {
            $taxonomy = app(\Omnichannel\Addons\WordPress\Services\WordPressArticleContentService::class)
                ->resolveWpTaxonomy($article);

            return match ($taxonomy) {
                'product_cat' => SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY,
                'category' => SeoProjectTask::POST_TYPE_CATEGORY,
                default => SeoProjectTask::normalizePostType((string) ($article->type ?? '')),
            };
        }

        // Laravel articles.type là nguồn sự thật cho editor / rewrite.
        $type = trim((string) ($article->type ?? ''));
        if ($type !== '') {
            return SeoProjectTask::normalizePostType($type);
        }

        return SeoProjectTask::POST_TYPE_ARTICLE;
    }
}
