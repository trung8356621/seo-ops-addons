<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use Omnichannel\Addons\Content\Support\NativeContentTypeMapper;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightContentComparison;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Preflight WP↔local comparison universe (distinct from SEO data health).
 */
final class SiteSyncPreflightContentComparisonTest extends TestCase
{
    public function test_remote_total_never_uses_discover_total_with_terms(): void
    {
        $cmp = new SiteSyncPreflightContentComparison();
        $out = $cmp->normalizeRemoteDiscover([
            'total' => 1177,
            'by_content_type' => ['post' => 601, 'page' => 10, 'product' => 514],
            'resources' => [
                'content' => ['total' => 1125],
                'terms' => ['total' => 51],
            ],
        ]);

        self::assertSame(1125, $out['total']);
        self::assertSame(601, $out['post']);
        self::assertSame(10, $out['page']);
        self::assertSame(514, $out['product']);
        self::assertSame(0, $out['other']);
        self::assertFalse($out['authoritative']);
        self::assertSame(SiteSyncPreflightContentComparison::SOURCE_CONTENT_TYPE_FALLBACK, $out['source']);
        self::assertSame(
            $out['total'],
            $out['post'] + $out['page'] + $out['product'] + $out['other'],
        );
    }

    public function test_terms_do_not_inflate_comparison_total(): void
    {
        $cmp = new SiteSyncPreflightContentComparison();
        $out = $cmp->normalizeRemoteDiscover([
            'total' => 1177,
            'by_content_type' => ['post' => 1125],
            'resources' => ['terms' => ['total' => 51]],
        ]);

        self::assertSame(1125, $out['total']);
        self::assertNotSame(1177, $out['total']);
        self::assertFalse($out['authoritative']);
    }

    public function test_native_post_type_excludes_system_blocks_symmetrically(): void
    {
        self::assertTrue(ArticleSeoInventoryPolicy::isSystemWpPostType('blocks'));

        $cmp = new SiteSyncPreflightContentComparison();
        $out = $cmp->fromNativePostTypeCounts([
            'post' => 600,
            'page' => 10,
            'product' => 514,
            'blocks' => 1,
        ]);

        self::assertSame(1124, $out['total']);
        self::assertSame(600, $out['post']);
        self::assertSame(10, $out['page']);
        self::assertSame(514, $out['product']);
    }

    public function test_supported_custom_cpt_maps_symmetrically_not_dropped(): void
    {
        self::assertSame('page', NativeContentTypeMapper::map('landing_page')->value);
        self::assertSame('product', NativeContentTypeMapper::map('machine')->value);
        self::assertFalse(ArticleSeoInventoryPolicy::isSystemWpPostType('landing_page'));
        self::assertFalse(ArticleSeoInventoryPolicy::isSystemWpPostType('machine'));

        $cmp = new SiteSyncPreflightContentComparison();
        $out = $cmp->fromNativePostTypeCounts([
            'post' => 100,
            'landing_page' => 5,
            'machine' => 3,
            'blocks' => 2,
        ]);

        // Custom CPTs counted into mapped buckets; system CPT dropped — not post/page/product-only accident.
        self::assertSame(108, $out['total']);
        self::assertSame(100, $out['post']);
        self::assertSame(5, $out['page']);
        self::assertSame(3, $out['product']);
    }

    public function test_site_map_overrides_custom_cpt_bucket(): void
    {
        $cmp = new SiteSyncPreflightContentComparison();
        $out = $cmp->fromNativePostTypeCounts(
            ['portfolio' => 7, 'blocks' => 1],
            ['portfolio' => 'page'],
        );

        self::assertSame(7, $out['total']);
        self::assertSame(0, $out['post']);
        self::assertSame(7, $out['page']);
    }

    public function test_native_counts_preferred_over_by_content_type(): void
    {
        $cmp = new SiteSyncPreflightContentComparison();
        $out = $cmp->normalizeRemoteDiscover([
            'total' => 9999,
            'by_content_type' => ['post' => 601, 'page' => 11, 'product' => 514],
            'by_native_post_type' => [
                'post' => 600,
                'page' => 10,
                'product' => 514,
                'blocks' => 1,
            ],
        ]);

        self::assertSame(1124, $out['total']);
        self::assertSame(600, $out['post']);
        self::assertSame(10, $out['page']);
        self::assertTrue($out['authoritative']);
        self::assertSame(SiteSyncPreflightContentComparison::SOURCE_NATIVE, $out['source']);
    }

    public function test_old_plugin_payload_without_by_native_is_non_authoritative(): void
    {
        $cmp = new SiteSyncPreflightContentComparison();
        // Site 7-style old plugin: by_content_type inflated (blocks folded into post) vs true 1124.
        $out = $cmp->normalizeRemoteDiscover([
            'total' => 1177,
            'by_content_type' => ['post' => 601, 'page' => 10, 'product' => 514],
            'resources' => [
                'content' => ['total' => 1125],
                'terms' => ['total' => 52],
            ],
        ]);

        self::assertFalse($out['authoritative']);
        self::assertSame(SiteSyncPreflightContentComparison::SOURCE_CONTENT_TYPE_FALLBACK, $out['source']);
        self::assertSame(1125, $out['total']);
    }

    public function test_old_plugin_count_skew_does_not_force_full_sync_recommendation(): void
    {
        $svc = new ReflectionClass(SiteSyncPreflightService::class);
        $method = $svc->getMethod('resolveRecommendation');
        $method->setAccessible(true);
        $instance = $svc->newInstanceWithoutConstructor();

        $delta = ['total' => 2, 'post' => 2, 'page' => 0, 'product' => 0];
        $green = ArticleRequiredDataRegistry::SEVERITY_GREEN;

        $nonAuth = $method->invoke($instance, 0, $delta, $green, false);
        self::assertSame(SiteSyncPreflightService::RECOMMEND_SYNCED, $nonAuth['recommendation']);
        self::assertStringContainsString('authoritative', $nonAuth['message']);
        self::assertStringNotContainsString('Đồng bộ toàn bộ sẽ kiểm tra lại inventory', $nonAuth['message']);

        $auth = $method->invoke($instance, 0, $delta, $green, true);
        self::assertSame(SiteSyncPreflightService::RECOMMEND_FULL, $auth['recommendation']);
    }

    public function test_wp_backed_identity_ssot_is_link_table_not_articles_column(): void
    {
        self::assertTrue(ArticleSeoInventoryPolicy::isWpBacked(42));
        self::assertFalse(ArticleSeoInventoryPolicy::isWpBacked(0));
        self::assertFalse(ArticleSeoInventoryPolicy::isWpBacked(null));

        $preflightSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncPreflightContentComparison::class))->getFileName()
        );
        $countLocal = $this->methodBody($preflightSrc, 'countLocal');
        self::assertStringContainsString('WpBackedComparableInventory::countByContentType', $countLocal);

        $inventorySrc = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Support\WpBackedComparableInventory::class))->getFileName()
        );
        $rows = $this->methodBodyForClass(
            $inventorySrc,
            \Omnichannel\Addons\Content\Support\WpBackedComparableInventory::class,
            'rows',
        );

        // INNER JOIN link table + wal.wp_post_id > 0 — same predicate as isWpBacked on link SoT.
        self::assertStringContainsString('wordpress_article_links as wal', $rows);
        self::assertStringContainsString("->join('wordpress_article_links as wal'", $rows);
        self::assertStringContainsString('wal.wp_post_id', $rows);
        self::assertStringContainsString("'>', 0", $rows);
        // Must not use retired articles.wp_post_id as membership SSOT.
        self::assertStringNotContainsString('a.wp_post_id', $rows);
        self::assertStringContainsString('isSeoInventoryCandidate', $rows);
    }

    public function test_total_equals_sum_of_type_rows(): void
    {
        $cmp = new SiteSyncPreflightContentComparison();
        foreach ([
            $cmp->fromContentTypeCounts(['post' => 10, 'page' => 2, 'product' => 3]),
            $cmp->fromNativePostTypeCounts(['post' => 10, 'page' => 2, 'product' => 3, 'blocks' => 9]),
        ] as $out) {
            self::assertSame(
                $out['total'],
                $out['post'] + $out['page'] + $out['product'] + $out['other'],
            );
        }
    }

    public function test_preflight_service_wires_comparison_not_health_denominator(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncPreflightService::class))->getFileName()
        );
        $evaluate = $this->methodBody($src, 'evaluate');
        $v3 = $this->methodBody($src, 'fetchRemoteCountsViaV3');
        $resolve = $this->methodBody($src, 'resolveRecommendation');

        self::assertStringContainsString('SiteSyncPreflightContentComparison', $src);
        self::assertStringContainsString('countLocal', $evaluate);
        self::assertStringContainsString('normalizeRemoteDiscover', $v3);
        self::assertStringContainsString('count_comparison_authoritative', $evaluate);
        self::assertStringContainsString('countAuthoritative', $resolve);
        self::assertStringNotContainsString("\$discover['total']", $v3);
        self::assertStringContainsString('Data health = broad SEO inventory', $evaluate);
    }

    public function test_local_count_sql_requires_wp_backed_and_inventory_policy(): void
    {
        $preflightSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncPreflightContentComparison::class))->getFileName()
        );
        $countLocal = $this->methodBody($preflightSrc, 'countLocal');
        self::assertStringContainsString('WpBackedComparableInventory::countByContentType', $countLocal);

        $inventorySrc = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Support\WpBackedComparableInventory::class))->getFileName()
        );
        $rows = $this->methodBodyForClass(
            $inventorySrc,
            \Omnichannel\Addons\Content\Support\WpBackedComparableInventory::class,
            'rows',
        );

        self::assertStringContainsString('wal.wp_post_id', $rows);
        self::assertStringContainsString("'>', 0", $rows);
        self::assertStringContainsString('isSeoInventoryCandidate', $rows);
        self::assertStringContainsString('wp_is_term', $rows);
        self::assertStringContainsString('deleted_at', $rows);
    }

    private function methodBody(string $src, string $method): string
    {
        $class = match ($method) {
            'evaluate', 'fetchRemoteCountsViaV3', 'resolveRecommendation' => SiteSyncPreflightService::class,
            'countLocal' => SiteSyncPreflightContentComparison::class,
            default => SiteSyncPreflightContentComparison::class,
        };

        return $this->methodBodyForClass($src, $class, $method);
    }

    private function methodBodyForClass(string $src, string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
