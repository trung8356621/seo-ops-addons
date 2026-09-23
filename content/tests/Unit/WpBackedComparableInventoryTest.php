<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\WpBackedComparableInventory;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightContentComparison;
use Tests\TestCase;

/**
 * WP-backed comparable inventory membership (Domain Overview + Preflight).
 */
final class WpBackedComparableInventoryTest extends TestCase
{
    private const SITE_ID = 91;

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

    public function test_counts_wp_backed_normal_post_page_product(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 10);
        $this->insertArticle(2, 'page', 'page', wpPostId: 20);
        $this->insertArticle(3, 'product', 'product', wpPostId: 30);
        $this->insertArticle(4, 'post', 'post', wpPostId: 40);

        $byType = WpBackedComparableInventory::countByWpPostType(self::SITE_ID);
        $byCt = WpBackedComparableInventory::countByContentType(self::SITE_ID);

        self::assertSame(['post' => 2, 'page' => 1, 'product' => 1], $byType);
        self::assertSame(4, $byCt['total']);
        self::assertSame(2, $byCt['post']);
        self::assertSame(1, $byCt['page']);
        self::assertSame(1, $byCt['product']);
    }

    public function test_excludes_soft_deleted_articles(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 10);
        $this->insertArticle(2, 'post', 'post', wpPostId: 20, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(['post' => 1], WpBackedComparableInventory::countByWpPostType(self::SITE_ID));
        self::assertSame(1, WpBackedComparableInventory::countByContentType(self::SITE_ID)['total']);
    }

    public function test_excludes_rows_without_wp_post_id_gt_zero(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 10);
        $this->insertArticle(2, 'post', 'post', wpPostId: 0);
        $this->insertArticle(3, 'page', 'page', wpPostId: null);

        self::assertSame(['post' => 1], WpBackedComparableInventory::countByWpPostType(self::SITE_ID));
        self::assertSame(1, WpBackedComparableInventory::countByContentType(self::SITE_ID)['total']);
    }

    public function test_excludes_taxonomy_terms(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 10);
        $this->insertArticle(2, 'category', 'post', wpPostId: 20, isTerm: true);

        self::assertSame(['post' => 1], WpBackedComparableInventory::countByWpPostType(self::SITE_ID));
        self::assertSame(1, WpBackedComparableInventory::countByContentType(self::SITE_ID)['post']);
    }

    public function test_excludes_system_cpts(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 10);
        $this->insertArticle(2, 'blocks', 'post', wpPostId: 20);
        $this->insertArticle(3, 'wp_block', 'post', wpPostId: 30);
        $this->insertArticle(4, 'wp_template', 'page', wpPostId: 40);
        $this->insertArticle(5, 'attachment', 'post', wpPostId: 50);

        self::assertSame(['post' => 1], WpBackedComparableInventory::countByWpPostType(self::SITE_ID));
        self::assertSame(1, WpBackedComparableInventory::countByContentType(self::SITE_ID)['total']);
    }

    public function test_domain_overview_semantics_align_with_preflight_wp_backed(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 10);
        $this->insertArticle(2, 'page', 'page', wpPostId: 20);
        $this->insertArticle(3, 'product', 'product', wpPostId: 30);
        // Inflators that used to leak into Domain Overview raw meta counts:
        $this->insertArticle(4, 'post', 'post', wpPostId: 40, deletedAt: '2026-01-01 00:00:00');
        $this->insertArticle(5, 'post', 'post', wpPostId: 0);
        $this->insertArticle(6, 'category', 'post', wpPostId: 60, isTerm: true);
        $this->insertArticle(7, 'blocks', 'post', wpPostId: 70);
        $this->insertArticle(8, 'post', 'post', wpPostId: null); // local-only / no link

        $byType = WpBackedComparableInventory::countByWpPostType(self::SITE_ID);
        $preflight = (new SiteSyncPreflightContentComparison())->countLocal(self::SITE_ID);

        self::assertSame(['post' => 1, 'page' => 1, 'product' => 1], $byType);
        self::assertSame(3, array_sum($byType));
        self::assertSame(3, $preflight['total']);
        self::assertSame(1, $preflight['post']);
        self::assertSame(1, $preflight['page']);
        self::assertSame(1, $preflight['product']);
        self::assertSame(array_sum($byType), $preflight['total']);
    }

    public function test_scope_articles_matches_row_membership(): void
    {
        $this->insertArticle(1, 'post', 'post', wpPostId: 10);
        $this->insertArticle(2, 'post', 'post', wpPostId: null);
        $this->insertArticle(3, 'blocks', 'post', wpPostId: 30);

        $ids = \Omnichannel\Addons\Content\Models\SeoArticle::query()
            ->where('articles.site_id', self::SITE_ID);
        $ids = WpBackedComparableInventory::scopeArticles($ids)
            ->orderBy('articles.id')
            ->pluck('articles.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        self::assertSame([1], $ids);
        self::assertCount(1, WpBackedComparableInventory::rows(self::SITE_ID));
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
