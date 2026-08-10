<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class ArticlePostTypeResolverTest extends TestCase
{
    public function test_local_article_type_wins_over_stale_wp_product_meta(): void
    {
        $article = new SeoArticle;
        $article->type = SeoProjectTask::POST_TYPE_ARTICLE;
        $article->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'product',
            ]),
        ]));

        self::assertSame(
            SeoProjectTask::POST_TYPE_ARTICLE,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_local_product_type_is_respected(): void
    {
        $article = new SeoArticle;
        $article->type = SeoProjectTask::POST_TYPE_PRODUCT;
        $article->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'post',
            ]),
        ]));

        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_empty_local_type_defaults_to_article_without_wp_post_type_meta(): void
    {
        $article = new SeoArticle;
        $article->type = '';
        $article->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'product',
            ]),
        ]));

        // Task 7 §N: wp_post_type meta no longer consulted — articles.type is SoT.
        self::assertSame(
            SeoProjectTask::POST_TYPE_ARTICLE,
            ArticlePostTypeResolver::resolve($article),
        );
    }
}
