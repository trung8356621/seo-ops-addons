<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkProductCatCatalog;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkProductCatMatcher;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpGenerator;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Full product_cat tree must enter Internal Link candidate pool.
 * Site MCP root-only projection must remain unchanged.
 */
final class ArticleInternalLinkProductCatCoverageTest extends TestCase
{
    private ArticleInternalLinkProductCatCatalog $catalog;

    private ArticleInternalLinkProductCatMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new ArticleInternalLinkProductCatCatalog;
        $this->matcher = new ArticleInternalLinkProductCatMatcher($this->catalog);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureTaxonomy(): array
    {
        return [
            [
                'taxonomy' => 'product_cat',
                'term_id' => 10,
                'parent_term_id' => 0,
                'name' => 'May Túi Xách',
                'slug' => 'may-tui-xach',
                'url' => 'https://example.com/may-tui-xach/',
                'seo_title' => 'May Túi Xách',
                'article_id' => 101,
                'health' => 'ok',
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 20,
                'parent_term_id' => 10,
                'name' => 'May Túi Vải Không Dệt',
                'slug' => 'may-tui-vai-khong-det',
                'url' => 'https://example.com/may-tui-vai-khong-det/',
                'seo_title' => 'May Túi Vải Không Dệt',
                'article_id' => 102,
                'health' => 'ok',
            ],
            [
                'taxonomy' => 'product_cat',
                'term_id' => 30,
                'parent_term_id' => 20,
                'name' => 'Túi Vải Không Dệt Ép Nhiệt',
                'slug' => 'tui-vai-khong-det-ep-nhiet',
                'url' => 'https://example.com/tui-vai-khong-det-ep-nhiet/',
                'seo_title' => 'Túi Vải Không Dệt Ép Nhiệt',
                'article_id' => 103,
                'health' => 'ok',
            ],
        ];
    }

    public function test_full_tree_enters_candidate_pool_including_child_and_grandchild(): void
    {
        $pool = $this->catalog->fromRows($this->fixtureTaxonomy());
        $debug = $this->catalog->lastDebug();

        self::assertSame(3, $debug['product_cat_total']);
        self::assertSame(1, $debug['product_cat_root']);
        self::assertSame(1, $debug['product_cat_child']);
        self::assertSame(1, $debug['product_cat_grandchild_or_deep']);
        self::assertSame(3, $debug['product_cat_after_filter']);

        $termIds = array_map(static fn (array $row): int => (int) $row['term_id'], $pool);
        self::assertSame([10, 20, 30], $termIds);

        $child = $pool[1];
        self::assertSame(20, (int) $child['term_id']);
        self::assertSame(10, (int) $child['parent_term_id']);
        self::assertSame(1, (int) $child['depth']);

        $grandchild = $pool[2];
        self::assertSame(30, (int) $grandchild['term_id']);
        self::assertSame(20, (int) $grandchild['parent_term_id']);
        self::assertSame(2, (int) $grandchild['depth']);
    }

    public function test_parent_nonzero_is_not_eligibility_filter(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkProductCatCatalog::class))->getFileName()
        );

        self::assertStringNotContainsString('parent_term_id !== 0', $source);
        self::assertStringNotContainsString('parent_term_id != 0', $source);
        self::assertStringNotContainsString('rootProductCategories', $source);

        $pool = $this->catalog->fromRows($this->fixtureTaxonomy());
        $childIds = array_values(array_map(
            static fn (array $row): int => (int) $row['term_id'],
            array_filter($pool, static fn (array $row): bool => (int) $row['parent_term_id'] > 0),
        ));

        self::assertSame([20, 30], $childIds);
    }

    public function test_normalized_phrase_match_outranks_article_fallback_score(): void
    {
        $plain = 'Khác với canvas, túi vải không dệt được sản xuất tại xưởng.';
        $pool = $this->catalog->fromRows($this->fixtureTaxonomy());
        $result = $this->matcher->match(
            $plain,
            $pool,
            [
                'site_domain' => 'example.com',
                'site_id' => 1,
                'current_article_id' => 999,
                'current_urls' => ['https://example.com/current-article'],
                'current_slug' => 'current-article',
            ],
        );

        $suggestions = $result['suggestions'];
        self::assertNotSame([], $suggestions);

        $winner = $suggestions[0];
        self::assertSame('product_cat', $winner['candidate_source']);
        self::assertSame(20, (int) $winner['term_id']);
        self::assertSame(10, (int) $winner['parent_term_id']);
        self::assertSame(1, (int) $winner['depth']);
        self::assertGreaterThanOrEqual(96, (int) $winner['score']);
        self::assertArrayHasKey('provenance', $winner);
        self::assertSame(20, (int) $winner['provenance']['term_id']);
        self::assertSame(10, (int) $winner['provenance']['parent_term_id']);

        // Article title-match baseline is 80 — product_cat must win.
        self::assertGreaterThan(80, (int) $winner['score']);
        self::assertSame(
            ArticleInternalLinkProductCatMatcher::REASON_NORMALIZED_PHRASE,
            (string) $winner['match_reason'],
        );
    }

    public function test_child_exact_beats_root_partial_when_both_match(): void
    {
        $rows = $this->fixtureTaxonomy();
        // Root also loosely related; phrase is specific to child.
        $plain = 'Chúng tôi chuyên may túi vải không dệt cho doanh nghiệp.';
        $pool = $this->catalog->fromRows($rows);
        $result = $this->matcher->match(
            $plain,
            $pool,
            [
                'site_domain' => 'example.com',
                'current_article_id' => 1,
                'current_urls' => [],
                'current_slug' => 'bai-viet',
            ],
        );

        self::assertNotSame([], $result['suggestions']);
        self::assertSame(20, (int) $result['suggestions'][0]['term_id']);
    }

    public function test_individual_product_rows_excluded_from_catalog(): void
    {
        $rows = $this->fixtureTaxonomy();
        $rows[] = [
            'taxonomy' => 'product_cat',
            'term_id' => 99,
            'parent_term_id' => 20,
            'name' => 'Túi Vải SKU-99',
            'slug' => 'tui-vai-sku-99',
            'url' => 'https://example.com/product/tui-vai-sku-99/',
            'page_type' => 'product',
            'health' => 'ok',
        ];

        $pool = $this->catalog->fromRows($rows);
        $ids = array_map(static fn (array $row): int => (int) $row['term_id'], $pool);

        self::assertNotContains(99, $ids);
        self::assertSame(3, count($pool));
    }

    public function test_known_404_category_excluded(): void
    {
        $rows = $this->fixtureTaxonomy();
        $rows[1]['health'] = '404';

        $pool = $this->catalog->fromRows($rows);
        $ids = array_map(static fn (array $row): int => (int) $row['term_id'], $pool);

        self::assertNotContains(20, $ids);
        self::assertSame(1, (int) $this->catalog->lastDebug()['product_cat_health_excluded']);
    }

    public function test_already_linked_category_url_excluded(): void
    {
        $plain = 'Nội dung có túi vải không dệt trong bài.';
        $pool = $this->catalog->fromRows($this->fixtureTaxonomy());
        $result = $this->matcher->match(
            $plain,
            $pool,
            [
                'site_domain' => 'example.com',
                'current_article_id' => 1,
                'current_urls' => [],
                'current_slug' => 'bai',
            ],
            alreadyLinkedNormalizedUrls: ['example.com/may-tui-vai-khong-det'],
        );

        $ids = array_map(static fn (array $row): int => (int) ($row['term_id'] ?? 0), $result['suggestions']);
        self::assertNotContains(20, $ids);
        self::assertGreaterThanOrEqual(1, (int) $result['debug']['skipped_already_linked_url']);
    }

    public function test_self_destination_excluded(): void
    {
        $plain = 'Bài này nói về túi vải không dệt.';
        $pool = $this->catalog->fromRows($this->fixtureTaxonomy());
        $result = $this->matcher->match(
            $plain,
            $pool,
            [
                'site_domain' => 'example.com',
                'current_article_id' => 102,
                'current_urls' => ['https://example.com/may-tui-vai-khong-det/'],
                'current_slug' => 'may-tui-vai-khong-det',
            ],
        );

        $ids = array_map(static fn (array $row): int => (int) ($row['term_id'] ?? 0), $result['suggestions']);
        self::assertNotContains(20, $ids);
    }

    public function test_provenance_includes_term_and_parent(): void
    {
        $plain = 'Chọn túi vải không dệt eco-friendly.';
        $pool = $this->catalog->fromRows($this->fixtureTaxonomy());
        $result = $this->matcher->match(
            $plain,
            $pool,
            [
                'site_domain' => 'example.com',
                'current_article_id' => 1,
                'current_urls' => [],
                'current_slug' => 'x',
            ],
        );

        self::assertNotSame([], $result['suggestions']);
        $prov = $result['suggestions'][0]['provenance'];
        self::assertSame('product_cat', $prov['candidate_source']);
        self::assertSame('product_cat', $prov['taxonomy']);
        self::assertGreaterThan(0, (int) $prov['term_id']);
        self::assertArrayHasKey('parent_term_id', $prov);
        self::assertArrayHasKey('depth', $prov);
        self::assertArrayHasKey('matched_phrase', $prov);
        self::assertArrayHasKey('match_reason', $prov);
        self::assertArrayHasKey('url', $prov);
        self::assertArrayHasKey('health', $prov);
    }

    public function test_site_mcp_root_only_projection_unchanged(): void
    {
        $generator = new ReflectionClass(SiteMcpGenerator::class);
        self::assertTrue($generator->hasMethod('rootProductCategories'));

        $method = new ReflectionMethod(SiteMcpGenerator::class, 'rootProductCategories');
        $body = $this->methodBody($method);
        self::assertStringContainsString('parent_term_id', $body);
        self::assertStringContainsString('!== 0', $body);

        // Separation contract: Internal Link catalog must not call MCP root helper.
        $catalogSource = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkProductCatCatalog::class))->getFileName()
        );
        self::assertStringNotContainsString('SiteMcpGenerator', $catalogSource);
        self::assertStringNotContainsString('rootProductCategories', $catalogSource);
        self::assertStringContainsString('SiteMcpProductCatIdentity', $catalogSource);
    }

    public function test_suggestion_service_wires_product_cat_before_fallback(): void
    {
        $pipeline = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline::class))->getFileName()
        );

        self::assertStringContainsString('ArticleInternalLinkProductCatMatcher', $pipeline);
        self::assertStringContainsString('matchForSite', $pipeline);
        self::assertStringContainsString('internal_link_catalog', $pipeline);
        self::assertStringContainsString('[INTERNAL_LINK_PIPELINE]', $pipeline);

        $productCatPos = strpos($pipeline, 'Stage 1: FULL product_cat');
        $fallbackPos = strpos($pipeline, 'contentKeywordFallback->supplement');

        self::assertNotFalse($productCatPos);
        self::assertNotFalse($fallbackPos);
        self::assertLessThan($fallbackPos, $productCatPos);
    }

    public function test_validator_accepts_product_cat_suggestion_shape(): void
    {
        $ok = LinkSuggestionValidator::isValidLinkSuggestion(
            [
                'text' => 'túi vải không dệt',
                'href' => 'https://example.com/may-tui-vai-khong-det/',
                'target_url' => 'https://example.com/may-tui-vai-khong-det/',
                'target_article_id' => 102,
                'bucket' => 'internal',
            ],
            [
                'site_domain' => 'example.com',
                'current_article_id' => 1,
                'current_urls' => [],
                'current_slug' => 'other',
            ],
        );

        self::assertTrue($ok);
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $lines = file($file);
        self::assertIsArray($lines);

        $start = $method->getStartLine();
        $end = $method->getEndLine();
        $slice = array_slice($lines, $start - 1, $end - $start + 1);

        return implode('', $slice);
    }
}
