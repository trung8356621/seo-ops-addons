<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\PublishCategoryOptionsAssembler;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalog;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalogResult;
use PHPUnit\Framework\TestCase;

final class PublishCategoryOptionsAssemblerTest extends TestCase
{
    public function test_post_uses_category_and_product_uses_product_cat(): void
    {
        $catalog = new class implements PublishingTaxonomyCatalog
        {
            public function getTerms(int $siteId, string $taxonomy): PublishingTaxonomyCatalogResult
            {
                unset($siteId);
                if ($taxonomy === self::TAXONOMY_CATEGORY) {
                    return PublishingTaxonomyCatalogResult::ok($taxonomy, [
                        ['id' => 1, 'name' => 'Tin tức', 'parent' => 0],
                        ['id' => 2, 'name' => 'Trong nước', 'parent' => 1],
                    ]);
                }
                if ($taxonomy === self::TAXONOMY_PRODUCT_CAT) {
                    return PublishingTaxonomyCatalogResult::ok($taxonomy, [
                        ['id' => 10, 'name' => 'Balo', 'parent' => 0],
                    ]);
                }

                return PublishingTaxonomyCatalogResult::unavailable($taxonomy, 'unused', '');
            }
        };

        $bundle = (new PublishCategoryOptionsAssembler($catalog))->forSite(7);

        self::assertSame([
            ['id' => 1, 'label' => 'Tin tức'],
            ['id' => 2, 'label' => '— Trong nước'],
        ], $bundle['category']);
        self::assertSame([
            ['id' => 10, 'label' => 'Balo'],
        ], $bundle['product_category']);
        self::assertTrue($bundle['status']['category']['ok']);
        self::assertTrue($bundle['status']['product_category']['ok']);
        self::assertSame('category', $bundle['status']['category']['taxonomy']);
        self::assertSame('product_cat', $bundle['status']['product_category']['taxonomy']);
    }

    public function test_unavailable_catalog_returns_empty_options_without_article_fallback(): void
    {
        $catalog = new class implements PublishingTaxonomyCatalog
        {
            public function getTerms(int $siteId, string $taxonomy): PublishingTaxonomyCatalogResult
            {
                unset($siteId);

                return PublishingTaxonomyCatalogResult::unavailable($taxonomy, 'http', 'taxonomy catalog HTTP 500');
            }
        };

        $bundle = (new PublishCategoryOptionsAssembler($catalog))->forSite(7);

        self::assertSame([], $bundle['category']);
        self::assertSame([], $bundle['product_category']);
        self::assertFalse($bundle['status']['category']['ok']);
        self::assertSame('http', $bundle['status']['category']['code']);
    }
}
