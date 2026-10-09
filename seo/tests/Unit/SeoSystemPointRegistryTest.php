<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;
use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Seo\Support\SeoSystemPointRegistry;
use ReflectionMethod;
use Tests\TestCase;

final class SeoSystemPointRegistryTest extends TestCase
{
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

    public function test_protected_block_is_code_1_and_is_not_mutated(): void
    {
        $this->insert(1, 'blocks', 'post', null, null);

        $article = $this->article(1);
        self::assertSame(SeoSystemPointRegistry::CODE_WP_PROTECTED, SeoSystemPointRegistry::resolve($article));

        $result = app(SeoAnalyzerService::class)->analyze($article);

        self::assertSame(SeoSystemPointRegistry::CODE_WP_PROTECTED, $result['system_point']);
        self::assertNull($result['quality_score']);
        self::assertFalse($result['rankable']);
        self::assertNull(DB::connection('omi_seo_ai')->table('seo_article_profiles')->where('article_id', 1)->value('seo_score'));
        self::assertSame(0, DB::connection('omi_seo_ai')->table('article_meta')->where('article_id', 1)->where('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS)->count());
    }

    public function test_page_is_code_2_without_rewriting_score(): void
    {
        $this->insert(2, 'page', 'page', 75, 'page keyword');

        $article = $this->article(2);
        $presented = SeoSystemPointRegistry::present(
            SeoSystemPointRegistry::resolve($article, true),
            75,
        );

        self::assertSame(SeoSystemPointRegistry::CODE_PAGE, $presented['system_point']);
        self::assertNull($presented['quality_score']);
        self::assertFalse($presented['rankable']);
        self::assertSame(75, (int) DB::connection('omi_seo_ai')->table('seo_article_profiles')->where('article_id', 2)->value('seo_score'));
    }

    public function test_post_missing_focus_keyword_is_code_3_and_keeps_calculated_zero(): void
    {
        $this->insert(3, 'post', 'post', null, null);

        $result = app(SeoAnalyzerService::class)->analyze($this->article(3));

        self::assertSame(SeoSystemPointRegistry::CODE_MISSING_FOCUS_KEYWORD, $result['system_point']);
        self::assertNull($result['quality_score']);
        self::assertSame(0, $result['score']);
        self::assertContains(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $result['violations']);
        self::assertSame(0, (int) DB::connection('omi_seo_ai')->table('seo_article_profiles')->where('article_id', 3)->value('seo_score'));
    }

    public function test_keyword_post_score_15_stays_ordinary_quality(): void
    {
        $this->insert(4, 'post', 'post', 15, 'alpha');
        $article = $this->article(4);

        self::assertNull(SeoSystemPointRegistry::resolve($article, true));
        self::assertTrue(SeoSystemPointRegistry::isRankable($article, true));
        $presented = SeoSystemPointRegistry::present(null, 15);
        self::assertSame(15, $presented['quality_score']);
        self::assertTrue($presented['rankable']);
        self::assertFalse(SeoSystemPointRegistry::isSystemPointCode(15));
    }

    public function test_legitimate_score_between_1_and_10_is_not_treated_as_a_system_point(): void
    {
        $this->insert(5, 'post', 'post', 5, 'alpha');

        self::assertNull(SeoSystemPointRegistry::resolve($this->article(5), true));
        self::assertSame(5, (int) DB::connection('omi_seo_ai')->table('seo_article_profiles')->where('article_id', 5)->value('seo_score'));
        self::assertTrue(SeoSystemPointRegistry::isSystemPointCode(5));
        self::assertSame(5, (int) DB::connection('omi_seo_ai')->table('seo_article_profiles')->where('article_id', 5)->value('seo_score'));
    }

    public function test_legacy_score_zero_with_keyword_is_not_code_3(): void
    {
        $this->insert(7, 'post', 'post', 0, 'alpha');

        self::assertNull(SeoSystemPointRegistry::resolve($this->article(7), true));
        self::assertTrue(SeoSystemPointRegistry::isRankable($this->article(7), true));
    }

    public function test_adding_focus_keyword_clears_system_point_and_restores_scoring(): void
    {
        $this->insert(3, 'post', 'post', null, null);
        $before = app(SeoAnalyzerService::class)->analyze($this->article(3));
        self::assertSame(SeoSystemPointRegistry::CODE_MISSING_FOCUS_KEYWORD, $before['system_point']);

        DB::connection('omi_seo_ai')->table('article_meta')->insert([
            'article_id' => 3,
            'meta_key' => 'seo_focus_keyword',
            'meta_value' => 'alpha keyword',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $article = $this->article(3);
        self::assertNull(SeoSystemPointRegistry::resolve($article, true));

        $restored = SeoScoringCalculator::scoreFromViolations([SeoScoringRulesRegistry::KEY_H2_MISSING]);
        self::assertSame(80, $restored);
        self::assertNotSame(SeoSystemPointRegistry::CODE_MISSING_FOCUS_KEYWORD, $restored);
        self::assertNull(SeoSystemPointRegistry::present(null, $restored)['system_point']);
        self::assertSame($restored, SeoSystemPointRegistry::present(null, $restored)['quality_score']);
    }

    public function test_low_score_ranking_excludes_system_points_and_paginates_without_padding(): void
    {
        $this->insert(1, 'blocks', 'post', 0, null);
        $this->insert(2, 'page', 'page', 4, null);
        $this->insert(3, 'post', 'post', 0, null);
        $this->insert(4, 'post', 'post', 15, 'alpha');
        $this->insert(5, 'post', 'post', 5, 'alpha');
        $this->insert(6, 'post', 'post', 80, 'alpha');
        $this->insert(7, 'post', 'post', 0, 'alpha');
        $this->insert(8, 'post', 'post', 9, 'alpha', skip: true);

        $low = app(SeoAuditScanService::class)->buildFilteredQuery(
            SeoArticle::query()->where('articles.site_id', 1)->countsTowardSeoScore(),
            [],
            true,
            false,
        );
        $ids = $low->orderBy('articles.id')->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();

        self::assertSame([4, 5, 7], $ids);

        $page1 = array_slice($ids, 0, 2);
        $page2 = array_slice($ids, 2, 2);
        self::assertSame([4, 5], $page1);
        self::assertSame([7], $page2);
        self::assertCount(3, $ids);

        $missing = app(SeoAuditScanService::class)->buildFilteredQuery(
            SeoArticle::query()->where('articles.site_id', 1)->countsTowardSeoScore(),
            [SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD],
            false,
            false,
        );
        $missingIds = $missing->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();
        self::assertContains(3, $missingIds);
        self::assertNotContains(4, $missingIds);
    }

    public function test_skip_seo_score_stays_unrankable_and_unmutated(): void
    {
        $this->insert(8, 'post', 'post', 9, 'alpha', skip: true);
        $article = $this->article(8);

        self::assertFalse($article->countsTowardSeoScore());
        self::assertFalse(SeoSystemPointRegistry::isRankable($article, true));
        self::assertNull(SeoSystemPointRegistry::resolve($article, true));

        $persist = new ReflectionMethod(SeoAnalyzerService::class, 'persistScoreResult');
        $persist->setAccessible(true);
        $persist->invoke(app(SeoAnalyzerService::class), $article, [
            'score' => 40,
            'violations' => [],
            'good' => [],
            'errors' => [],
            'warnings' => [],
        ]);

        self::assertSame(9, (int) DB::connection('omi_seo_ai')->table('seo_article_profiles')->where('article_id', 8)->value('seo_score'));
    }

    private function article(int $id): SeoArticle
    {
        return SeoArticle::query()->with(['articleMetas', 'seoProfile'])->findOrFail($id);
    }

    private function ensureTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id');
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->text('body')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('article_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_article_profiles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id')->unique();
            $table->integer('seo_score')->nullable();
            $table->boolean('skip_seo_score')->default(false);
            $table->integer('internal_link_count')->nullable();
            $table->integer('external_link_count')->nullable();
            $table->timestamps();
        });
        $schema->create('keywords', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('phrase')->nullable();
            $table->string('type')->nullable();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
    }

    private function insert(
        int $id,
        string $wpPostType,
        string $contentType,
        ?int $score,
        ?string $keyword,
        bool $skip = false,
    ): void {
        $now = now();
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $id,
            'site_id' => 1,
            'title' => 'Article '.$id,
            'status' => 'publish',
            'type' => 'article',
            'body' => '<p>Body</p>',
            'slug' => 'article-'.$id,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
        DB::connection('omi_seo_ai')->table('seo_article_profiles')->insert([
            'article_id' => $id,
            'seo_score' => $score,
            'skip_seo_score' => $skip,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $metas = [
            [ArticleContentClassification::META_WP_POST_TYPE, $wpPostType],
            [ArticleContentClassification::META_CONTENT_TYPE, $contentType],
            [ArticleContentClassification::META_WP_IS_TERM, '0'],
        ];
        if ($keyword !== null) {
            $metas[] = ['seo_focus_keyword', $keyword];
        }
        foreach ($metas as [$key, $value]) {
            DB::connection('omi_seo_ai')->table('article_meta')->insert([
                'article_id' => $id,
                'meta_key' => $key,
                'meta_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
