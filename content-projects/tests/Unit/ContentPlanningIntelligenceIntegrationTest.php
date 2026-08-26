<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\Models\Site;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Tests\TestCase;

/**
 * Phase 6 — Planning Intelligence DB semantics (requires SEO_TEST_USE_MYSQL=true).
 */
final class ContentPlanningIntelligenceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        foreach ([
            'seo_projects',
            'seo_project_tasks',
            'keywords',
            'seo_keyword_classifications',
            'seo_link_maps',
            'articles',
            'wordpress_article_links',
            'keyword_meta',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table on omi_seo_ai: '.$table);
            }
        }
    }

    public function test_principal_keywords_exclude_noise_and_sentence(): void
    {
        [$site, $project] = $this->seedSiteProject();
        $siteId = (int) $site->getKey();

        $this->seedKeyword($siteId, 'seo good one', true, 0.9, 'product');
        $this->seedKeyword($siteId, 'seo good two', true, 0.8, 'product');
        $this->seedKeyword($siteId, 'seo good three', true, 0.7, 'product');
        $this->seedKeyword($siteId, 'seo good four', true, 0.6, 'product');
        $this->seedKeyword($siteId, 'seo good five', true, 0.55, 'product');
        $this->seedKeyword($siteId, 'noise phrase', false, 0.1, 'noise');
        $this->seedKeyword($siteId, 'this is a long sentence about bags', false, 0.2, 'sentence');
        $this->seedKeyword($siteId, 'another noise', false, 0.05, 'noise');
        $this->seedKeyword($siteId, 'low score seo', true, 0.1, 'product');
        $this->seedKeyword($siteId, 'bare inventory', false, 0.9, 'product');

        $ctx = app(ContentPlanningIntelligenceService::class)->build($project, $site, [
            'use_keyword_intelligence' => true,
            'use_site_context' => false,
            'use_mcp_context' => false,
        ], 'vi');

        $phrases = array_map(static fn (array $r): string => $r['phrase'], $ctx['principal_keywords']);
        self::assertContains('seo good one', $phrases);
        self::assertNotContains('noise phrase', $phrases);
        self::assertNotContains('this is a long sentence about bags', $phrases);
        self::assertNotContains('low score seo', $phrases);
        self::assertNotContains('bare inventory', $phrases);
        self::assertGreaterThanOrEqual(5, count($phrases));
    }

    public function test_coverage_requires_linked_published_content(): void
    {
        [$site, $project] = $this->seedSiteProject();
        $siteId = (int) $site->getKey();

        $coveredId = $this->seedKeyword($siteId, 'covered kw', true, 0.9, 'product');
        $this->seedKeyword($siteId, 'uncovered kw', true, 0.9, 'product');

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'title' => 'Published covered article '.$this->seq,
            'slug' => 'covered-'.$this->seq,
            'type' => 'article',
            'status' => 'publish',
            'language' => 'vi',
        ]);
        WordpressArticleLink::query()->create([
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'wp_post_id' => 8_700_000 + $this->seq,
            'observed_post_status' => 'publish',
            'observed_permalink' => 'https://example.test/covered-'.$this->seq,
            'observed_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $coveredId,
            'source_article_id' => (int) $article->id,
            'target_article_id' => null,
            'anchor_text' => 'covered kw',
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ctx = app(ContentPlanningIntelligenceService::class)->build($project, $site, [
            'use_keyword_intelligence' => true,
            'use_site_context' => true,
            'use_mcp_context' => false,
        ], 'vi');

        $byPhrase = [];
        foreach ($ctx['principal_keywords'] as $row) {
            $byPhrase[$row['phrase']] = $row['coverage'];
        }
        self::assertSame('covered', $byPhrase['covered kw'] ?? null);
        self::assertSame('uncovered', $byPhrase['uncovered kw'] ?? null);
        self::assertArrayHasKey(NewContentSuggestionIdentity::normalize('covered kw'), $ctx['covered_keyword_norms']);
        self::assertArrayNotHasKey(NewContentSuggestionIdentity::normalize('uncovered kw'), $ctx['covered_keyword_norms']);
    }

    public function test_draft_planned_create_is_excluded_norm(): void
    {
        [$site, $project] = $this->seedSiteProject();
        SeoProjectTask::query()->create([
            'project_id' => (int) $project->getKey(),
            'site_id' => (int) $site->getKey(),
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'already planned topic',
            'keyword' => 'already planned topic',
            'title' => 'Already planned title',
            'status' => SeoProjectTask::STATUS_PENDING,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'target_date' => now()->format('Y-m-d'),
        ]);

        $ctx = app(ContentPlanningIntelligenceService::class)->build($project, $site, [
            'use_keyword_intelligence' => false,
            'use_site_context' => false,
            'use_mcp_context' => false,
        ], 'vi');

        self::assertNotEmpty($ctx['planned_topics']);
        $plannedFp = NewContentSuggestionIdentity::fingerprint('already planned topic', 'Already planned title');
        self::assertArrayHasKey($plannedFp, $ctx['planned_fingerprints']);
        // Draft planned ≠ content coverage; dedup uses planned fingerprints / planned keyword norms.
        self::assertArrayNotHasKey(
            NewContentSuggestionIdentity::normalize('already planned topic'),
            $ctx['covered_keyword_norms'],
        );
    }

    public function test_rejected_fingerprint_is_project_scoped(): void
    {
        [$site, $projectA] = $this->seedSiteProject('Draft A');
        $projectB = SeoProject::query()->create([
            'site_id' => (int) $site->getKey(),
            'user_id' => 1,
            'name' => 'Draft B '.$this->seq,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        $fp = NewContentSuggestionIdentity::fingerprint('rejected x', 'Title X');
        SeoContentProjectSuggestionDecision::query()->create([
            'project_id' => (int) $projectA->getKey(),
            'site_id' => (int) $site->getKey(),
            'source_type' => SeoContentProjectSuggestionDecision::SOURCE_AI_NEW_CONTENT,
            'source_key' => NewContentSuggestionIdentity::decisionSourceKey($fp),
            'decision' => SeoContentProjectSuggestionDecision::DECISION_DISMISSED,
            'meta' => ['keyword' => 'rejected x', 'title' => 'Title X'],
        ]);

        $ctxA = app(ContentPlanningIntelligenceService::class)->build($projectA, $site, [
            'use_keyword_intelligence' => false,
            'use_site_context' => false,
            'use_mcp_context' => false,
        ], 'vi');
        $ctxB = app(ContentPlanningIntelligenceService::class)->build($projectB, $site, [
            'use_keyword_intelligence' => false,
            'use_site_context' => false,
            'use_mcp_context' => false,
        ], 'vi');

        self::assertArrayHasKey($fp, $ctxA['rejected_fingerprints']);
        self::assertArrayNotHasKey($fp, $ctxB['rejected_fingerprints']);
    }

    public function test_weakly_covered_ki_phrase_not_in_covered_keyword_norms(): void
    {
        [$site, $project] = $this->seedSiteProject();
        $siteId = (int) $site->getKey();

        $weakId = $this->seedKeyword($siteId, 'weak only kw', true, 0.9, 'product');
        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'title' => 'Draft-only article '.$this->seq,
            'slug' => 'weak-'.$this->seq,
            'type' => 'article',
            'status' => 'draft',
            'language' => 'vi',
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $weakId,
            'source_article_id' => (int) $article->id,
            'target_article_id' => null,
            'anchor_text' => 'weak only kw',
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ctx = app(ContentPlanningIntelligenceService::class)->build($project, $site, [
            'use_keyword_intelligence' => true,
            'use_site_context' => true,
            'use_mcp_context' => false,
        ], 'vi');

        $byPhrase = [];
        foreach ($ctx['principal_keywords'] as $row) {
            $byPhrase[$row['phrase']] = $row['coverage'];
        }
        self::assertSame('weakly_covered', $byPhrase['weak only kw'] ?? null);
        self::assertArrayNotHasKey(
            NewContentSuggestionIdentity::normalize('weak only kw'),
            $ctx['covered_keyword_norms'],
        );
    }

    public function test_mcp_absent_still_builds_context(): void
    {
        [$site, $project] = $this->seedSiteProject();
        $ctx = app(ContentPlanningIntelligenceService::class)->build($project, $site, [
            'use_keyword_intelligence' => true,
            'use_site_context' => true,
            'use_mcp_context' => true,
        ], 'vi');

        self::assertIsArray($ctx['mcp_signals']);
        self::assertSame([], $ctx['mcp_signals']);
        self::assertArrayHasKey('principal_keywords_count', $ctx['diagnostics']);
        self::assertNull($ctx['mcp_period']);
    }

    /**
     * @return array{0: Site, 1: SeoProject}
     */
    private function seedSiteProject(string $name = 'Planning Draft'): array
    {
        $this->seq++;
        $siteId = 9_610_000 + $this->seq;
        $site = new Site;
        $site->forceFill([
            'id' => $siteId,
            'domain' => 'plan-'.$siteId.'.test',
            'name' => 'Plan Site '.$siteId,
            'user_id' => 1,
        ]);
        $site->exists = true;

        $project = SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => $name.' '.$this->seq,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        return [$site, $project];
    }

    private function seedKeyword(int $siteId, string $phrase, bool $isSeo, float $score, string $kind): int
    {
        $this->seq++;
        $keywordId = (int) DB::connection('omi_seo_ai')->table('keywords')->insertGetId([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keywordId,
            'meta_key' => KeywordMetaKey::siteTargetUrl($siteId),
            'meta_value' => 'https://example.test/'.$this->seq,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classification = [
            'keyword_id' => $keywordId,
            'phrase_kind' => $kind,
            'is_seo_keyword' => $isSeo,
            'keyword_score' => $score,
            'source_kind' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::connection('omi_seo_ai')->table('seo_keyword_classifications')->insert($classification);

        return $keywordId;
    }
}
