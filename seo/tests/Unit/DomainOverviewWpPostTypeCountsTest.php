<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\WpBackedComparableInventory;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightContentComparison;
use ReflectionClass;
use Tests\TestCase;

/**
 * Domain Overview “Nội dung WordPress” must use WP-backed comparable membership
 * (same universe as Site Sync Preflight), not raw article_meta.wp_post_type rows.
 */
final class DomainOverviewWpPostTypeCountsTest extends TestCase
{
    private const SITE_ID = 55;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
    }

    public function test_get_wp_post_type_counts_delegates_to_shared_inventory(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(DomainOverviewService::class))->getFileName()
        );
        $method = $this->methodBody($src, DomainOverviewService::class, 'getWpPostTypeCounts');

        self::assertStringContainsString('WpBackedComparableInventory::countByWpPostType', $method);
        self::assertStringNotContainsString('ArticleMeta::query()', $method);
    }

    public function test_counts_exclude_stale_local_term_and_system_inventory(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 101);
        $this->insertArticle(2, 'page', 'page', wpPostId: 102);
        $this->insertArticle(3, 'product', 'product', wpPostId: 103);
        $this->insertArticle(4, 'post', 'post', wpPostId: 104);
        // Inflators (previous Domain Overview bug):
        $this->insertArticle(5, 'post', 'post', wpPostId: 105, deletedAt: '2026-03-01 00:00:00');
        $this->insertArticle(6, 'post', 'post', wpPostId: 0);
        $this->insertArticle(7, 'category', 'post', wpPostId: 107, isTerm: true);
        $this->insertArticle(8, 'blocks', 'post', wpPostId: 108);
        $this->insertArticle(9, 'wp_block', 'post', wpPostId: 109);
        $this->insertArticle(10, 'post', 'post', wpPostId: null);

        $counts = (new DomainOverviewService())->getWpPostTypeCounts(self::SITE_ID);
        $preflight = (new SiteSyncPreflightContentComparison())->countLocal(self::SITE_ID);

        self::assertSame(['post' => 2, 'page' => 1, 'product' => 1], $counts);
        self::assertSame(4, array_sum($counts));
        self::assertSame(4, $preflight['total']);
        self::assertSame($counts['post'], $preflight['post']);
        self::assertSame($counts['page'], $preflight['page']);
        self::assertSame($counts['product'], $preflight['product']);
        self::assertSame(
            WpBackedComparableInventory::countByWpPostType(self::SITE_ID),
            $counts,
        );
    }

    private function methodBody(string $src, string $class, string $method): string
    {
        $ref = new \ReflectionMethod($class, $method);
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id');
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('article_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('wordpress_article_links', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->unsignedInteger('site_id')->nullable();
            $table->unsignedInteger('wp_post_id')->nullable();
        });
    }

    private function insertArticle(
        int $id,
        string $wpPostType,
        string $contentType,
        ?int $wpPostId = null,
        bool $isTerm = false,
        ?string $deletedAt = null,
    ): void {
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $id,
            'site_id' => self::SITE_ID,
            'status' => 'publish',
            'deleted_at' => $deletedAt,
        ]);
        DB::connection('omi_seo_ai')->table('article_meta')->insert([
            ['article_id' => $id, 'meta_key' => 'wp_post_type', 'meta_value' => $wpPostType],
            ['article_id' => $id, 'meta_key' => 'content_type', 'meta_value' => $contentType],
            ['article_id' => $id, 'meta_key' => 'wp_is_term', 'meta_value' => $isTerm ? '1' : '0'],
        ]);
        if ($wpPostId !== null) {
            DB::connection('omi_seo_ai')->table('wordpress_article_links')->insert([
                'article_id' => $id,
                'site_id' => self::SITE_ID,
                'wp_post_id' => $wpPostId,
            ]);
        }
    }
}
