<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\PublishingTaxonomyResolver;
use PHPUnit\Framework\TestCase;

final class PublishingTaxonomyResolverTest extends TestCase
{
    public function test_post_and_article_require_category(): void
    {
        foreach (['article', 'post', ''] as $type) {
            $resolved = PublishingTaxonomyResolver::resolve($type);
            self::assertTrue($resolved['required']);
            self::assertSame('category', $resolved['taxonomy']);
            self::assertSame('category', $resolved['wp_taxonomy']);
        }
    }

    public function test_page_does_not_require_category(): void
    {
        $resolved = PublishingTaxonomyResolver::resolve('page');
        self::assertFalse($resolved['required']);
        self::assertNull($resolved['taxonomy']);
        self::assertSame('page', $resolved['reason']);
    }

    public function test_page_wins_when_normalized_post_type_is_article(): void
    {
        $resolved = PublishingTaxonomyResolver::resolve('article', 'page');
        self::assertFalse($resolved['required']);
        self::assertSame('page', $resolved['reason']);
    }

    public function test_product_requires_product_category(): void
    {
        $resolved = PublishingTaxonomyResolver::resolve('product');
        self::assertTrue($resolved['required']);
        self::assertSame('product_category', $resolved['taxonomy']);
        self::assertSame('product_cat', $resolved['wp_taxonomy']);
    }

    public function test_custom_post_type_does_not_assume_blog_category(): void
    {
        $resolved = PublishingTaxonomyResolver::resolve('portfolio');
        self::assertFalse($resolved['required']);
        self::assertNull($resolved['taxonomy']);
        self::assertSame('custom_or_unknown', $resolved['reason']);
    }

    public function test_taxonomy_entities_do_not_require_post_categories(): void
    {
        foreach (['category', 'product_category', 'product_cat'] as $type) {
            $resolved = PublishingTaxonomyResolver::resolve($type);
            self::assertFalse($resolved['required']);
            self::assertSame('taxonomy_entity', $resolved['reason']);
        }
    }
}
