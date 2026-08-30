<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Bridges Article core content_type → legacy SeoProjectTask post-type vocabulary
 * still used by Content Projects / some editor widgets.
 *
 * Article business behavior MUST use ArticleContentClassification / ContentType.
 * This resolver only provides compatibility labels (article|product|category|product_category).
 */
final class ArticlePostTypeResolver
{
    public static function resolve(SeoArticle $article): string
    {
        $classification = ArticleContentClassification::for($article);

        if ($classification->isTerm()) {
            return $classification->contentType() === ContentType::Product
                ? SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY
                : SeoProjectTask::POST_TYPE_CATEGORY;
        }

        return match ($classification->contentType()) {
            ContentType::Product => SeoProjectTask::POST_TYPE_PRODUCT,
            // Page shares editorial surface with posts in task vocabulary;
            // page-specific behavior uses ContentType::Page / content_type meta.
            ContentType::Page, ContentType::Post => SeoProjectTask::POST_TYPE_ARTICLE,
        };
    }

    public static function contentType(SeoArticle $article): ContentType
    {
        return ArticleContentClassification::for($article)->contentType();
    }

    public static function isTerm(SeoArticle $article): bool
    {
        return ArticleContentClassification::for($article)->isTerm();
    }

    public static function isPage(SeoArticle $article): bool
    {
        return ArticleContentClassification::for($article)->equals(ContentType::Page);
    }

    public static function isProduct(SeoArticle $article): bool
    {
        return ArticleContentClassification::for($article)->equals(ContentType::Product);
    }
}
