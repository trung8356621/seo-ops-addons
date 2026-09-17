<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\SiteLink;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteLink\SiteLinkPolicyResolver;
use Omnichannel\Addons\SearchFoundation\Services\SiteLink\VerifiedProductCatLinkSource;
use PHPUnit\Framework\TestCase;

final class SiteLinkPolicyResolverTest extends TestCase
{
    private SiteLinkPolicyResolver $policy;

    private VerifiedProductCatLinkSource $productCats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productCats = new VerifiedProductCatLinkSource;
        $this->policy = new SiteLinkPolicyResolver(
            new SiteDomainPromptContextService,
            $this->productCats,
        );
    }

    public function test_compose_includes_manual_and_all_depth_product_cat(): void
    {
        $productCat = $this->productCats->fromRawRows([
            [
                'taxonomy' => 'product_cat',
                'term_id' => 1,
                'parent_term_id' => 0,
                'name' => 'Root Cat',
                'url' => 'https://example.com/root',
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 2,
                'parent_term_id' => 1,
                'name' => 'Child Cat',
                'url' => 'https://example.com/child',
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 3,
                'parent_term_id' => 2,
                'name' => 'Nested Cat',
                'url' => 'https://example.com/nested',
            ],
        ]);

        $effective = $this->policy->compose(
            [['keyword' => 'manual a', 'url' => 'https://example.com/a']],
            $productCat,
            null,
        );

        self::assertCount(4, $effective);
        self::assertSame(SiteLinkPolicyResolver::SOURCE_DOMAIN_LINK_LIST, $effective[0]['source']);
        self::assertSame('Root Cat', $effective[1]['keyword']);
        self::assertSame(0, $effective[1]['parent_term_id']);
        self::assertSame('Child Cat', $effective[2]['keyword']);
        self::assertSame(1, $effective[2]['parent_term_id']);
        self::assertSame('Nested Cat', $effective[3]['keyword']);
        self::assertSame(2, $effective[3]['parent_term_id']);
    }

    public function test_missing_parent_and_invalid_identity_rejected(): void
    {
        $productCat = $this->productCats->fromRawRows([
            [
                'taxonomy' => 'product_cat',
                'term_id' => 10,
                'name' => 'No Parent',
                'url' => 'https://example.com/no-parent',
            ],
            [
                'taxonomy' => 'category',
                'term_id' => 11,
                'parent_term_id' => 0,
                'name' => 'Wrong Tax',
                'url' => 'https://example.com/wrong',
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 0,
                'parent_term_id' => 0,
                'name' => 'Bad Term',
                'url' => 'https://example.com/bad',
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 12,
                'parent_term_id' => 0,
                'name' => 'Ok Root',
                'url' => 'https://example.com/ok',
            ],
        ]);

        self::assertCount(1, $productCat);
        self::assertSame(12, $productCat[0]['term_id']);
    }

    public function test_manual_wins_keyword_collision_over_product_cat(): void
    {
        $effective = $this->policy->compose(
            [['keyword' => 'túi giữ nhiệt', 'url' => 'https://example.com/manual']],
            [['keyword' => 'túi giữ nhiệt', 'url' => 'https://example.com/cat', 'taxonomy' => 'product_cat', 'term_id' => 1, 'parent_term_id' => 0]],
            ['keyword' => 'Brand', 'url' => 'https://example.com/'],
        );

        self::assertCount(2, $effective);
        self::assertSame('https://example.com/manual', $effective[0]['url']);
        self::assertSame(SiteLinkPolicyResolver::SOURCE_DOMAIN_LINK_LIST, $effective[0]['source']);
        self::assertSame(SiteLinkPolicyResolver::SOURCE_MAIN_DOMAIN, $effective[1]['source']);
    }

    public function test_product_cat_wins_over_main_domain_collision(): void
    {
        $effective = $this->policy->compose(
            [],
            [['keyword' => 'Brand Co', 'url' => 'https://example.com/cat', 'taxonomy' => 'product_cat', 'term_id' => 1, 'parent_term_id' => 0]],
            ['keyword' => 'Brand Co', 'url' => 'https://example.com/'],
        );

        self::assertCount(1, $effective);
        self::assertSame(SiteLinkPolicyResolver::SOURCE_PRODUCT_CAT, $effective[0]['source']);
        self::assertSame('https://example.com/cat', $effective[0]['url']);
    }

    public function test_main_domain_only_when_passed_to_compose(): void
    {
        $withoutMain = $this->policy->compose(
            [['keyword' => 'a', 'url' => 'https://example.com/a']],
            [],
            null,
        );
        self::assertCount(1, $withoutMain);

        $withMain = $this->policy->compose(
            [['keyword' => 'a', 'url' => 'https://example.com/a']],
            [],
            ['keyword' => 'Brand', 'url' => 'https://example.com/'],
        );
        self::assertCount(2, $withMain);
        self::assertSame(SiteLinkPolicyResolver::SOURCE_MAIN_DOMAIN, $withMain[1]['source']);
    }

    public function test_null_parent_string_rejected_not_treated_as_root(): void
    {
        $productCat = $this->productCats->fromRawRows([
            [
                'taxonomy' => 'product_cat',
                'term_id' => 5,
                'parent_term_id' => null,
                'name' => 'Null Parent',
                'url' => 'https://example.com/null',
            ],
        ]);

        self::assertSame([], $productCat);
    }
}
