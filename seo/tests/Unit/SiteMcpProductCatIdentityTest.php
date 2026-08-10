<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatIdentity;
use PHPUnit\Framework\TestCase;

final class SiteMcpProductCatIdentityTest extends TestCase
{
    public function test_parent_zero_survives_normalization(): void
    {
        $row = SiteMcpProductCatIdentity::normalizeVerified([
            'taxonomy' => 'product_cat',
            'term_id' => 10,
            'parent_id' => 0,
            'name' => 'Root',
            'slug' => 'root',
            'url' => 'https://example.test/product-category/root/',
            'post_count' => 3,
        ]);

        self::assertNotNull($row);
        self::assertSame(0, $row['parent_term_id']);
        self::assertSame(10, $row['term_id']);
        self::assertSame('product_cat', $row['taxonomy']);
        self::assertSame('taxonomy', $row['page_type']);
        self::assertTrue($row['verified']);
    }

    public function test_missing_parent_returns_null(): void
    {
        self::assertNull(SiteMcpProductCatIdentity::normalizeVerified([
            'taxonomy' => 'product_cat',
            'term_id' => 10,
            'name' => 'Orphan',
        ]));
    }

    public function test_individual_product_excluded(): void
    {
        self::assertNull(SiteMcpProductCatIdentity::normalizeVerified([
            'taxonomy' => 'product_cat',
            'type' => 'product',
            'term_id' => 10,
            'parent_term_id' => 0,
            'name' => 'SKU',
        ]));
    }

    public function test_child_parent_preserved(): void
    {
        $row = SiteMcpProductCatIdentity::normalizeVerified([
            'taxonomy' => 'product_cat',
            'term_id' => 22,
            'parent_term_id' => 10,
            'name' => 'Child',
        ]);
        self::assertNotNull($row);
        self::assertSame(10, $row['parent_term_id']);
    }

    public function test_verified_wins_over_incomplete_on_dedupe(): void
    {
        $out = SiteMcpProductCatIdentity::dedupeByTaxonomyTermId([
            [
                'taxonomy' => 'product_cat',
                'term_id' => 5,
                'url' => 'https://example.test/a/',
                'verified' => false,
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 5,
                'parent_term_id' => 0,
                'url' => '',
                'verified' => true,
            ],
        ]);

        self::assertCount(1, $out);
        self::assertTrue($out[0]['verified']);
        self::assertSame('https://example.test/a/', $out[0]['url']);
        self::assertSame(0, (int) $out[0]['parent_term_id']);
    }

    public function test_availability_unavailable_vs_zero_roots(): void
    {
        self::assertSame(
            SiteMcpProductCatIdentity::AVAILABILITY_UNAVAILABLE,
            SiteMcpProductCatIdentity::resolveAvailability(false, true, 0, 0),
        );
        self::assertSame(
            SiteMcpProductCatIdentity::AVAILABILITY_AVAILABLE,
            SiteMcpProductCatIdentity::resolveAvailability(true, true, 0, 0),
        );
        self::assertSame(
            SiteMcpProductCatIdentity::AVAILABILITY_INCOMPLETE,
            SiteMcpProductCatIdentity::resolveAvailability(true, true, 0, 4),
        );
    }

    public function test_count_tree(): void
    {
        $tree = SiteMcpProductCatIdentity::countTree([
            ['taxonomy' => 'product_cat', 'term_id' => 1, 'parent_term_id' => 0],
            ['taxonomy' => 'product_cat', 'term_id' => 2, 'parent_term_id' => 0],
            ['taxonomy' => 'product_cat', 'term_id' => 3, 'parent_term_id' => 1],
        ]);

        self::assertSame(3, $tree['product_cat_total']);
        self::assertSame(2, $tree['root_product_cat']);
        self::assertSame(1, $tree['child_product_cat']);
    }
}
