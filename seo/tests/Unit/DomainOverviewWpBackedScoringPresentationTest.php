<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Seo\Support\SeoScoringStatus;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Domain Overview / Site Sync website scoring UI must use WP-backed membership,
 * while Workspace queue eligibility may still include local-only articles.
 */
final class DomainOverviewWpBackedScoringPresentationTest extends TestCase
{
    private const SITE_ID = 88;

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

    public function test_workspace_progress_includes_local_only_wp_backed_excludes_it(): void
    {
        $this->insertArticle(1, 'post', wpPostId: 10, score: 80, completed: true);
        $this->insertArticle(2, 'post', wpPostId: null, score: 70, completed: true); // local-only
        $this->insertArticle(3, 'page', wpPostId: 30, score: null, completed: false);

        $workspace = app(SeoArticleScoringQueueService::class)->domainProgress(self::SITE_ID);
        $wpBacked = app(SeoArticleScoringQueueService::class)->domainWpBackedProgress(self::SITE_ID);
        $overview = app(DomainOverviewService::class)->getWpBackedScoringProgress(self::SITE_ID);

        self::assertSame(3, $workspace['total']);
        self::assertSame(2, $workspace['completed']);
        self::assertSame(2, $wpBacked['total']);
        self::assertSame(1, $wpBacked['completed']);
        self::assertSame($wpBacked, $overview);
    }

    public function test_donut_and_progress_share_wp_backed_membership_excluding_leaks(): void
    {
        $this->insertArticle(1, 'post', wpPostId: 10, score: 40, completed: true);
        $this->insertArticle(2, 'page', wpPostId: 20, score: 75, completed: true);
        $this->insertArticle(3, 'product', wpPostId: 30, score: null, completed: false);
        // Inflators that previously leaked into donut / workspace-mixed progress:
        $this->insertArticle(4, 'post', wpPostId: null, score: 99, completed: true); // local-only
        $this->insertArticle(5, 'blocks', wpPostId: 50, score: 88, completed: true);
        $this->insertArticle(6, 'wp_block', wpPostId: 60, score: 77, completed: true);
        $this->insertArticle(7, 'category', wpPostId: 70, score: 66, completed: true, isTerm: true);
        $this->insertArticle(8, 'post', wpPostId: 0, score: 55, completed: true);
        $this->insertArticle(9, 'post', wpPostId: 90, score: 50, completed: true, skipSeoScore: true);

        $overview = app(DomainOverviewService::class);
        $stats = $overview->getScoringStatistics(self::SITE_ID);
        $distribution = $overview->getScoreDistribution(self::SITE_ID);
        $progress = $overview->getWpBackedScoringProgress(self::SITE_ID);

        // Denominator = WP-backed scoring-eligible (skip excluded): articles 1,2,3
        self::assertSame(3, $distribution['total']);
        self::assertSame(3, $progress['total']);
        // Donut “Đã chấm” = WP-backed with non-null score (1,2) — not system/local leaks
        self::assertSame(2, $stats['scored']);
        self::assertSame(2, $distribution['scored']);
        // Progress completed recalculated on same WP-backed set (1,2)
        self::assertSame(2, $progress['completed']);
        self::assertSame(
            $distribution['scored'],
            array_sum(array_column($distribution['segments'], 'count')),
        );
        self::assertLessThanOrEqual($progress['total'], $stats['scored']);
    }

    public function test_presentation_wiring_uses_wp_backed_not_workspace_progress(): void
    {
        $generalSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/search-foundation/src/Filament/Resources/DomainResource/Pages/GeneralDomain.php'
        );
        $progressMethod = $this->methodBodyFromSource(
            $generalSrc,
            'Omnichannel\\Addons\\SearchFoundation\\Filament\\Resources\\DomainResource\\Pages\\GeneralDomain',
            'getSeoScoringProgress',
        );
        self::assertStringContainsString('getWpBackedScoringProgress', $progressMethod);
        self::assertStringNotContainsString('domainProgress', $progressMethod);

        $presenterSrc = (string) file_get_contents(
            (new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName()
        );
        $safe = $this->methodBodyFromSource($presenterSrc, SiteSyncStatusPresenter::class, 'safeScoringProgress');
        self::assertStringContainsString('domainWpBackedProgress', $safe);
        self::assertStringNotContainsString('->domainProgress(', $safe);

        // Queue / workspace eligibility must remain broader.
        $queueSrc = (string) file_get_contents(
            (new ReflectionClass(SeoArticleScoringQueueService::class))->getFileName()
        );
        self::assertStringContainsString('function domainProgress', $queueSrc);
        self::assertStringContainsString('function domainWpBackedProgress', $queueSrc);
        $eligible = $this->methodBodyFromSource(
            $queueSrc,
            SeoArticleScoringQueueService::class,
            'eligibleArticlesQuery',
        );
        self::assertStringContainsString('scopeCandidates', $eligible);
        self::assertStringNotContainsString('WpBackedComparableInventory', $eligible);
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id');
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('omi_seo_ai')->create('article_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('seo_article_profiles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id')->unique();
            $table->integer('seo_score')->nullable();
            $table->boolean('skip_seo_score')->default(false);
            $table->timestamps();
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
        ?int $wpPostId = null,
        ?int $score = null,
        bool $completed = false,
        bool $isTerm = false,
        bool $skipSeoScore = false,
    ): void {
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $id,
            'site_id' => self::SITE_ID,
            'status' => 'publish',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::connection('omi_seo_ai')->table('seo_article_profiles')->insert([
            'article_id' => $id,
            'seo_score' => $score,
            'skip_seo_score' => $skipSeoScore,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('article_meta')->insert([
            [
                'article_id' => $id,
                'meta_key' => ArticleContentClassification::META_WP_POST_TYPE,
                'meta_value' => $wpPostType,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'article_id' => $id,
                'meta_key' => ArticleContentClassification::META_WP_IS_TERM,
                'meta_value' => $isTerm ? '1' : '0',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        if ($completed) {
            DB::connection('omi_seo_ai')->table('article_meta')->insert([
                'article_id' => $id,
                'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
                'meta_value' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('omi_seo_ai')->table('article_meta')->insert([
                'article_id' => $id,
                'meta_key' => SeoScoringStatus::META_KEY_STATUS,
                'meta_value' => SeoScoringStatus::STATUS_COMPLETED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($wpPostId !== null) {
            DB::connection('omi_seo_ai')->table('wordpress_article_links')->insert([
                'article_id' => $id,
                'site_id' => self::SITE_ID,
                'wp_post_id' => $wpPostId,
            ]);
        }
    }

    /**
     * @param  class-string  $class
     */
    private function methodBodyFromSource(string $src, string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }
}
