<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIntelligenceIngestionService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use App\Models\Site;
use Tests\TestCase;

/**
 * Phase 7 — Vocabulary → KI feedback DB semantics (SEO_TEST_USE_MYSQL=true).
 */
final class VocabularyKeywordIntelligenceIntegrationTest extends TestCase
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
            'keywords',
            'keyword_meta',
            'seo_keyword_classifications',
            'articles',
            'seo_link_maps',
            'wordpress_article_links',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table on omi_seo_ai: '.$table);
            }
        }
    }

    public function test_related_topics_ingested_and_other_groups_ignored(): void
    {
        $article = $this->createArticle();
        $suffix = uniqid('rt_', true);
        $groups = [
            'Related topics' => [
                'balo chong nuoc '.$suffix,
                've sinh balo '.$suffix,
                'kich thuoc balo '.$suffix,
                'balo doanh nghiep '.$suffix,
                'ba lo hoc sinh '.$suffix,
            ],
            'Antonyms' => ['doi lap '.$suffix],
            'N-grams' => ['ngram noise '.$suffix],
            'Holonymy' => ['holonymy tag '.$suffix],
        ];

        $summary = app(VocabularyKeywordIntelligenceIngestionService::class)
            ->ingestFromVocabularyGroups($article, $groups);

        self::assertSame(5, $summary['discovered']);
        self::assertSame(5, $summary['ingested']);
        self::assertGreaterThanOrEqual(1, $summary['classified']);
        self::assertArrayHasKey('related_topics', $summary['groups']);

        self::assertSame(0, Keyword::query()->where('phrase', 'doi lap '.$suffix)->count());
        self::assertSame(0, Keyword::query()->where('phrase', 'ngram noise '.$suffix)->count());
        self::assertSame(1, Keyword::query()->where('phrase', 'balo chong nuoc '.$suffix)->count());

        $kw = Keyword::query()->where('phrase', 'balo chong nuoc '.$suffix)->first();
        self::assertNotNull($kw);
        self::assertSame(KeywordSourceNormalizer::AI_GENERATED, (string) $kw->source);
        self::assertSame(Keyword::TYPE_SUGGEST, (string) $kw->type);
        self::assertTrue(SeoKeywordClassification::query()->where('keyword_id', $kw->id)->exists());
        self::assertSame(
            0,
            DB::connection('omi_seo_ai')->table('seo_link_maps')->where('keyword_id', $kw->id)->count(),
        );
    }

    public function test_idempotent_rerun_does_not_duplicate_keyword(): void
    {
        $article = $this->createArticle();
        $phrase = 'idempotent topic '.uniqid('', true);
        $svc = app(VocabularyKeywordIntelligenceIngestionService::class);

        $first = $svc->ingestRelatedTopics($article, [$phrase]);
        $second = $svc->ingestRelatedTopics($article, [$phrase]);

        self::assertSame(1, $first['ingested']);
        self::assertSame(1, $second['duplicates']);
        self::assertSame(1, Keyword::query()->where('phrase', Keyword::preparePhraseForStorage($phrase))->count());
    }

    public function test_manual_source_is_not_downgraded(): void
    {
        $article = $this->createArticle();
        $siteId = (int) $article->site_id;
        $phrase = 'manual strong '.uniqid('', true);

        $existing = app(KeywordPersistenceService::class)->upsert($phrase, Keyword::TYPE_NORMAL, $siteId);
        self::assertNotNull($existing);
        $existing->forceFill([
            'source' => KeywordSourceNormalizer::MANUAL,
            'source_locked' => false,
        ])->save();

        $summary = app(VocabularyKeywordIntelligenceIngestionService::class)
            ->ingestRelatedTopics($article, [$phrase]);

        self::assertGreaterThanOrEqual(1, $summary['source_preserved']);
        self::assertSame(KeywordSourceNormalizer::MANUAL, (string) $existing->fresh()?->source);
    }

    public function test_vocabulary_discovery_does_not_create_false_coverage(): void
    {
        $article = $this->createArticle();
        $siteId = (int) $article->site_id;
        $phrase = 'uncovered related '.uniqid('', true);

        app(VocabularyKeywordIntelligenceIngestionService::class)->ingestRelatedTopics($article, [$phrase]);

        $site = new Site;
        $site->forceFill(['id' => $siteId, 'domain' => 'vocab-'.$siteId.'.test', 'user_id' => 1]);
        $site->exists = true;
        $project = SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'Vocab plan '.$this->seq,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        $ctx = app(ContentPlanningIntelligenceService::class)->build($project, $site, [
            'use_keyword_intelligence' => true,
            'use_site_context' => false,
            'use_mcp_context' => false,
        ], 'vi');

        $byPhrase = [];
        foreach ($ctx['principal_keywords'] as $row) {
            $byPhrase[$row['phrase']] = $row['coverage'];
        }

        // Only appears if classifier marks SEO-worthy; either way must not be covered.
        if (isset($byPhrase[$phrase])) {
            self::assertSame('uncovered', $byPhrase[$phrase]);
        }
        self::assertArrayNotHasKey(
            NewContentSuggestionIdentity::normalize($phrase),
            $ctx['covered_keyword_norms'],
        );
    }

    public function test_true_coverage_still_works_alongside_vocabulary(): void
    {
        $article = $this->createArticle('publish');
        $siteId = (int) $article->site_id;
        $phrase = 'covered focus '.uniqid('', true);

        $keyword = app(KeywordPersistenceService::class)->upsert($phrase, Keyword::TYPE_NORMAL, $siteId);
        self::assertNotNull($keyword);
        $keyword->forceFill(['source' => KeywordSourceNormalizer::SITE_SYNC])->save();

        DB::connection('omi_seo_ai')->table('seo_keyword_classifications')->updateOrInsert(
            ['keyword_id' => (int) $keyword->id],
            [
                'phrase_kind' => 'keyword_phrase',
                'is_seo_keyword' => true,
                'keyword_score' => 0.9,
                'source_kind' => KeywordSourceNormalizer::SITE_SYNC,
                'normalized_text' => mb_strtolower($phrase),
                'folded_text' => mb_strtolower($phrase),
                'raw_text' => $phrase,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => (int) $keyword->id,
            'source_article_id' => (int) $article->id,
            'target_article_id' => null,
            'anchor_text' => $phrase,
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Vocabulary discovery of same phrase must not wipe coverage link.
        app(VocabularyKeywordIntelligenceIngestionService::class)->ingestRelatedTopics($article, [$phrase]);

        self::assertSame(
            1,
            DB::connection('omi_seo_ai')->table('seo_link_maps')->where('keyword_id', $keyword->id)->count(),
        );
        self::assertSame(KeywordSourceNormalizer::SITE_SYNC, (string) $keyword->fresh()?->source);

        $site = new Site;
        $site->forceFill(['id' => $siteId, 'domain' => 'cov-'.$siteId.'.test', 'user_id' => 1]);
        $site->exists = true;
        $project = SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'Coverage plan '.$this->seq,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        $ctx = app(ContentPlanningIntelligenceService::class)->build($project, $site, [
            'use_keyword_intelligence' => true,
            'use_site_context' => false,
            'use_mcp_context' => false,
        ], 'vi');

        $byPhrase = [];
        foreach ($ctx['principal_keywords'] as $row) {
            $byPhrase[$row['phrase']] = $row['coverage'];
        }
        self::assertSame('covered', $byPhrase[$phrase] ?? null);
    }

    public function test_no_draft_task_or_mcp_write(): void
    {
        $article = $this->createArticle();
        $beforeTasks = Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
            ? (int) DB::connection('omi_seo_ai')->table('seo_project_tasks')->count()
            : 0;
        $beforeMcp = Schema::connection('omi_seo_ai')->hasTable('seo_mcp_source_snapshots')
            ? (int) DB::connection('omi_seo_ai')->table('seo_mcp_source_snapshots')->count()
            : 0;

        app(VocabularyKeywordIntelligenceIngestionService::class)->ingestRelatedTopics(
            $article,
            ['no side effects '.uniqid('', true)],
        );

        if (Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')) {
            self::assertSame($beforeTasks, (int) DB::connection('omi_seo_ai')->table('seo_project_tasks')->count());
        }
        if (Schema::connection('omi_seo_ai')->hasTable('seo_mcp_source_snapshots')) {
            self::assertSame($beforeMcp, (int) DB::connection('omi_seo_ai')->table('seo_mcp_source_snapshots')->count());
        }
    }

    private function createArticle(string $observedStatus = 'draft'): SeoArticle
    {
        $this->seq++;
        $siteId = 9_710_000 + $this->seq;
        $token = 'vk-'.uniqid('', true);

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'title' => 'Cach chon balo '.$token,
            'slug' => $token,
            'type' => 'article',
            'status' => $observedStatus === 'publish' ? 'publish' : 'draft',
            'language' => 'vi',
        ]);

        WordpressArticleLink::query()->create([
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'wp_post_id' => 8_710_000 + $this->seq,
            'observed_post_status' => $observedStatus,
            'observed_permalink' => 'https://example-'.$siteId.'.test/'.$token,
            'observed_at' => now(),
        ]);

        return $article->fresh(['wordpressLink']) ?? $article;
    }
}
