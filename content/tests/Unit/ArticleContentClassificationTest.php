<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Content\Support\NativeContentTypeMapper;
use Omnichannel\Addons\Content\Support\PublishingTaxonomyResolver;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Tests\TestCase;

final class ArticleContentClassificationTest extends TestCase
{
    public function test_wp_post_maps_to_content_type_post(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'post',
            'wp_entity' => 'post',
        ]);

        self::assertSame(ContentType::Post, $resolved['content_type']);
        self::assertFalse($resolved['wp_is_term']);
        self::assertSame('post', $resolved['wp_post_type']);
        self::assertNull($resolved['parent_id']);
    }

    public function test_wp_page_maps_to_content_type_page(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'page',
            'wp_is_term' => false,
        ]);

        self::assertSame(ContentType::Page, $resolved['content_type']);
        self::assertFalse($resolved['wp_is_term']);
        self::assertNull($resolved['parent_id']);
    }

    public function test_woo_product_maps_to_content_type_product(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'product',
            'wp_entity' => 'post',
        ]);

        self::assertSame(ContentType::Product, $resolved['content_type']);
        self::assertFalse($resolved['wp_is_term']);
    }

    public function test_custom_cpt_machine_maps_to_product(): void
    {
        self::assertSame(
            ContentType::Product,
            NativeContentTypeMapper::map('machine'),
        );
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'machine',
            'wp_is_term' => false,
        ]);
        self::assertSame(ContentType::Product, $resolved['content_type']);
        self::assertSame('machine', $resolved['wp_post_type']);
    }

    public function test_custom_cpt_landing_page_maps_to_page(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'landing_page',
        ]);
        self::assertSame(ContentType::Page, $resolved['content_type']);
        self::assertSame('landing_page', $resolved['wp_post_type']);
    }

    public function test_custom_cpt_portfolio_maps_to_post(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'portfolio',
        ]);
        self::assertSame(ContentType::Post, $resolved['content_type']);
    }

    public function test_category_term_maps_to_post_with_wp_is_term(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'category',
            'wp_entity' => 'term',
            'parent_id' => 0,
        ]);

        self::assertSame(ContentType::Post, $resolved['content_type']);
        self::assertTrue($resolved['wp_is_term']);
        self::assertSame('category', $resolved['wp_post_type']);
        self::assertSame(0, $resolved['parent_id']);
    }

    public function test_product_cat_term_maps_to_product(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'product_cat',
            'wp_is_term' => true,
            'parent_id' => 0,
        ]);

        self::assertSame(ContentType::Product, $resolved['content_type']);
        self::assertTrue($resolved['wp_is_term']);
    }

    public function test_child_taxonomy_preserves_root_zero_and_pending_parent(): void
    {
        $root = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'product_cat',
            'wp_is_term' => true,
            'parent_term_id' => 0,
        ]);
        self::assertSame(0, $root['parent_id']);

        $child = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'product_cat',
            'wp_is_term' => true,
            'parent_term_id' => 42,
        ]);
        // WP parent id > 0 is resolved to local article id later; not stored as WP id on articles.parent_id.
        self::assertNull($child['parent_id']);
    }

    public function test_non_term_parent_id_remains_null(): void
    {
        $resolved = ArticleContentClassification::fromSyncItem([
            'wp_post_type' => 'post',
            'wp_is_term' => false,
            'parent_id' => 99,
        ]);
        self::assertNull($resolved['parent_id']);
    }

    public function test_page_behavior_uses_content_type_not_raw_wp_post_type(): void
    {
        $article = $this->articleWithMeta([
            'content_type' => 'page',
            'wp_is_term' => '0',
            'wp_post_type' => 'landing_page',
        ]);

        self::assertTrue(ArticlePostTypeResolver::isPage($article));
        self::assertSame(ContentType::Page, ArticlePostTypeResolver::contentType($article));

        $resolved = PublishingTaxonomyResolver::resolve(
            ArticlePostTypeResolver::contentType($article)->value,
        );
        self::assertFalse($resolved['required']);
        self::assertSame('page', $resolved['reason']);
    }

    public function test_product_behavior_uses_content_type(): void
    {
        $article = $this->articleWithMeta([
            'content_type' => 'product',
            'wp_is_term' => '0',
            'wp_post_type' => 'machine',
        ]);

        self::assertTrue(ArticlePostTypeResolver::isProduct($article));
        $resolved = PublishingTaxonomyResolver::resolve(
            ArticlePostTypeResolver::contentType($article)->value,
        );
        self::assertTrue($resolved['required']);
        self::assertSame('product', $resolved['reason']);
    }

    public function test_cpt_mapped_page_inherits_page_behavior(): void
    {
        $article = $this->articleWithMeta([
            'content_type' => 'page',
            'wp_is_term' => '0',
            'wp_post_type' => 'landing_page',
        ]);

        self::assertSame('page', PublishingTaxonomyResolver::resolve(
            ArticleContentClassification::for($article)->contentType()->value,
        )['reason']);
    }

    public function test_cpt_mapped_product_inherits_product_behavior(): void
    {
        $article = $this->articleWithMeta([
            'content_type' => 'product',
            'wp_is_term' => '0',
            'wp_post_type' => 'machine',
        ]);

        self::assertSame('product', PublishingTaxonomyResolver::resolve(
            ArticleContentClassification::for($article)->contentType()->value,
        )['reason']);
    }

    public function test_legacy_row_inference_preserves_page_and_term_flags(): void
    {
        $page = ArticleContentClassification::fromLegacyRow('article', 'page', 'post');
        self::assertSame(ContentType::Page, $page['content_type']);
        self::assertFalse($page['wp_is_term']);

        $term = ArticleContentClassification::fromLegacyRow('category', 'category', 'term');
        self::assertSame(ContentType::Post, $term['content_type']);
        self::assertTrue($term['wp_is_term']);
    }

    public function test_invalid_content_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContentType::fromString('portfolio');
    }

    public function test_no_fourth_content_type_allowed(): void
    {
        self::assertSame(['post', 'page', 'product'], ContentType::values());
        self::assertNull(ContentType::tryFromString('term'));
        self::assertNull(ContentType::tryFromString('article'));
        self::assertNull(ContentType::tryFromString('category'));
    }

    public function test_resolver_compat_labels_for_terms_and_products(): void
    {
        $product = $this->articleWithMeta([
            'content_type' => 'product',
            'wp_is_term' => '0',
        ]);
        self::assertSame(SeoProjectTask::POST_TYPE_PRODUCT, ArticlePostTypeResolver::resolve($product));

        $productCat = $this->articleWithMeta([
            'content_type' => 'product',
            'wp_is_term' => '1',
            'wp_post_type' => 'product_cat',
        ]);
        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY,
            ArticlePostTypeResolver::resolve($productCat),
        );
        self::assertTrue(ArticlePostTypeResolver::isTerm($productCat));
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function articleWithMeta(array $metas, string $legacyType = ''): SeoArticle
    {
        $article = new SeoArticle;
        $article->type = $legacyType;
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
