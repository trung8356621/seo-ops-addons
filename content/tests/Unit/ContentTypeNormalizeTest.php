<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleByWpIdResolver;
use PHPUnit\Framework\TestCase;

final class ContentTypeNormalizeTest extends TestCase
{
    private ArticleByWpIdResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ArticleByWpIdResolver();
    }

    public function test_post_maps_to_article(): void
    {
        self::assertSame('article', $this->resolver->normalizeType('post'));
    }

    public function test_article_maps_to_article(): void
    {
        self::assertSame('article', $this->resolver->normalizeType('article'));
    }

    public function test_page_maps_to_article_business_type(): void
    {
        self::assertSame('article', $this->resolver->normalizeType('page'));
    }

    public function test_product_maps_to_product(): void
    {
        self::assertSame('product', $this->resolver->normalizeType('product'));
    }

    public function test_category_maps_to_category(): void
    {
        self::assertSame('category', $this->resolver->normalizeType('category'));
    }

    public function test_product_cat_maps_to_product_category(): void
    {
        self::assertSame('product_category', $this->resolver->normalizeType('product_cat'));
    }

    public function test_product_category_maps_to_product_category(): void
    {
        self::assertSame('product_category', $this->resolver->normalizeType('product_category'));
    }

    public function test_empty_defaults_to_article(): void
    {
        self::assertSame('article', $this->resolver->normalizeType(''));
    }

    public function test_custom_post_type_maps_to_article(): void
    {
        self::assertSame('article', $this->resolver->normalizeType('portfolio'));
        self::assertSame('article', $this->resolver->normalizeType('case_study'));
        self::assertSame('article', $this->resolver->normalizeType('event'));
    }

    public function test_page_case_insensitive_maps_to_article(): void
    {
        self::assertSame('article', $this->resolver->normalizeType('Page'));
        self::assertSame('article', $this->resolver->normalizeType('PAGE'));
    }
}
