<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\EffectiveDomainLinkResolver;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use PHPUnit\Framework\TestCase;

final class EffectiveDomainLinkResolverTest extends TestCase
{
    private EffectiveDomainLinkResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EffectiveDomainLinkResolver(new SiteDomainPromptContextService);
    }

    public function test_a_manual_only_plus_main_domain(): void
    {
        $effective = $this->resolver->merge(
            [
                ['keyword' => 'balo quà tặng', 'link' => 'https://example.com/balo'],
                ['keyword' => 'túi giữ nhiệt', 'link' => 'https://example.com/tui'],
            ],
            [],
            ['keyword' => 'Công Ty ABC', 'link' => 'https://example.com/'],
        );

        self::assertCount(3, $effective);
        self::assertSame(EffectiveDomainLinkResolver::SOURCE_CUSTOM, $effective[0]['source']);
        self::assertSame(EffectiveDomainLinkResolver::SOURCE_CUSTOM, $effective[1]['source']);
        self::assertSame(EffectiveDomainLinkResolver::SOURCE_MAIN_DOMAIN, $effective[2]['source']);
    }

    public function test_b_product_cat_implicit(): void
    {
        $effective = $this->resolver->merge(
            [
                ['keyword' => 'custom a', 'link' => 'https://example.com/a'],
                ['keyword' => 'custom b', 'link' => 'https://example.com/b'],
            ],
            [
                ['keyword' => 'Túi xách du lịch', 'link' => 'https://example.com/danh-muc/tui'],
                ['keyword' => 'Balo học sinh', 'link' => 'https://example.com/danh-muc/balo'],
                ['keyword' => 'Balo công sở', 'link' => 'https://example.com/danh-muc/balo-cs'],
            ],
            ['keyword' => 'May Túi Canvas', 'link' => 'https://example.com/'],
        );

        self::assertCount(6, $effective);
    }

    public function test_c_custom_overrides_product_cat(): void
    {
        $effective = $this->resolver->merge(
            [
                ['keyword' => 'túi giữ nhiệt', 'link' => 'https://example.com/custom'],
            ],
            [
                ['keyword' => 'túi giữ nhiệt', 'link' => 'https://example.com/product-cat'],
            ],
            null,
        );

        self::assertCount(1, $effective);
        self::assertSame('https://example.com/custom', $effective[0]['link']);
        self::assertSame(EffectiveDomainLinkResolver::SOURCE_CUSTOM, $effective[0]['source']);
    }

    public function test_d_case_and_space_dedupe(): void
    {
        $effective = $this->resolver->merge(
            [
                ['keyword' => 'Túi giữ nhiệt', 'link' => 'https://example.com/custom'],
            ],
            [
                ['keyword' => ' túi giữ nhiệt ', 'link' => 'https://example.com/product-cat'],
            ],
            null,
        );

        self::assertCount(1, $effective);
        self::assertSame('Túi giữ nhiệt', $effective[0]['keyword']);
        self::assertSame('https://example.com/custom', $effective[0]['link']);
    }

    public function test_e_main_domain_uses_brand_anchor_not_hostname(): void
    {
        $effective = $this->resolver->merge(
            [],
            [],
            ['keyword' => 'Công Ty ABC', 'link' => 'https://example.com/'],
        );

        self::assertCount(1, $effective);
        self::assertSame('Công Ty ABC', $effective[0]['keyword']);
        self::assertSame('https://example.com/', $effective[0]['link']);
        self::assertNotSame('example.com', $effective[0]['keyword']);
    }

    public function test_f_empty_product_cat_rows_skipped_via_merge(): void
    {
        $effective = $this->resolver->merge(
            [['keyword' => 'ok', 'link' => 'https://example.com/ok']],
            [
                ['keyword' => '', 'link' => 'https://example.com/bad'],
                ['keyword' => 'gone', 'link' => ''],
            ],
            null,
        );

        self::assertCount(1, $effective);
        self::assertSame('ok', $effective[0]['keyword']);
    }

    public function test_g_other_taxonomy_not_in_merge_input(): void
    {
        // Resolver only receives product_cat rows from loader; merge itself never invents category/post_tag.
        $effective = $this->resolver->merge(
            [],
            [['keyword' => 'product only', 'link' => 'https://example.com/pc']],
            null,
        );

        self::assertCount(1, $effective);
        self::assertSame(EffectiveDomainLinkResolver::SOURCE_PRODUCT_CAT, $effective[0]['source']);
    }

    public function test_i_custom_order_preserved_before_product_cat(): void
    {
        $effective = $this->resolver->merge(
            [
                ['keyword' => 'second-custom', 'link' => 'https://example.com/2'],
                ['keyword' => 'first-custom', 'link' => 'https://example.com/1'],
            ],
            [
                ['keyword' => 'cat-a', 'link' => 'https://example.com/cat-a'],
            ],
            ['keyword' => 'Brand', 'link' => 'https://example.com/'],
        );

        self::assertSame(
            ['second-custom', 'first-custom', 'cat-a', 'Brand'],
            array_column($effective, 'keyword'),
        );
    }

    public function test_normalize_keyword_key_collapses_whitespace_casefold(): void
    {
        self::assertSame(
            'túi giữ nhiệt',
            EffectiveDomainLinkResolver::normalizeKeywordKey("  Túi   giữ\tnhiệt "),
        );
    }
}
