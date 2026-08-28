<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\EditDomain;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContactDiscovery;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContextAssembler;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDiscovery;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDraft;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpGenerator;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpKeywordExtractor;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpOfficialGuard;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpPreview;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpProductCatIdentity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteMcpDraftGeneratorTest extends TestCase
{
    public function test_keyword_extractor_priority_order(): void
    {
        $extractor = new SiteMcpKeywordExtractor;

        $fromFocus = $extractor->extract([
            'focus_keyword' => 'balo laptop',
            'seo_title' => 'Balo Laptop Cao Cấp | Shop',
            'title' => 'Balo',
            'slug' => 'balo-xyz',
        ]);
        self::assertSame('balo laptop', $fromFocus['keyword']);
        self::assertSame('seo_keyword', $fromFocus['source']);

        $fromSeoTitle = $extractor->extract([
            'focus_keyword' => '',
            'seo_title' => 'Túi Canvas Unisex | Brand',
            'title' => 'Ignored',
            'slug' => 'tui',
        ]);
        self::assertSame('Túi Canvas Unisex', $fromSeoTitle['keyword']);
        self::assertSame('seo_title', $fromSeoTitle['source']);
    }

    public function test_root_product_cat_only_becomes_important_pages_not_main_topics(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [
                $this->cat('Balo laptop', 'balo-laptop', 10, 0, 'balo laptop'),
                $this->cat('Balo Laptop', 'balo-laptop-2', 11, 0, 'Balo Laptop'),
                $this->cat('Balo học sinh', 'balo-hs', 12, 10, 'balo học sinh'),
            ],
            products: [
                $this->product('May Balo Dây Rút The Lin Q', 'may-balo-day-rut-the-lin-q', 100),
                $this->product('May Balo Mầm Non Bảo Quyên', 'may-balo-mam-non', 101),
            ],
            counts: ['post' => 3, 'page' => 1, 'product' => 2, 'product_cat' => 3, 'attachment' => 0],
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertSame('keyword_clusters.v1', $draft['generation']['topical_source']);
        self::assertCount(1, $draft['important_pages']);
        self::assertSame('balo laptop', $draft['important_pages'][0]['keyword'] ?? null);
        self::assertSame(3, $draft['counts']['product_cat']);
        self::assertSame(2, $draft['counts']['root_product_cat']);
        self::assertSame(2, $draft['counts']['product']);
        self::assertSame(2, $draft['counts']['excluded']['product']);
    }

    public function test_products_never_become_main_topics_or_important_pages(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'e-commerce',
            strategy: 'ecommerce_catalog',
            productCategories: [
                $this->cat('túi canvas', 'tui-canvas', 20, 0, 'túi canvas'),
            ],
            products: [
                $this->product('May Balo Quà Tặng Nitto Jokaso Oceana', 'sku-nitto', 200, 'nitto jokaso oceana'),
                $this->product('Balo laptop chống sốc mẫu 01', 'balo-chong-soc-01', 201, 'balo chống sốc mẫu 01'),
            ],
        ), [
            'source' => 'keyword_clusters.v1',
            'built_at' => gmdate('c'),
            'total_clustered_keywords' => 5,
            'topics' => [
                [
                    'cluster_ref' => 'ck_canvas',
                    'name' => 'túi canvas',
                    'weight' => 100.0,
                    'keyword_count' => 5,
                    'source' => 'auto',
                    'state' => 'active',
                    'priority' => null,
                    'intent' => 'commercial',
                    'coverage' => 'weak',
                    'dna' => [],
                ],
            ],
        ]);

        $topics = $draft['keyword_context']['main_topics'];
        self::assertSame(['túi canvas'], $topics);
        self::assertNotContains('nitto jokaso oceana', $topics);

        foreach ($draft['important_pages'] as $page) {
            self::assertNotSame('product', $page['type'] ?? $page['page_type'] ?? null);
            self::assertSame('product_cat', $page['taxonomy']);
            self::assertSame(0, (int) $page['parent_term_id']);
            self::assertStringNotContainsString('/product/', (string) ($page['url'] ?? ''));
        }

        $aiJson = (string) json_encode((new SiteMcpPreview)->present($draft)['ai_context']);
        self::assertStringNotContainsString('https://', $aiJson);
        self::assertStringNotContainsString('sku-nitto', $aiJson);
    }

    public function test_production_woocommerce_parent_zero_only(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            hasWoo: true,
            productCategories: [
                $this->cat('balo quà tặng', 'balo-qua-tang', 30, 0, 'balo quà tặng'),
                $this->cat('túi giữ nhiệt', 'tui-giu-nhiet', 31, 0, 'túi giữ nhiệt'),
                $this->cat('balo con', 'balo-con', 32, 30, 'balo con'),
            ],
            products: [
                $this->product('Catalog sample A (price 0)', 'sample-a', 300),
                $this->product('Catalog sample B (price 0)', 'sample-b', 301),
            ],
        ));

        self::assertSame('production', $draft['site']['website_type']);
        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertSame(2, $draft['counts']['products']);
        self::assertSame(2, $draft['counts']['root_product_cat']);
        self::assertCount(2, $draft['important_pages']);
        $pageKeywords = array_map(static fn (array $p): string => (string) ($p['keyword'] ?? ''), $draft['important_pages']);
        self::assertSame(['balo quà tặng', 'túi giữ nhiệt'], $pageKeywords);
        self::assertNotContains('balo con', $pageKeywords);
    }

    public function test_fail_closed_excludes_missing_taxonomy_parent_and_product_without_parent(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [
                // Missing taxonomy
                [
                    'url' => 'https://shop.example/maybe-cat/',
                    'title' => 'Guessed',
                    'seo_title' => 'Guessed',
                    'page_type' => 'product_category',
                    'focus_keyword' => 'guessed',
                    'taxonomy' => '',
                    'term_id' => 99,
                    'parent_term_id' => 0,
                ],
                // Missing parent_term_id key
                [
                    'url' => 'https://shop.example/product-category/orphan/',
                    'title' => 'Orphan',
                    'seo_title' => 'Orphan',
                    'page_type' => 'product_category',
                    'focus_keyword' => 'orphan',
                    'taxonomy' => 'product_cat',
                    'term_id' => 98,
                ],
                // Product mis-bucketed without parent
                [
                    'url' => 'https://shop.example/product/balo-anh-van-ape/',
                    'title' => 'Balo Anh Văn APE',
                    'seo_title' => 'Balo Anh Văn APE',
                    'page_type' => 'product',
                    'focus_keyword' => 'balo anh van',
                    'taxonomy' => 'product_cat',
                    'term_id' => 97,
                ],
                $this->cat('Balo học sinh', 'balo-hs', 40, 0, 'balo học sinh'),
            ],
            products: [
                $this->product('Balo Anh Văn APE', 'balo-anh-van-ape', 500),
            ],
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertCount(1, $draft['important_pages']);
        self::assertSame('balo học sinh', $draft['important_pages'][0]['keyword'] ?? null);
        self::assertSame('/product-category/balo-hs/', parse_url((string) $draft['important_pages'][0]['url'], PHP_URL_PATH));
    }

    public function test_no_root_categories_emits_warning_and_empty_topics(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'e-commerce',
            strategy: 'ecommerce_catalog',
            productCategories: [
                $this->cat('child only', 'child', 50, 49, 'child only'),
            ],
            products: [
                $this->product('SKU 1', 'sku-1', 600),
            ],
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertSame([], $draft['important_pages']);
        self::assertContains('ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE', $draft['keyword_context']['warnings']);
        self::assertSame(0, $draft['counts']['root_product_cat']);
        self::assertSame(1, $draft['counts']['product_cat']);
    }

    public function test_valid_category_named_balo_laptop_not_rejected_by_title_heuristic(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [
                $this->cat('Balo laptop', 'balo-laptop', 70, 0, 'Balo laptop'),
            ],
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertSame('product_cat', $draft['important_pages'][0]['taxonomy']);
        self::assertSame('Balo laptop', $draft['important_pages'][0]['keyword'] ?? null);
        self::assertNotEmpty($draft['important_pages']);
    }

    public function test_parent_integer_zero_survives_and_appears_in_main_topics(): void
    {
        $normalized = SiteMcpProductCatIdentity::normalizeVerified([
            'taxonomy' => 'product_cat',
            'term_id' => 15,
            'parent_term_id' => 0,
            'name' => 'Balo quà tặng',
            'slug' => 'balo-qua-tang',
            'url' => 'https://baloquatang.net/product-category/balo-qua-tang/',
            'post_count' => 12,
        ]);
        self::assertNotNull($normalized);
        self::assertSame(0, $normalized['parent_term_id']);
        self::assertSame('taxonomy', $normalized['page_type']);

        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [$normalized + ['title' => 'Balo quà tặng', 'focus_keyword' => 'balo quà tặng']],
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertSame('balo quà tặng', $draft['important_pages'][0]['keyword'] ?? null);
        self::assertSame(0, (int) $draft['important_pages'][0]['parent_term_id']);
    }

    public function test_child_and_product_excluded_from_main_topics(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [
                $this->cat('Root cat', 'root', 1, 0, 'root cat'),
                $this->cat('Child cat', 'child', 2, 1, 'child cat'),
            ],
            products: [
                $this->product('Individual SKU', 'sku-x', 900),
            ],
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        $pageKeywords = array_map(static fn (array $p): string => (string) ($p['keyword'] ?? ''), $draft['important_pages']);
        self::assertSame(['root cat'], $pageKeywords);
        self::assertNotContains('child cat', $pageKeywords);
        self::assertSame(1, $draft['counts']['child_product_cat']);
        self::assertSame(1, $draft['counts']['excluded']['child_product_cat']);
    }

    public function test_missing_parent_excluded_and_verified_wins_over_incomplete_duplicate(): void
    {
        $incomplete = [
            'url' => 'https://shop.example/product-category/stale/',
            'title' => 'Stale crawler',
            'seo_title' => 'Stale crawler',
            'page_type' => 'product_category',
            'focus_keyword' => 'stale',
            'taxonomy' => 'product_cat',
            'term_id' => 80,
            // missing parent_term_id
            'source' => 'crawler',
            'verified' => false,
        ];
        $verified = $this->cat('Verified root', 'verified-root', 80, 0, 'verified root');
        $deduped = SiteMcpProductCatIdentity::dedupeByTaxonomyTermId([$incomplete, $verified]);
        self::assertCount(1, $deduped);
        self::assertTrue((bool) $deduped[0]['verified']);
        self::assertSame(0, (int) $deduped[0]['parent_term_id']);

        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [$incomplete, $verified],
        ));
        // Generator root filter also drops incomplete; one important page from verified.
        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertSame(['verified root'], array_map(static fn (array $p): string => (string) ($p['keyword'] ?? ''), $draft['important_pages']));
    }

    public function test_old_plugin_unavailable_does_not_claim_zero_roots(): void
    {
        $fixture = $this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [],
            products: [
                $this->product('SKU', 'sku', 1),
            ],
            hasWoo: true,
        );
        $fixture['availability'] = ['product_cat_taxonomy' => 'unavailable'];
        $fixture['taxonomy_capability'] = [
            'product_category_taxonomy_export' => false,
            'known' => true,
        ];
        $fixture['counts']['root_product_cat'] = 0;
        $fixture['counts']['product_cat'] = 0;
        $fixture['counts']['product_cat_total'] = 0;

        $draft = $this->generator()->buildFromDiscovery($fixture);

        self::assertContains(SiteMcpProductCatIdentity::WARNING_CAPABILITY_MISSING, $draft['keyword_context']['warnings']);
        self::assertNotContains('ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE', $draft['keyword_context']['warnings']);
        self::assertSame('unavailable', $draft['availability']['product_cat_taxonomy']);
        self::assertSame([], $draft['keyword_context']['main_topics']);
    }

    public function test_available_taxonomy_with_zero_roots_is_distinguishable(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'e-commerce',
            strategy: 'ecommerce_catalog',
            productCategories: [
                $this->cat('only child', 'only-child', 55, 54, 'only child'),
            ],
        ));

        self::assertSame('available', $draft['availability']['product_cat_taxonomy']);
        self::assertContains('ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE', $draft['keyword_context']['warnings']);
        self::assertNotContains(SiteMcpProductCatIdentity::WARNING_CAPABILITY_MISSING, $draft['keyword_context']['warnings']);
        self::assertSame(0, $draft['counts']['root_product_cat']);
        self::assertSame(1, $draft['counts']['child_product_cat']);
    }

    public function test_duplicate_taxonomy_term_produces_one_topic(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [
                $this->cat('Balo học sinh', 'balo-hs', 40, 0, 'balo học sinh'),
                $this->cat('Balo học sinh copy', 'balo-hs-copy', 40, 0, 'balo học sinh'),
            ],
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertCount(1, $draft['important_pages']);
        self::assertSame('balo học sinh', $draft['important_pages'][0]['keyword'] ?? null);
    }

    public function test_live_taxonomy_row_with_parent_zero_populates_main_topics(): void
    {
        $liveRoot = SiteMcpProductCatIdentity::normalizeVerified([
            'taxonomy' => 'product_cat',
            'term_id' => 3,
            'parent_term_id' => 0,
            'name' => 'Balo quà tặng',
            'slug' => 'balo-qua-tang',
            'url' => 'https://baloquatang.net/product-category/balo-qua-tang/',
            'post_count' => 20,
            'source' => 'taxonomy_export',
        ]);
        self::assertNotNull($liveRoot);

        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'production',
            strategy: 'production_catalog',
            productCategories: [
                // Stale staging: only child, no root identity
                $this->cat('Child only', 'child', 4, 3, 'child only'),
                $liveRoot + ['title' => 'Balo quà tặng', 'focus_keyword' => 'balo quà tặng'],
            ],
            products: [
                $this->product('SKU', 'sku', 999),
            ],
            hasWoo: true,
        ));

        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertContains('balo quà tặng', array_map(static fn (array $p): string => (string) ($p['keyword'] ?? ''), $draft['important_pages']));
        self::assertGreaterThanOrEqual(1, (int) $draft['counts']['root_product_cat']);
        self::assertNotEmpty($draft['important_pages']);
    }

    public function test_contact_discovery_quality_rules(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html><html><body>
<script type = "application/ld+json" >
{"@graph":[{"@type":"Organization","name":"Shop","contactPoint":{"@type":"ContactPoint","telephone":"+84 901 111 222","email":"sales@shop.example"},"sameAs":["https://www.facebook.com/shop","https://twitter.com/intent/tweet?text=x"]}]}
</script>
<script type="application/ld+json">{not-json</script>
<a href="tel:%2B84903334455?ext=12">Call</a>
<a href="mailto:hi@shop.example?subject=hi">Mail</a>
<a href="https://m.facebook.com/shop">FB</a>
<a href="https://notfacebook.com/shop">Fake</a>
<a href="https://facebook.com/sharer/sharer.php?u=x">Share</a>
<a href="https://www.instagram.com/shop">IG</a>
</body></html>
HTML;

        $parsed = (new SiteMcpContactDiscovery)->parse($html, 'https://shop.example');
        $phones = array_column($parsed['phones'], 'value');
        $emails = array_column($parsed['emails'], 'value');
        $networks = array_column($parsed['socials'], 'network');

        self::assertNotEmpty($phones);
        self::assertContains('sales@shop.example', $emails);
        self::assertContains('hi@shop.example', $emails);
        self::assertContains('facebook', $networks);
        self::assertContains('instagram', $networks);
        self::assertNotContains('notfacebook.com', implode(' ', array_column($parsed['socials'], 'url')));
        foreach ($parsed['socials'] as $social) {
            self::assertStringNotContainsString('/sharer', (string) ($social['url'] ?? ''));
            self::assertStringNotContainsString('/intent/', (string) ($social['url'] ?? ''));
        }

        // Duplicate facebook profile URLs keep strongest confidence.
        $fbConfidences = [];
        foreach ($parsed['socials'] as $social) {
            if (($social['network'] ?? '') !== 'facebook') {
                continue;
            }
            $fbConfidences[] = (float) ($social['confidence'] ?? 0);
        }
        self::assertNotEmpty($fbConfidences);
        self::assertGreaterThanOrEqual(0.8, max($fbConfidences));

        // Malformed HTML must not throw.
        $broken = (new SiteMcpContactDiscovery)->parse('<div><a href="tel:0901234567">x', 'https://shop.example');
        self::assertNotEmpty($broken['phones']);
    }

    public function test_news_without_cluster_profile_has_empty_main_topics(): void
    {
        $draft = $this->generator()->buildFromDiscovery([
            'domain' => 'news.example',
            'website_type' => 'news',
            'discovery_strategy' => 'news_manual',
            'site_title' => 'News',
            'brand' => 'News',
            'official' => [
                'short_description' => 'x',
                'company_short_identity' => 'Tin tức nhanh',
                'tone' => '',
                'links' => [],
                'cta' => [],
            ],
            'official_exists' => true,
            'sync_run_id' => null,
            'manual_link_keywords' => ['kinh tế'],
            'product_categories' => [],
            'service_categories' => [
                $this->cat('Kinh tế', 'kinh-te', 1, null, 'kinh tế'),
            ],
            'products' => [],
            'product_count' => 0,
            'product_category_count' => 0,
            'has_woocommerce_catalog' => false,
            'news_candidates' => [
                $this->cat('Kinh tế', 'kinh-te', 1, null, 'kinh tế'),
            ],
            'counts' => ['post' => 5, 'page' => 2, 'product' => 0, 'product_cat' => 0, 'attachment' => 1],
            'homepage_html' => '',
        ]);

        self::assertSame([], $draft['important_pages']);
        self::assertSame([], $draft['keyword_context']['main_topics']);
        self::assertSame('Tin tức nhanh', $draft['site']['company_short_identity']);
        self::assertNotEmpty($draft['discovery_candidates']);
        self::assertFalse($draft['generation']['official_fields_modified']);
    }

    public function test_article_and_keyword_context_previews_have_no_urls_or_placeholders(): void
    {
        $draft = $this->generator()->buildFromDiscovery($this->catalogFixture(
            websiteType: 'e-commerce',
            strategy: 'ecommerce_catalog',
            productCategories: [
                $this->cat('balo laptop', 'balo-laptop', 50, 0, 'balo laptop'),
            ],
            homepageHtml: '<a href="tel:0901234567">Call</a><a href="mailto:hi@shop.example">Mail</a>',
        ), [
            'source' => 'keyword_clusters.v1',
            'built_at' => gmdate('c'),
            'total_clustered_keywords' => 10,
            'topics' => [
                [
                    'cluster_ref' => 'ck_balo',
                    'name' => 'balo laptop',
                    'weight' => 100.0,
                    'keyword_count' => 10,
                    'source' => 'auto',
                    'state' => 'active',
                    'priority' => null,
                    'intent' => 'commercial',
                    'coverage' => 'medium',
                    'dna' => [],
                ],
            ],
        ]);

        $preview = (new SiteMcpPreview)->present($draft);
        $article = $preview['article_context'];
        $keyword = $preview['keyword_preview'];

        self::assertFalse($article['has_unresolved']);
        self::assertStringContainsString('ARTICLE CONTEXT PREVIEW', $article['text']);
        self::assertStringNotContainsString('[phone]', $article['text']);
        self::assertStringContainsString('KEYWORD CONTEXT PREVIEW', $keyword['text']);
        self::assertStringContainsString('Topical profile:', $keyword['text']);
        self::assertStringContainsString('balo laptop — 100%', $keyword['text']);
        self::assertStringNotContainsString('https://', (string) json_encode($preview['ai_context']));
        self::assertStringContainsString('https://', (string) ($draft['important_pages'][0]['url'] ?? ''));
        self::assertFalse($preview['official_fields_modified']);
    }

    public function test_contact_discovery_from_json_ld_and_links(): void
    {
        $html = <<<'HTML'
<script type="application/ld+json">
{"@type":"Organization","telephone":"+84901112233","email":"sales@shop.example","sameAs":["https://facebook.com/shop","https://www.instagram.com/shop"]}
</script>
<a href="tel:090998877">Call</a>
<a href="https://zalo.me/123">Zalo</a>
HTML;

        $parsed = (new SiteMcpContactDiscovery)->parse($html, 'https://shop.example');
        self::assertNotEmpty($parsed['phones']);
        self::assertNotEmpty($parsed['emails']);
        $networks = array_column($parsed['socials'], 'network');
        self::assertContains('facebook', $networks);
        self::assertContains('instagram', $networks);
        self::assertContains('zalo', $networks);
    }

    public function test_context_assembler_rejects_unresolved_placeholders(): void
    {
        $assembler = new SiteMcpContextAssembler;
        $this->expectException(\RuntimeException::class);
        $assembler->assertNoUnresolvedPlaceholders('Call [phone] now');
    }

    public function test_official_guard_rejects_non_draft_meta_key(): void
    {
        $guard = (new ReflectionClass(SiteMcpOfficialGuard::class))->newInstanceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $guard->assertMetaKeyAllowed(SiteDomainPromptContextService::META_KEY);
    }

    public function test_official_guard_requires_official_fields_modified_false(): void
    {
        $guard = (new ReflectionClass(SiteMcpOfficialGuard::class))->newInstanceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $guard->assertDraftPayloadSafe([
            'generation' => ['official_fields_modified' => true],
        ]);
    }

    public function test_draft_preview_accordion_lives_on_edit_domain_sidebar(): void
    {
        $edit = (string) file_get_contents((new ReflectionClass(EditDomain::class))->getFileName());
        $editView = dirname((new ReflectionClass(EditDomain::class))->getFileName(), 5)
            .'/resources/views/filament/resources/domain-resource/pages/edit-domain.blade.php';
        $panel = dirname((new ReflectionClass(EditDomain::class))->getFileName(), 5)
            .'/resources/views/filament/resources/domain-resource/pages/partials/site-mcp-draft-panel.blade.php';
        $general = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        $generalView = (string) file_get_contents(
            dirname((new ReflectionClass(GeneralDomain::class))->getFileName(), 5)
            .'/resources/views/filament/resources/domain-resource/pages/general-domain.blade.php'
        );
        $resource = (string) file_get_contents((new ReflectionClass(DomainResource::class))->getFileName());
        $panelSrc = (string) file_get_contents($panel);
        $form = (string) file_get_contents(
            dirname((new ReflectionClass(EditDomain::class))->getFileName(), 2)
            .'/Forms/DomainTechnicalSeoForm.php'
        );

        self::assertFileExists($editView);
        self::assertFileExists($panel);
        self::assertStringContainsString("route('/{record}/edit')", $resource);
        self::assertStringContainsString('generateSiteMcpDraftAction', $edit);
        self::assertStringContainsString('seo-site-mcp-draft-drawer', (string) file_get_contents($editView));
        self::assertStringContainsString('Draft only — official data unchanged', $panelSrc);
        self::assertStringContainsString('Full Site MCP Draft', $panelSrc);
        self::assertStringContainsString('Article Context Preview', $panelSrc);
        self::assertStringContainsString('New Keyword Context Preview', $panelSrc);
        self::assertStringContainsString('Use Site MCP', $panelSrc);
        self::assertStringContainsString('Select all', $panelSrc);
        self::assertStringContainsString('company_short_identity', $form);
        self::assertStringContainsString("Repeater::make('phones')", $form);
        self::assertStringContainsString('cta_intro', $form);
        self::assertStringNotContainsString('phone_1', $form);
        self::assertStringNotContainsString('Apply', $panelSrc);
        self::assertStringNotContainsString('Merge', $panelSrc);
        self::assertStringNotContainsString('generateSiteMcpDraftAction', $general);
        self::assertStringNotContainsString('CanonicalCapabilityRegistry', $edit);
    }

    public function test_generator_services_do_not_write_official_prompt_context(): void
    {
        foreach ([
            SiteMcpGenerator::class,
            SiteMcpDiscovery::class,
            SiteMcpDraft::class,
            SiteMcpPreview::class,
            SiteMcpKeywordExtractor::class,
            SiteMcpOfficialGuard::class,
            SiteMcpContactDiscovery::class,
            SiteMcpContextAssembler::class,
        ] as $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            self::assertStringNotContainsString('saveForSite(', $source, $class);
            self::assertStringNotContainsString('writePayload(', $source, $class);
        }

        $draftSource = (string) file_get_contents((new ReflectionClass(SiteMcpDraft::class))->getFileName());
        self::assertStringContainsString("META_KEY = 'site_mcp_draft'", $draftSource);
        self::assertStringContainsString('SiteMcpOfficialGuard', $draftSource);
    }

    public function test_keyword_cli_exposes_site_mcp_flags_not_workspace(): void
    {
        $catalog = (string) file_get_contents(
            dirname((new ReflectionClass(SiteMcpDraft::class))->getFileName(), 2)
            .'/AgentWorkspace/Cli/AgentCliCommandCatalog.php'
        );
        $js = (string) file_get_contents(
            dirname((new ReflectionClass(SiteMcpDraft::class))->getFileName(), 3)
            .'/resources/js/agent/command-catalog.js'
        );
        $page = (string) file_get_contents((new ReflectionClass(EditDomain::class))->getFileName());
        // Wrong class for page — use AgentWorkspacePage path via catalog sibling.
        $agentPage = dirname((new ReflectionClass(SiteMcpDraft::class))->getFileName(), 3)
            .'/Filament/Pages/AgentWorkspacePage.php';
        $agentSrc = (string) file_get_contents($agentPage);
        unset($page);

        self::assertStringContainsString('--keyword=""', $catalog);
        self::assertStringContainsString('--use-site-mcp="yes"', $catalog);
        self::assertStringNotContainsString('--workspace=""', $catalog);
        self::assertStringContainsString("'local_only' => true", $catalog);
        self::assertStringContainsString('requires_site', $catalog);
        self::assertStringContainsString('SiteMcpKeywordSuggestCliService', $agentSrc);
        self::assertStringContainsString('handleKeywordSuggestCli', $agentSrc);
        self::assertStringContainsString('storeKeywordContext', $agentSrc);
        self::assertStringContainsString('--use-site-mcp="yes"', $js);
        self::assertStringNotContainsString('/keyword-suggest --workspace=""', $js);
        self::assertStringContainsString('local_only: true', $js);
    }

    /**
     * @param  list<array<string, mixed>>  $productCategories
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $serviceCategories
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function catalogFixture(
        string $websiteType,
        string $strategy,
        array $productCategories = [],
        array $products = [],
        array $serviceCategories = [],
        bool $hasWoo = false,
        array $counts = [],
        string $homepageHtml = '',
    ): array {
        return [
            'domain' => 'https://shop.example',
            'website_type' => $websiteType,
            'discovery_strategy' => $strategy,
            'site_title' => 'Shop',
            'brand' => 'Shop',
            'official' => [
                'short_description' => 'Official short description stays',
                'company_short_identity' => 'Xưởng sản xuất balo theo yêu cầu.',
                'tone' => 'friendly',
                'cta_intro' => 'Nhắc liên hệ tự nhiên.',
                'links' => [['keyword' => 'manual', 'link' => 'https://shop.example/manual']],
                'cta' => [],
                'phones' => [['value' => '0123']],
            ],
            'official_exists' => true,
            'sync_run_id' => 99,
            'manual_link_keywords' => ['manual'],
            'product_categories' => $productCategories,
            'service_categories' => $serviceCategories,
            'products' => $products,
            'product_count' => count($products),
            'product_category_count' => count($productCategories),
            'has_woocommerce_catalog' => $hasWoo || $productCategories !== [] || $products !== [],
            'news_candidates' => [],
            'counts' => $counts !== [] ? $counts : [
                'post' => 0,
                'page' => 0,
                'product' => count($products),
                'product_cat' => count($productCategories),
                'product_cat_total' => count($productCategories),
                'root_product_cat' => count(array_filter(
                    $productCategories,
                    static fn (array $row): bool => (int) ($row['parent_term_id'] ?? -1) === 0
                        && ($row['taxonomy'] ?? '') === 'product_cat'
                        && (int) ($row['term_id'] ?? 0) > 0,
                )),
                'child_product_cat' => count(array_filter(
                    $productCategories,
                    static fn (array $row): bool => (int) ($row['parent_term_id'] ?? -1) > 0
                        && ($row['taxonomy'] ?? '') === 'product_cat'
                        && (int) ($row['term_id'] ?? 0) > 0,
                )),
                'incomplete_product_cat' => 0,
                'attachment' => 0,
            ],
            'availability' => [
                'product_cat_taxonomy' => 'available',
            ],
            'taxonomy_capability' => [
                'product_category_taxonomy_export' => true,
                'known' => true,
            ],
            'homepage_url' => 'https://shop.example',
            'homepage_html' => $homepageHtml,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cat(
        string $title,
        string $slug,
        int $termId,
        ?int $parentTermId,
        string $focus,
    ): array {
        $row = [
            'article_id' => $termId,
            'url' => 'https://shop.example/product-category/'.$slug.'/',
            'title' => $title,
            'name' => $title,
            'seo_title' => $title,
            'page_type' => 'taxonomy',
            'focus_keyword' => $focus,
            'slug' => $slug,
            'taxonomy' => 'product_cat',
            'term_id' => $termId,
            'source' => 'taxonomy_sync',
            'verified' => true,
        ];
        if ($parentTermId !== null) {
            $row['parent_term_id'] = $parentTermId;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $title, string $slug, int $id, string $focus = ''): array
    {
        return [
            'article_id' => $id,
            'url' => 'https://shop.example/product/'.$slug.'/',
            'title' => $title,
            'seo_title' => $title,
            'page_type' => 'product',
            'focus_keyword' => $focus,
            'slug' => $slug,
            'taxonomy' => '',
            'term_id' => $id,
            'parent_term_id' => null,
            'source' => 'articles',
        ];
    }

    private function generator(): SiteMcpGenerator
    {
        $discovery = (new ReflectionClass(SiteMcpDiscovery::class))->newInstanceWithoutConstructor();
        $draft = (new ReflectionClass(SiteMcpDraft::class))->newInstanceWithoutConstructor();
        $keywords = new SiteMcpKeywordExtractor;
        $contacts = new SiteMcpContactDiscovery;

        return new SiteMcpGenerator($discovery, $draft, $keywords, $contacts);
    }
}
