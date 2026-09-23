<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
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

        self::assertStringContainsString('SiteSyncPreflightContentComparison', $src);
        self::assertStringContainsString('countLocal', $evaluate);
        self::assertStringContainsString('normalizeRemoteDiscover', $v3);
        self::assertStringNotContainsString("\$discover['total']", $v3);
        self::assertStringContainsString('Data health = broad SEO inventory', $evaluate);
    }

    public function test_local_count_sql_requires_wp_backed_and_inventory_policy(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncPreflightContentComparison::class))->getFileName()
        );
        $method = $this->methodBody($src, 'countLocal');

        self::assertStringContainsString('wal.wp_post_id', $method);
        self::assertStringContainsString("'>', 0", $method);
        self::assertStringContainsString('isSeoInventoryCandidate', $method);
        self::assertStringContainsString('wp_is_term', $method);
    }

    private function methodBody(string $src, string $method): string
    {
        $class = str_contains($src, 'class SiteSyncPreflightService')
            ? SiteSyncPreflightService::class
            : SiteSyncPreflightContentComparison::class;
        if ($method === 'evaluate' || $method === 'fetchRemoteCountsViaV3') {
            $class = SiteSyncPreflightService::class;
        } elseif ($method === 'countLocal') {
            $class = SiteSyncPreflightContentComparison::class;
        }
        $ref = new ReflectionMethod($class, $method);
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
