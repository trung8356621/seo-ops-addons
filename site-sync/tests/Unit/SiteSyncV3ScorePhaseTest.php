<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Jobs\AnalyzeArticleSeoJob;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Progress\SiteSyncStepCatalog;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase as LaravelTestCase;

/**
 * V3 SCORE phase — WP-backed scoring lifecycle after VERIFY, before COMPLETE.
 */
final class SiteSyncV3ScorePhaseTest extends LaravelTestCase
{
    private const SITE_ID = 64;

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

    public function test_verify_advances_to_score_not_complete(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $verify = $this->methodBody($src, 'phaseVerify');
        $handle = $this->methodBody($src, 'handle');

        self::assertStringContainsString('PHASE_SCORE', $verify);
        self::assertStringNotContainsString('PHASE_COMPLETE', $verify);
        self::assertStringContainsString('phaseScore', $handle);
        self::assertContains(SiteSyncV3Schema::PHASE_SCORE, SiteSyncV3Schema::PHASES);
        self::assertSame(
            [
                SiteSyncV3Schema::PHASE_DISCOVER,
                SiteSyncV3Schema::PHASE_IMPORT,
                SiteSyncV3Schema::PHASE_RECONCILE_STALE,
                SiteSyncV3Schema::PHASE_CATCH_UP,
                SiteSyncV3Schema::PHASE_VERIFY,
                SiteSyncV3Schema::PHASE_SCORE,
                SiteSyncV3Schema::PHASE_COMPLETE,
            ],
            SiteSyncStepCatalog::v3Keys(),
        );
    }

    public function test_score_phase_queues_wp_backed_only_and_uses_wp_backed_progress(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $score = $this->methodBody($src, 'phaseScore');

        self::assertStringContainsString('queueMissingOrStaleWpBackedForSite', $score);
        self::assertStringContainsString('domainWpBackedProgress', $score);
        self::assertStringNotContainsString('queueMissingOrStaleForSite(', $score);
        self::assertStringNotContainsString('->domainProgress(', $score);
        self::assertStringContainsString('unresolved', $score);
        self::assertStringContainsString('SCORE_STALE_MINUTES', $score);
        self::assertStringContainsString('scoring_stale', $score);
        self::assertStringContainsString('scoring_deferred', $score);
        self::assertStringContainsString('->delay(', $score);
        // Must not port V2 false-complete after polls >= 6.
        self::assertStringNotContainsString('polls >= 6', $score);
        self::assertStringNotContainsString('$polls >= 6', $score);
    }

    public function test_score_terminal_completed_with_warnings_and_no_silent_unresolved(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $score = $this->methodBody($src, 'phaseScore');
        $complete = $this->methodBody($src, 'phaseComplete');

        self::assertStringContainsString('$inFlight === 0 && $unresolved === 0', $score);
        self::assertStringContainsString('PHASE_COMPLETE', $score);
        self::assertStringContainsString('completed_with_warnings', $complete);
        self::assertStringContainsString('scoring_failed', $complete);
    }

    public function test_queue_missing_or_stale_wp_backed_excludes_local_only(): void
    {
        Queue::fake();

        $this->insertArticle(1, 'post', wpPostId: 10); // WP-backed missing score
        $this->insertArticle(2, 'post', wpPostId: null); // local-only — must not queue for Site Sync

        $result = app(SeoArticleScoringQueueService::class)
            ->queueMissingOrStaleWpBackedForSite(self::SITE_ID);

        self::assertSame(1, $result['queued']);
        self::assertSame(1, $result['missing_queued']);
        Queue::assertPushed(AnalyzeArticleSeoJob::class, 1);

        $workspace = app(SeoArticleScoringQueueService::class)->queueMissingOrStaleForSite(self::SITE_ID);
        // Second call: article 1 already pending → skip; local-only still eligible for Workspace.
        self::assertGreaterThanOrEqual(1, $workspace['queued'] + $workspace['skipped']);
    }

    public function test_workspace_domain_progress_still_includes_local_only(): void
    {
        $this->insertArticle(1, 'post', wpPostId: 10);
        $this->insertArticle(2, 'post', wpPostId: null);

        $workspace = app(SeoArticleScoringQueueService::class)->domainProgress(self::SITE_ID);
        $wpBacked = app(SeoArticleScoringQueueService::class)->domainWpBackedProgress(self::SITE_ID);

        self::assertSame(2, $workspace['total']);
        self::assertSame(1, $wpBacked['total']);
    }

    public function test_resume_prefers_score_when_scoring_dispatched(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'resolveAttentionResumePhase');

        self::assertStringContainsString('PHASE_SCORE', $method);
        self::assertStringContainsString('scoring_dispatched_at', $method);
    }

    private function methodBody(string $src, string $method): string
    {
        $ref = new ReflectionMethod(RunSiteSyncV3Orchestrator::class, $method);
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id');
            $table->string('status')->nullable();
            $table->string('title')->nullable();
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
        Schema::connection('omi_seo_ai')->create('wordpress_article_links', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->unsignedInteger('site_id')->nullable();
            $table->unsignedInteger('wp_post_id')->nullable();
        });
    }

    private function insertArticle(int $id, string $wpPostType, ?int $wpPostId = null): void
    {
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $id,
            'site_id' => self::SITE_ID,
            'status' => 'publish',
            'title' => 'A'.$id,
            'body' => '<p>x</p>',
            'slug' => 'a-'.$id,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::connection('omi_seo_ai')->table('seo_article_profiles')->insert([
            'article_id' => $id,
            'seo_score' => null,
            'skip_seo_score' => false,
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
                'meta_value' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ],
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
