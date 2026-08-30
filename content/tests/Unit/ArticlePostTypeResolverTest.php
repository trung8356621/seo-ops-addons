<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class ArticlePostTypeResolverTest extends TestCase
{
    public function test_content_type_post_resolves_to_article_compat_label(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'product', // raw must not override content_type
        ]);

        self::assertSame(
            SeoProjectTask::POST_TYPE_ARTICLE,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_content_type_product_is_respected(): void
    {
        $article = $this->article([
            'content_type' => 'product',
            'wp_is_term' => '0',
            'wp_post_type' => 'post',
        ]);

        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_legacy_type_still_inferred_when_content_type_missing(): void
    {
        $article = new SeoArticle;
        $article->type = '';
        $article->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'product',
            ]),
        ]));

        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_page_content_type_keeps_article_compat_but_is_page(): void
    {
        $article = $this->article([
            'content_type' => 'page',
            'wp_is_term' => '0',
            'wp_post_type' => 'page',
        ]);

        self::assertSame(SeoProjectTask::POST_TYPE_ARTICLE, ArticlePostTypeResolver::resolve($article));
        self::assertTrue(ArticlePostTypeResolver::isPage($article));
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function article(array $metas): SeoArticle
    {
        $article = new SeoArticle;
        $article->type = '';
        $collection = new Collection;
        foreach ($metas as $key => $value) {
            $collection->push(new ArticleMeta([
                'meta_key' => $key,
                'meta_value' => $value,
            ]));
        }
        $article->setRelation('articleMetas', $collection);

        return $article;
    }
}
