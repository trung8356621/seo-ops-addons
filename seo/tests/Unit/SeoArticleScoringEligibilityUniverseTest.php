<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Jobs\AnalyzeArticleSeoJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Workspace SEO scoring denominator must match ArticleSeoInventoryPolicy universe
 * (minus skip_seo_score), not invent a second content set via scopeNonTerm alone.
 */
final class SeoArticleScoringEligibilityUniverseTest extends TestCase
{
    private const SITE_ID = 77;

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

    public function test_normal_post_page_product_and_local_only_are_eligible(): void
    {
        $this->insertArticle(1, 'post');
        $this->insertArticle(2, 'page');
        $this->insertArticle(3, 'product');
        $this->insertArticle(4, null); // local-only: no wp_post_type

        $ids = $this->eligibleIds();

        self::assertSame([1, 2, 3, 4], $ids);
        self::assertTrue($this->article(1)->isEligibleForWorkspaceSeoScoring());
        self::assertTrue($this->article(2)->isEligibleForWorkspaceSeoScoring());
        self::assertTrue($this->article(3)->isEligibleForWorkspaceSeoScoring());
        self::assertTrue($this->article(4)->isEligibleForWorkspaceSeoScoring());
    }

    public function test_taxonomy_term_and_system_wp_types_are_excluded(): void
    {
        $this->insertArticle(1, 'post');
        $this->insertArticle(2, 'post', isTerm: true);
        $this->insertArticle(3, 'blocks');
        $this->insertArticle(4, 'wp_block');
        $this->insertArticle(5, 'wp_future_internal');
        $this->insertArticle(6, 'wp_template');

        $ids = $this->eligibleIds();

        self::assertSame([1], $ids);
        self::assertFalse($this->article(2)->isEligibleForWorkspaceSeoScoring());
        self::assertFalse($this->article(3)->isEligibleForWorkspaceSeoScoring());
        self::assertFalse($this->article(4)->isEligibleForWorkspaceSeoScoring());
        self::assertFalse($this->article(5)->isEligibleForWorkspaceSeoScoring());
        self::assertFalse(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('blocks', '0'));
        self::assertFalse(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('wp_block', null));
        self::assertFalse(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('wp_future_internal', null));
    }

    public function test_direct_dispatch_does_not_queue_system_article(): void
    {
        Queue::fake();
        $this->insertArticle(1, 'blocks');

        $dispatched = app(SeoArticleScoringQueueService::class)->dispatchForArticle($this->article(1), force: true);

        self::assertFalse($dispatched);
        Queue::assertNothingPushed();
    }

    public function test_domain_progress_denominator_excludes_system_articles(): void
    {
        $this->insertArticle(1, 'post');
        $this->insertArticle(2, 'page');
        $this->insertArticle(3, 'blocks');

        $progress = app(SeoArticleScoringQueueService::class)->domainProgress(self::SITE_ID);

        self::assertSame(2, $progress['total']);
        self::assertSame(2, $progress['remaining']);
    }

    public function test_queue_missing_and_queue_all_use_same_eligibility_universe(): void
    {
        Queue::fake();
        $this->insertArticle(1, 'post');
        $this->insertArticle(2, 'blocks');
        $this->insertArticle(3, 'product');

        $missing = app(SeoArticleScoringQueueService::class)->queueMissingForSite(self::SITE_ID);
        self::assertSame(2, $missing['queued']);
        Queue::assertPushed(AnalyzeArticleSeoJob::class, 2);
        Queue::assertPushed(
            AnalyzeArticleSeoJob::class,
            static fn (AnalyzeArticleSeoJob $job): bool => in_array($job->articleId, [1, 3], true),
        );
        Queue::assertNotPushed(
            AnalyzeArticleSeoJob::class,
            static fn (AnalyzeArticleSeoJob $job): bool => $job->articleId === 2,
        );

        // Fresh site / articles — avoid ShouldBeUnique locks from the missing pass.
        Queue::fake();
        DB::connection('omi_seo_ai')->table('article_meta')->delete();
        DB::connection('omi_seo_ai')->table('seo_article_profiles')->delete();
        DB::connection('omi_seo_ai')->table('articles')->delete();
        $this->insertArticle(10, 'post');
        $this->insertArticle(11, 'blocks');
        $this->insertArticle(12, 'page');

        $all = app(SeoArticleScoringQueueService::class)->queueAllForSite(self::SITE_ID);
        self::assertSame(2, $all['queued']);
        Queue::assertPushed(AnalyzeArticleSeoJob::class, 2);
        Queue::assertPushed(
            AnalyzeArticleSeoJob::class,
            static fn (AnalyzeArticleSeoJob $job): bool => in_array($job->articleId, [10, 12], true),
        );
    }

    public function test_skip_seo_score_still_excludes_otherwise_valid_inventory_candidate(): void
    {
        $this->insertArticle(1, 'post', skipSeoScore: true);
        $this->insertArticle(2, 'post');

        self::assertSame([2], $this->eligibleIds());
        self::assertFalse($this->article(1)->isEligibleForWorkspaceSeoScoring());
        self::assertFalse($this->article(1)->countsTowardSeoScore());
    }

    public function test_scoring_eligible_query_uses_inventory_policy_scope_not_non_term_alone(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoArticleScoringQueueService::class))->getFileName()
        );
        $eligible = $this->methodBody(SeoArticleScoringQueueService::class, 'eligibleArticlesQuery');
        $dispatch = $this->methodBody(SeoArticleScoringQueueService::class, 'dispatchForArticle');

        self::assertStringContainsString('ArticleSeoInventoryPolicy::scopeCandidates', $eligible);
        self::assertStringNotContainsString('scopeNonTerm', $eligible);
        self::assertStringContainsString('countsTowardSeoScore', $eligible);

        self::assertStringContainsString('isEligibleForWorkspaceSeoScoring', $dispatch);
        self::assertStringNotContainsString('countsTowardSeoScore()', $dispatch);

        self::assertTrue(method_exists(ArticleSeoInventoryPolicy::class, 'scopeCandidates'));
        self::assertTrue(method_exists(SeoArticle::class, 'isEligibleForWorkspaceSeoScoring'));
        self::assertStringContainsString('scopeCandidates', $src);
    }

    public function test_analyze_job_and_sync_php_score_guard_structural_types(): void
    {
        $jobSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/content/src/Jobs/AnalyzeArticleSeoJob.php'
        );
        $syncSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/wordpress/src/Services/SyncDomainContentService.php'
        );

        self::assertStringContainsString('isEligibleForWorkspaceSeoScoring', $jobSrc);
        self::assertStringContainsString('isEligibleForWorkspaceSeoScoring', $syncSrc);
        self::assertStringContainsString('function scoreSyncedItemWithPhp', $syncSrc);
        $scorePos = strpos($syncSrc, 'function scoreSyncedItemWithPhp');
        self::assertNotFalse($scorePos);
        $scoreSlice = substr($syncSrc, $scorePos, 500);
        self::assertStringContainsString('isEligibleForWorkspaceSeoScoring', $scoreSlice);
    }

    public function test_focus_keyword_coverage_reuses_inventory_policy_scope(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/FocusKeywordCoverageQuery.php'
        );
        self::assertStringContainsString('ArticleSeoInventoryPolicy::scopeCandidates', $src);
    }

    /**
     * @return list<int>
     */
    private function eligibleIds(): array
    {
        $ref = new ReflectionMethod(SeoArticleScoringQueueService::class, 'eligibleArticlesQuery');
        $ref->setAccessible(true);
        /** @var \Illuminate\Database\Eloquent\Builder<SeoArticle> $query */
        $query = $ref->invoke(app(SeoArticleScoringQueueService::class), self::SITE_ID);

        return $query->orderBy('articles.id')->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function article(int $id): SeoArticle
    {
        return SeoArticle::query()->with(['articleMetas', 'seoProfile'])->findOrFail($id);
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
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
    }

    private function insertArticle(
        int $id,
        ?string $wpPostType,
        bool $isTerm = false,
        bool $skipSeoScore = false,
    ): void {
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $id,
            'site_id' => self::SITE_ID,
            'title' => 'Article '.$id,
            'status' => 'publish',
            'type' => 'article',
            'body' => '<p>Body</p>',
            'slug' => 'article-'.$id,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::connection('omi_seo_ai')->table('seo_article_profiles')->insert([
            'article_id' => $id,
            'seo_score' => null,
            'skip_seo_score' => $skipSeoScore,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($wpPostType !== null) {
            DB::connection('omi_seo_ai')->table('article_meta')->insert([
                'article_id' => $id,
                'meta_key' => ArticleContentClassification::META_WP_POST_TYPE,
                'meta_value' => $wpPostType,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::connection('omi_seo_ai')->table('article_meta')->insert([
            'article_id' => $id,
            'meta_key' => ArticleContentClassification::META_WP_IS_TERM,
            'meta_value' => $isTerm ? '1' : '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }
}
