<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\Seo\Services\FocusKeywordCoverageQuery;
use Omnichannel\Addons\Seo\Services\FocusKeywordCoverageService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Tests\TestCase;

/**
 * Article-level Focus Keyword coverage — unique phrases ≠ articles covered.
 */
final class FocusKeywordCoverageServiceTest extends TestCase
{
    private const SITE_ID = 42;

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

    public function test_a_ten_articles_three_phrases_cover_eight(): void
    {
        // 10 eligible posts; phrases A,B,C cover articles 1–8; 9–10 missing.
        for ($i = 1; $i <= 10; $i++) {
            $this->insertArticle($i, 'post');
        }
        $this->insertKeyword(1, 'phrase-a', [1, 2, 3]);
        $this->insertKeyword(2, 'phrase-b', [4, 5, 6]);
        $this->insertKeyword(3, 'phrase-c', [7, 8]);

        $result = app(FocusKeywordCoverageService::class)->forSite(self::SITE_ID);

        self::assertSame(10, $result['eligible_article_count']);
        self::assertSame(8, $result['articles_with_focus_keyword']);
        self::assertSame(2, $result['missing_focus_keyword_articles']);
        self::assertSame(3, $result['unique_effective_focus_phrases']);
        self::assertSame([9, 10], $result['missing_article_ids']);
    }

    public function test_b_same_keyword_on_five_articles(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertArticle($i, 'post');
        }
        $this->insertKeyword(1, 'shared-phrase', [1, 2, 3, 4, 5]);

        $result = app(FocusKeywordCoverageService::class)->forSite(self::SITE_ID);

        self::assertSame(5, $result['articles_with_focus_keyword']);
        self::assertSame(1, $result['unique_effective_focus_phrases']);
        self::assertSame(5, $result['focus_article_relations']);
        self::assertSame(0, $result['missing_focus_keyword_articles']);
    }

    public function test_c_article_with_two_focus_relations_counts_once(): void
    {
        $this->insertArticle(1, 'post');
        // Stale double main_article_id (should still cover article once).
        $this->insertKeyword(1, 'first', [1]);
        $this->insertKeyword(2, 'second', [1]);

        $result = app(FocusKeywordCoverageService::class)->forSite(self::SITE_ID);

        self::assertSame(1, $result['eligible_article_count']);
        self::assertSame(1, $result['articles_with_focus_keyword']);
        self::assertSame(0, $result['missing_focus_keyword_articles']);
        self::assertSame(2, $result['focus_article_relations']);
    }

    public function test_d_manual_overrides_provider_still_one_covered_article(): void
    {
        $this->insertArticle(1, 'post');
        DB::connection('omi_seo_ai')->table('article_meta')->insert([
            'article_id' => 1,
            'meta_key' => 'seo_focus_keyword',
            'meta_value' => 'manual phrase',
        ]);
        $this->insertKeyword(1, 'provider phrase', [1], SiteSyncSchema::SOURCE_PROVIDER);

        $result = app(FocusKeywordCoverageService::class)->forSite(self::SITE_ID);

        self::assertSame(1, $result['articles_with_focus_keyword']);
        self::assertSame(0, $result['missing_focus_keyword_articles']);
        // Source bucket uses keyword source priority; locked/manual preferred.
        self::assertSame(1, $result['source_breakdown']['provider'] + $result['source_breakdown']['manual']);
    }

    public function test_e_wp_provider_missing_appears_in_missing_focus(): void
    {
        $this->insertArticle(1, 'post');
        // No meta, no keyword relation.

        $result = app(FocusKeywordCoverageService::class)->forSite(self::SITE_ID);

        self::assertSame([1], $result['missing_article_ids']);
        self::assertSame(1, $result['missing_focus_keyword_articles']);
    }

    public function test_g_system_blocks_excluded_from_denominator(): void
    {
        $this->insertArticle(1, 'post');
        $this->insertArticle(2, 'blocks');
        $this->insertArticle(3, 'wp_template');
        $this->insertArticle(4, 'post', isTerm: true);

        $result = app(FocusKeywordCoverageService::class)->forSite(self::SITE_ID);

        self::assertSame(1, $result['eligible_article_count']);
        self::assertSame([1], app(FocusKeywordCoverageService::class)->query()
            ->eligibleQuery(self::SITE_ID)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_missing_scope_matches_audit_and_posts_filter_contract(): void
    {
        $srcAudit = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/SeoAuditScanService.php',
        );
        $srcArticle = (string) file_get_contents(
            dirname(__DIR__, 3).'/content/src/Filament/Resources/ArticleResource.php',
        );
        $srcPresenter = (string) file_get_contents(
            dirname(__DIR__, 3).'/content-projects/src/Services/ContentProject/Operations/SiteHealthCardPresenter.php',
        );

        self::assertStringContainsString('FocusKeywordCoverageQuery', $srcAudit);
        self::assertStringContainsString("focus_keyword_status", $srcArticle);
        self::assertStringContainsString('FocusKeywordCoverageService', $srcPresenter);
        self::assertStringContainsString('FocusKeywordCoverageQuery', $srcArticle);

        $query = new FocusKeywordCoverageQuery();
        self::assertTrue(method_exists($query, 'applyMissingFocusScope'));
        self::assertTrue(method_exists($query, 'applySeoInventoryScope'));
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id');
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('article_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('phrase');
            $table->string('source')->nullable();
            $table->boolean('source_locked')->default(false);
        });
        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('keyword_id');
            $table->string('meta_key');
            $table->string('meta_value');
        });
        Schema::connection('omi_seo_ai')->create('wordpress_article_links', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->unsignedInteger('wp_post_id')->nullable();
        });
    }

    private function insertArticle(int $id, string $wpPostType, bool $isTerm = false): void
    {
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $id,
            'site_id' => self::SITE_ID,
            'title' => 'Article '.$id,
            'status' => 'publish',
            'deleted_at' => null,
        ]);
        DB::connection('omi_seo_ai')->table('article_meta')->insert([
            'article_id' => $id,
            'meta_key' => 'wp_post_type',
            'meta_value' => $wpPostType,
        ]);
        if ($isTerm) {
            DB::connection('omi_seo_ai')->table('article_meta')->insert([
                'article_id' => $id,
                'meta_key' => 'wp_is_term',
                'meta_value' => '1',
            ]);
        }
        DB::connection('omi_seo_ai')->table('wordpress_article_links')->insert([
            'article_id' => $id,
            'wp_post_id' => 1000 + $id,
        ]);
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function insertKeyword(
        int $keywordId,
        string $phrase,
        array $articleIds,
        string $source = SiteSyncSchema::SOURCE_PROVIDER,
    ): void {
        DB::connection('omi_seo_ai')->table('keywords')->insert([
            'id' => $keywordId,
            'phrase' => $phrase,
            'source' => $source,
            'source_locked' => false,
        ]);
        foreach ($articleIds as $articleId) {
            DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                'keyword_id' => $keywordId,
                'meta_key' => KeywordMetaKey::MainArticleId->value,
                'meta_value' => (string) $articleId,
            ]);
        }
    }
}
