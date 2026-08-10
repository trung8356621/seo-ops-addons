<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Jobs\AnalyzeArticleSeoJob;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;
use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;
use Omnichannel\Addons\Seo\Services\SeoScoringEngine;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Seo\Support\SeoScoringStatus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SeoAuditCacheArchitectureTest extends TestCase
{
    public function test_scoring_calculator_uses_registry_deductions(): void
    {
        $violations = [SeoScoringRulesRegistry::KEY_FAQ_MISSING, SeoScoringRulesRegistry::KEY_H2_MISSING];
        $score = SeoScoringCalculator::scoreFromViolations($violations);

        $this->assertSame(70, $score);
    }

    public function test_analyze_article_seo_job_is_unique_by_article_id(): void
    {
        $job = new AnalyzeArticleSeoJob(42);

        $this->assertSame('analyze-article-seo:42', $job->uniqueId());
    }

    public function test_queue_service_skips_unchanged_sync_item_when_already_analyzed(): void
    {
        Queue::fake();

        $article = $this->makeArticle();
        ArticleMeta::query()->create([
            'article_id' => $article->id,
            'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
            'meta_value' => json_encode([]),
        ]);
        $article->update(['seo_score' => 100]);
        SeoScoringStatus::writeStatus($article, SeoScoringStatus::STATUS_COMPLETED);

        $item = [
            'title' => 'Title',
            'scoring' => ['body' => '<p>Body</p>', 'slug' => 'slug'],
        ];
        $fingerprint = app(SeoArticleScoringQueueService::class)->buildSyncItemFingerprint($item);
        SeoScoringStatus::writeFingerprint($article, $fingerprint);

        $dispatched = app(SeoArticleScoringQueueService::class)->dispatchIfSyncItemChanged($article, $item, $article);

        $this->assertFalse($dispatched);
        Queue::assertNothingPushed();
    }

    public function test_queue_service_dispatches_when_sync_content_changes(): void
    {
        Queue::fake();

        $article = $this->makeArticle();
        SeoScoringStatus::writeFingerprint($article, 'old-hash');
        SeoScoringStatus::writeStatus($article, SeoScoringStatus::STATUS_COMPLETED);
        ArticleMeta::query()->create([
            'article_id' => $article->id,
            'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
            'meta_value' => json_encode([]),
        ]);
        $article->update(['seo_score' => 100]);

        $item = [
            'title' => 'Changed title',
            'scoring' => ['body' => '<p>New body</p>', 'slug' => 'new-slug'],
        ];

        $dispatched = app(SeoArticleScoringQueueService::class)->dispatchIfSyncItemChanged($article, $item, $article);

        $this->assertTrue($dispatched);
        Queue::assertPushed(AnalyzeArticleSeoJob::class, fn (AnalyzeArticleSeoJob $job): bool => $job->articleId === (int) $article->id);
    }

    public function test_audit_filtered_query_uses_json_violation_cache(): void
    {
        $matched = $this->makeArticle(['title' => 'Matched']);
        $other = $this->makeArticle(['title' => 'Other']);

        ArticleMeta::query()->create([
            'article_id' => $matched->id,
            'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
            'meta_value' => json_encode([SeoScoringRulesRegistry::KEY_FAQ_MISSING]),
        ]);
        $matched->update(['seo_score' => 90]);

        ArticleMeta::query()->create([
            'article_id' => $other->id,
            'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
            'meta_value' => json_encode([]),
        ]);
        $other->update(['seo_score' => 100]);

        $query = app(SeoAuditScanService::class)->buildFilteredQuery(
            SeoArticle::query()->where('site_id', 1),
            [SeoScoringRulesRegistry::KEY_FAQ_MISSING],
            false,
            false,
        );

        $ids = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame([(int) $matched->id], $ids);
    }

    public function test_audit_low_score_filter_uses_cached_seo_score_column(): void
    {
        $low = $this->makeArticle(['seo_score' => 45]);
        $high = $this->makeArticle(['seo_score' => 95]);

        foreach ([$low, $high] as $article) {
            ArticleMeta::query()->create([
                'article_id' => $article->id,
                'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
                'meta_value' => json_encode([]),
            ]);
        }

        $query = app(SeoAuditScanService::class)->buildFilteredQuery(
            SeoArticle::query()->where('site_id', 1),
            [],
            true,
            false,
        );

        $ids = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->assertContains((int) $low->id, $ids);
        $this->assertNotContains((int) $high->id, $ids);
    }

    public function test_empty_violations_array_means_analyzed_without_errors(): void
    {
        $article = $this->makeArticle();
        ArticleMeta::query()->create([
            'article_id' => $article->id,
            'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
            'meta_value' => json_encode([]),
        ]);
        $article->update(['seo_score' => 100]);

        $this->assertTrue(SeoScoringStatus::hasBeenAnalyzed($article->fresh()));
        $this->assertFalse(SeoScoringStatus::needsScoring($article->fresh()));
    }

    public function test_audit_scan_service_does_not_inject_scoring_engine(): void
    {
        $reflection = new \ReflectionClass(SeoAuditScanService::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor ? $constructor->getParameters() : [];

        $paramNames = array_map(static fn (\ReflectionParameter $param): string => $param->getName(), $params);

        $this->assertNotContains('engine', $paramNames);
        $this->assertNotContains('analyzer', $paramNames);
    }

    public function test_scoring_engine_returns_canonical_violation_keys(): void
    {
        $engine = app(SeoScoringEngine::class);
        $result = $engine->analyzeHtml(
            '<p>keyword intro</p><h2>Heading</h2><p>keyword body</p><img src="/a.jpg" alt="keyword alt">',
            'keyword',
            [],
            [
                'seo_title' => 'keyword title',
                'meta_description' => 'keyword meta',
                'slug' => 'keyword-slug',
                'domain' => 'example.com',
                'article_length_target' => 50,
            ],
        );

        $violations = is_array($result['violations'] ?? null) ? $result['violations'] : [];
        foreach ($violations as $violation) {
            $this->assertNotNull(SeoScoringRulesRegistry::canonicalRuleKeyForViolation((string) $violation));
        }
    }

    public function test_queue_missing_only_targets_articles_without_cache(): void
    {
        Queue::fake();

        $missing = $this->makeArticle();
        $cached = $this->makeArticle();
        ArticleMeta::query()->create([
            'article_id' => $cached->id,
            'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
            'meta_value' => json_encode([]),
        ]);
        $cached->update(['seo_score' => 100]);

        $result = app(SeoArticleScoringQueueService::class)->queueMissingForSite(1);

        $this->assertSame(1, $result['queued']);
        Queue::assertPushed(AnalyzeArticleSeoJob::class, 1);
        Queue::assertPushed(AnalyzeArticleSeoJob::class, fn (AnalyzeArticleSeoJob $job): bool => $job->articleId === (int) $missing->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeArticle(array $overrides = []): SeoArticle
    {
        $this->ensureSeoDatabaseAvailable();

        return SeoArticle::query()->create(array_merge([
            'site_id' => 1,
            'type' => 'article',
            'title' => 'Test article',
            'status' => 'publish',
            'body' => '<p>Body content long enough for scoring checks.</p>',
            'slug' => 'test-article',
        ], $overrides));
    }

    private function ensureSeoDatabaseAvailable(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('articles')) {
                $this->markTestSkipped('omi_seo_ai articles table is not available in this test database.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('omi_seo_ai connection is not available in this test database.');
        }
    }
}
