<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpKeywordExtractor;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatIdentity;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * product_cat seed eligibility: all verified levels, fail-closed capability, Link List precedence.
 */
final class TopicSeedResolverProductCatTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function verified(array $rows): array
    {
        return TopicSeedResolver::verifiedProductCategories($rows);
    }

    public function test_root_product_cat_parent_zero_is_seed(): void
    {
        $rows = $this->verified([[
            'taxonomy' => 'product_cat',
            'term_id' => 10,
            'parent_term_id' => 0,
            'name' => 'Root Cat',
            'url' => 'https://example.test/root-cat',
            'verified' => true,
            'page_type' => 'taxonomy',
        ]]);
        self::assertCount(1, $rows);
        self::assertSame(10, (int) $rows[0]['term_id']);
        self::assertSame(0, (int) $rows[0]['parent_term_id']);
    }

    public function test_child_product_cat_parent_nonzero_is_seed(): void
    {
        $rows = $this->verified([[
            'taxonomy' => 'product_cat',
            'term_id' => 20,
            'parent_term_id' => 10,
            'name' => 'Child Cat',
            'url' => 'https://example.test/child-cat',
            'verified' => true,
            'page_type' => 'taxonomy',
        ]]);
        self::assertCount(1, $rows);
        self::assertSame(20, (int) $rows[0]['term_id']);
        self::assertSame(10, (int) $rows[0]['parent_term_id']);
    }

    public function test_nested_product_cat_deeper_level_is_seed(): void
    {
        $rows = $this->verified([
            [
                'taxonomy' => 'product_cat',
                'term_id' => 1,
                'parent_term_id' => 0,
                'name' => 'L1',
                'verified' => true,
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 2,
                'parent_term_id' => 1,
                'name' => 'L2',
                'verified' => true,
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 3,
                'parent_term_id' => 2,
                'name' => 'L3 Nested Gift Bags',
                'url' => 'https://example.test/l3-nested-gift-bags',
                'verified' => true,
                'page_type' => 'taxonomy',
            ],
        ]);
        $ids = array_map(static fn (array $r): int => (int) $r['term_id'], $rows);
        self::assertSame([1, 2, 3], $ids);
        self::assertSame(2, (int) $rows[2]['parent_term_id']);
    }

    public function test_generic_verified_child_category_fixture_survives_resolver(): void
    {
        // Generic fixture equivalent to a nested gift-bag category (no domain hardcode).
        $category = [
            'taxonomy' => 'product_cat',
            'term_id' => 77,
            'parent_term_id' => 15,
            'name' => 'Gift Bag Category',
            'slug' => 'gift-bag-category',
            'url' => 'https://example.test/gift-bag-category',
            'verified' => true,
            'page_type' => 'taxonomy',
        ];
        $verified = $this->verified([$category]);
        self::assertCount(1, $verified);
        self::assertSame(77, (int) $verified[0]['term_id']);
        self::assertSame(15, (int) $verified[0]['parent_term_id']);

        $extracted = (new SiteMcpKeywordExtractor)->extractCategoryTopic($verified[0]);
        self::assertNotSame('', trim((string) ($extracted['keyword'] ?? '')));
    }

    public function test_individual_product_is_not_seed(): void
    {
        $rows = $this->verified([[
            'taxonomy' => 'product_cat',
            'term_id' => 99,
            'parent_term_id' => 0,
            'name' => 'SKU Product',
            'page_type' => 'product',
            'type' => 'product',
            'verified' => true,
        ]]);
        self::assertSame([], $rows);
    }

    public function test_other_taxonomy_is_not_seed(): void
    {
        $rows = $this->verified([[
            'taxonomy' => 'category',
            'term_id' => 5,
            'parent_term_id' => 0,
            'name' => 'Blog Cat',
            'verified' => true,
        ]]);
        self::assertSame([], $rows);
    }

    public function test_invalid_term_id_is_not_seed(): void
    {
        $rows = $this->verified([[
            'taxonomy' => 'product_cat',
            'term_id' => 0,
            'parent_term_id' => 0,
            'name' => 'Bad',
            'verified' => true,
        ]]);
        self::assertSame([], $rows);
    }

    public function test_unverified_category_is_not_seed(): void
    {
        // Missing parent_term_id → normalizeVerified returns null; unverified fallback fails.
        $rows = $this->verified([[
            'taxonomy' => 'product_cat',
            'term_id' => 8,
            'name' => 'Ambiguous',
            'verified' => false,
        ]]);
        self::assertSame([], $rows);

        $null = SiteMcpProductCatIdentity::normalizeVerified([
            'taxonomy' => 'product_cat',
            'term_id' => 8,
            'name' => 'Ambiguous',
        ]);
        self::assertNull($null);
    }

    public function test_incomplete_taxonomy_capability_yields_no_product_cat_seeds(): void
    {
        $availability = SiteMcpProductCatIdentity::resolveAvailability(
            capabilityExportAvailable: true,
            capabilityKnown: true,
            verifiedTotal: 0,
            incompleteTotal: 3,
        );
        self::assertSame(SiteMcpProductCatIdentity::AVAILABILITY_INCOMPLETE, $availability);
        // Fail-closed gate in productCatSeeds: incomplete → [].
        self::assertSame([], $this->productCatSeedsWhenAvailability($availability, [[
            'taxonomy' => 'product_cat',
            'term_id' => 1,
            'parent_term_id' => 0,
            'name' => 'Would Seed If Available',
            'verified' => true,
        ]]));
    }

    public function test_unavailable_taxonomy_capability_yields_no_product_cat_seeds(): void
    {
        $availability = SiteMcpProductCatIdentity::resolveAvailability(
            capabilityExportAvailable: false,
            capabilityKnown: true,
            verifiedTotal: 0,
            incompleteTotal: 0,
        );
        self::assertSame(SiteMcpProductCatIdentity::AVAILABILITY_UNAVAILABLE, $availability);
        self::assertSame([], $this->productCatSeedsWhenAvailability($availability, [[
            'taxonomy' => 'product_cat',
            'term_id' => 1,
            'parent_term_id' => 0,
            'name' => 'Would Seed If Available',
            'verified' => true,
        ]]));
    }

    public function test_link_list_precedence_preserved_in_resolve_merge_order(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        // Link List seeds collected first; product_cat only fills missing keyword_id.
        $linkPos = strpos($src, 'linkListSeeds');
        $catPos = strpos($src, 'productCatSeeds');
        self::assertNotFalse($linkPos);
        self::assertNotFalse($catPos);
        self::assertLessThan($linkPos !== false ? $catPos : 0, (int) $linkPos);
        self::assertStringContainsString("if (! isset(\$byKeyword[\$seed['keyword_id']]))", $src);
        self::assertStringContainsString('TopicKeywordSource::LINK_LIST', $src);
        self::assertStringContainsString('TopicKeywordSource::PRODUCT_CAT', $src);
        self::assertSame(TopicKeywordSource::LINK_LIST, 'link_list');
        self::assertSame(TopicKeywordSource::PRODUCT_CAT, 'product_cat');
    }

    public function test_site_isolation_contract_in_resolver(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        self::assertStringContainsString('function resolve(int $siteId)', $src);
        self::assertStringContainsString('getRawPayloadForSite', $src);
        self::assertStringContainsString("Site::query()->find(\$siteId)", $src);
        self::assertStringContainsString('upsertClassification($siteId', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertStringNotContainsString('seo_topic_cluster_meta', $src);
        self::assertStringNotContainsString('SiteLinkCatalogCapability', $src);
        self::assertStringNotContainsString('effectiveLinks', $src);
        self::assertStringNotContainsString('->forKeyword(', $src);
        self::assertStringNotContainsString('::forKeyword(', $src);
    }

    public function test_no_root_only_filter_remains(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        self::assertStringNotContainsString('rootProductCategories', $src);
        self::assertStringNotContainsString('child categories are NOT', $src);
        self::assertStringNotContainsString("parent_term_id'] !== 0", $src);
        self::assertStringContainsString('verifiedProductCategories', $src);
    }

    /**
     * Mirrors the fail-closed availability gate used by productCatSeeds().
     *
     * @param  list<array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    private function productCatSeedsWhenAvailability(string $availability, array $categories): array
    {
        if ($availability === SiteMcpProductCatIdentity::AVAILABILITY_UNAVAILABLE
            || $availability === SiteMcpProductCatIdentity::AVAILABILITY_INCOMPLETE) {
            return [];
        }

        return $this->verified($categories);
    }
}
