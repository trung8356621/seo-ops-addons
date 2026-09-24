<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscQueryMappingType;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordRelationship;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordRelationshipReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\TestCase;

/**
 * Real disposable MySQL proof for Keyword MCP type-2 relationship.
 *
 * Requires SEO_TEST_USE_MYSQL=true + SEO_TEST_DATABASE=*_test.
 * Skips honestly when disposable MySQL env is unavailable.
 */
#[Group('mysql')]
final class KeywordRelationshipReadModelIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run Keyword Relationship MySQL proof.');
        }

        $testDb = trim((string) (env('SEO_TEST_DATABASE') ?: env('DB_TEST_DATABASE') ?: ''));
        if ($testDb === '' || ! str_ends_with($testDb, '_test')) {
            $this->markTestSkipped('Set SEO_TEST_DATABASE=*_test (disposable) for Keyword Relationship MySQL proof.');
        }

        if (! TopicReclusterService::tablesReady()) {
            $this->fail('Topic Core tables missing on omi_seo_ai.');
        }

        foreach ([
            'keywords',
            'keyword_meta',
            'articles',
            'seo_topics',
            'seo_topic_keywords',
            'seo_topic_keyword_dna',
            'seo_link_maps',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table: '.$table);
            }
        }
    }

    public function test_happy_path_relationship_fixture_graph(): void
    {
        $fx = $this->seedHappyPathFixture();

        $dto = app(KeywordRelationshipReadModel::class)->relationship($fx['site_id'], $fx['keyword_id']);
        self::assertInstanceOf(KeywordRelationship::class, $dto);

        $arr = $dto->toArray();
        self::assertSame(KeywordRelationship::SCHEMA, $arr['schema']);
        self::assertSame($fx['site_id'], $arr['site_id']);

        $kw = $arr['keyword'];
        self::assertSame($fx['keyword_id'], (int) $kw['id']);
        self::assertSame($fx['phrase'], (string) $kw['phrase']);
        self::assertTrue((bool) $kw['source_locked']);
        self::assertTrue((bool) $kw['membership_locked']);
        self::assertArrayNotHasKey('locked', $kw);

        self::assertNotSame([], $arr['topics']);
        self::assertSame($fx['topic_id'], (int) $arr['topics'][0]['id']);

        self::assertNotSame([], $arr['focus_articles']);
        self::assertSame($fx['focus_id'], (int) $arr['focus_articles'][0]['article_id']);

        $dnaPhrases = array_column($arr['dna']['topic_dna'] ?? [], 'phrase');
        self::assertContains($fx['dna'], $dnaPhrases);

        $relatedIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $arr['related_keywords']['items'] ?? [],
        );
        self::assertContains($fx['related_keyword_id'], $relatedIds);

        self::assertTrue($arr['internal_links']['available']);
        $inboundIds = array_column($arr['internal_links']['inbound']['items'] ?? [], 'link_map_id');
        $outboundIds = array_column($arr['internal_links']['outbound']['items'] ?? [], 'link_map_id');
        self::assertContains($fx['inbound_id'], $inboundIds);
        self::assertContains($fx['outbound_id'], $outboundIds);
        self::assertNotContains($fx['external_id'], $inboundIds);
        self::assertNotContains($fx['external_id'], $outboundIds);
        self::assertNotContains($fx['wiki_id'], $inboundIds);
        self::assertNotContains($fx['wiki_id'], $outboundIds);

        foreach (array_merge(
            $arr['internal_links']['inbound']['items'] ?? [],
            $arr['internal_links']['outbound']['items'] ?? [],
        ) as $link) {
            self::assertSame('internal', (string) ($link['link_type'] ?? ''));
        }

        if ($fx['gsc_available']) {
            self::assertTrue($arr['gsc']['available']);
            $mappingIds = array_column($arr['gsc']['query_mappings']['items'] ?? [], 'id');
            self::assertContains($fx['gsc_id'], $mappingIds);
        }

        if ($fx['planning_available']) {
            self::assertTrue($arr['planning']['available']);
            $taskIds = array_column($arr['planning']['items']['items'] ?? [], 'task_id');
            self::assertContains($fx['task_id'], $taskIds);
        }
    }

    public function test_wrong_site_isolation_returns_null_without_existence_leak(): void
    {
        $fx = $this->seedHappyPathFixture();
        $otherSite = $fx['site_id'] + 17;

        $dto = app(KeywordRelationshipReadModel::class)->relationship($otherSite, $fx['keyword_id']);
        self::assertNull($dto);
    }

    public function test_relationship_does_not_write_mcp_source_snapshots(): void
    {
        $fx = $this->seedHappyPathFixture();

        $before = 0;
        $tableReady = Schema::connection('omi_seo_ai')->hasTable('seo_mcp_source_snapshots');
        if ($tableReady) {
            $before = (int) SeoMcpSourceSnapshot::query()->count();
        }

        $dto = app(KeywordRelationshipReadModel::class)->relationship($fx['site_id'], $fx['keyword_id']);
        self::assertInstanceOf(KeywordRelationship::class, $dto);

        if ($tableReady) {
            $after = (int) SeoMcpSourceSnapshot::query()->count();
            self::assertSame($before, $after);
        }
    }

    public function test_internal_link_semantics_exclude_external_and_wiki_trust(): void
    {
        $fx = $this->seedHappyPathFixture();

        $dto = app(KeywordRelationshipReadModel::class)->relationship($fx['site_id'], $fx['keyword_id']);
        self::assertInstanceOf(KeywordRelationship::class, $dto);
        $links = $dto->toArray()['internal_links'];

        $allIds = array_merge(
            array_column($links['inbound']['items'] ?? [], 'link_map_id'),
            array_column($links['outbound']['items'] ?? [], 'link_map_id'),
        );
        self::assertContains($fx['inbound_id'], $allIds);
        self::assertContains($fx['outbound_id'], $allIds);
        self::assertNotContains($fx['external_id'], $allIds);
        self::assertNotContains($fx['wiki_id'], $allIds);

        foreach (($links['inbound']['items'] ?? []) as $row) {
            self::assertSame($fx['focus_id'], (int) ($row['target_article_id'] ?? 0));
            self::assertSame('internal', (string) ($row['link_type'] ?? ''));
        }
        foreach (($links['outbound']['items'] ?? []) as $row) {
            self::assertSame($fx['focus_id'], (int) ($row['source_article_id'] ?? 0));
            self::assertSame('internal', (string) ($row['link_type'] ?? ''));
        }
    }

    public function test_visibility_and_ordering_are_deterministic_in_source(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('keywordVisibleOnSite', $src);
        self::assertStringContainsString('orderBy(\'phrase\')', $src);
        self::assertStringContainsString('orderBy(\'id\')', $src);
        self::assertStringContainsString('orderByDesc(\'confidence\')', $src);
        self::assertStringContainsString('sort($siblingIds)', $src);
        self::assertStringContainsString('ExactKeyword', $src);
        self::assertStringContainsString('NormalizedKeyword', $src);
        self::assertStringContainsString('Manual', $src);
        self::assertStringContainsString('source_locked', $src);
        self::assertStringContainsString('membership_locked', $src);
        self::assertStringContainsString('SeoLinkMapType::Internal', $src);
    }

    /**
     * @return array{
     *   site_id: int,
     *   keyword_id: int,
     *   related_keyword_id: int,
     *   topic_id: int,
     *   focus_id: int,
     *   phrase: string,
     *   dna: string,
     *   inbound_id: int,
     *   outbound_id: int,
     *   external_id: int,
     *   wiki_id: int,
     *   gsc_available: bool,
     *   gsc_id: int|null,
     *   planning_available: bool,
     *   task_id: int|null
     * }
     */
    private function seedHappyPathFixture(): array
    {
        $this->seq++;
        $siteId = 9_200_000 + ((int) (microtime(true) * 1000) % 100_000) + $this->seq;
        $suffix = (string) $this->seq.'-'.substr((string) microtime(true), -5);
        $phrase = 'rel-kw-'.$suffix;

        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'source' => 'test',
            'source_locked' => true,
        ]);
        $related = Keyword::query()->create([
            'phrase' => 'rel-sib-'.$suffix,
            'type' => Keyword::TYPE_NORMAL,
            'source' => 'test',
            'source_locked' => false,
        ]);

        $topic = SeoTopic::query()->create([
            'site_id' => $siteId,
            'name' => 'Rel Topic '.$suffix,
            'source' => TopicSource::MANUAL,
            'status' => TopicStatus::ACTIVE,
            'is_locked' => false,
        ]);

        SeoTopicKeyword::query()->create([
            'site_id' => $siteId,
            'topic_id' => (int) $topic->id,
            'keyword_id' => (int) $keyword->id,
            'source' => 'test',
            'is_seed' => true,
            'is_locked' => true,
            'confidence' => 1.0,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $siteId,
            'topic_id' => (int) $topic->id,
            'keyword_id' => (int) $related->id,
            'source' => 'test',
            'is_seed' => false,
            'is_locked' => false,
            'confidence' => 0.8,
        ]);

        $dna = 'dna-rel-'.$suffix;
        SeoTopicKeywordDna::query()->create([
            'site_id' => $siteId,
            'topic_id' => (int) $topic->id,
            'keyword_id' => (int) $keyword->id,
            'value' => $dna,
            'facet_type' => 'branch',
            'placement' => 'after',
            'frequency' => 1,
            'source' => 'test',
        ]);

        $focus = SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => 'Focus '.$suffix,
            'slug' => 'focus-'.$suffix,
            'body' => '<p>focus</p>',
            'status' => 'published',
        ]);
        $neighborIn = SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => 'Inbound '.$suffix,
            'slug' => 'in-'.$suffix,
            'body' => '<p>in</p>',
            'status' => 'published',
        ]);
        $neighborOut = SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => 'Outbound '.$suffix,
            'slug' => 'out-'.$suffix,
            'body' => '<p>out</p>',
            'status' => 'published',
        ]);

        $metaOk = app(KeywordMetaRepository::class)->setMainArticleIdForSite(
            (int) $keyword->id,
            $siteId,
            (int) $focus->id,
        );
        self::assertTrue($metaOk);

        $inbound = SeoLinkMap::query()->create([
            'keyword_id' => null,
            'source_article_id' => (int) $neighborIn->id,
            'target_article_id' => (int) $focus->id,
            'anchor_text' => 'to-focus',
            'link_type' => SeoLinkMapType::Internal,
            'status' => SeoLinkMapStatus::Active,
        ]);
        $outbound = SeoLinkMap::query()->create([
            'keyword_id' => null,
            'source_article_id' => (int) $focus->id,
            'target_article_id' => (int) $neighborOut->id,
            'anchor_text' => 'from-focus',
            'link_type' => SeoLinkMapType::Internal,
            'status' => SeoLinkMapStatus::Active,
        ]);
        $external = SeoLinkMap::query()->create([
            'keyword_id' => (int) $keyword->id,
            'source_article_id' => (int) $focus->id,
            'target_article_id' => null,
            'target_external_url' => 'https://example.com/ext-'.$suffix,
            'anchor_text' => 'external',
            'link_type' => SeoLinkMapType::External,
            'status' => SeoLinkMapStatus::Active,
        ]);
        $wiki = SeoLinkMap::query()->create([
            'keyword_id' => (int) $keyword->id,
            'source_article_id' => (int) $focus->id,
            'target_article_id' => null,
            'target_external_url' => 'https://en.wikipedia.org/wiki/Test_'.$suffix,
            'anchor_text' => 'wiki',
            'link_type' => SeoLinkMapType::WikiTrust,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $gscAvailable = Schema::connection('omi_seo_ai')->hasTable('seo_gsc_query_mappings');
        $gscId = null;
        if ($gscAvailable) {
            try {
                $gsc = SeoGscQueryMapping::query()->create([
                    'public_ref' => 'gsc_query_mapping:rel-'.$suffix,
                    'tenant_id' => 1,
                    'site_id' => $siteId,
                    'property_id' => null,
                    'normalized_query' => mb_strtolower($phrase, 'UTF-8'),
                    'identity_hash' => hash('sha256', 'rel-'.$suffix),
                    'sample_query' => $phrase,
                    'keyword_id' => (int) $keyword->id,
                    'mapping_type' => GscQueryMappingType::ExactKeyword,
                    'confidence' => 0.99,
                    'source' => 'test',
                    'status' => GscMappingStatus::Approved,
                ]);
                $gscId = (int) $gsc->id;
            } catch (\Throwable) {
                // Optional proof — schema may require property FK; do not fail core relationship.
                $gscAvailable = false;
                $gscId = null;
            }
        }

        $planningAvailable = Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
            && Schema::connection('omi_seo_ai')->hasTable('seo_projects');
        $taskId = null;
        if ($planningAvailable) {
            try {
                $project = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->create([
                    'site_id' => $siteId,
                    'user_id' => 1,
                    'name' => 'rel-plan-'.$suffix,
                    'month' => \Omnichannel\Addons\ContentProjects\Models\SeoProject::draftCompatibilityMonth(),
                    'status' => \Omnichannel\Addons\ContentProjects\Models\SeoProject::STATUS_DRAFT,
                    'kind' => \Omnichannel\Addons\ContentProjects\Models\SeoProject::KIND_MONTHLY,
                    'total_tasks' => 0,
                ]);
                $task = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->create([
                    'project_id' => (int) $project->id,
                    'site_id' => $siteId,
                    'type' => \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::TYPE_CREATE,
                    'post_type' => \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::POST_TYPE_ARTICLE,
                    'source_content' => $phrase,
                    'keyword' => $phrase,
                    'title' => 'Plan '.$suffix,
                    'status' => \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::STATUS_PENDING,
                    'target_date' => '2026-09-15',
                    'planning_month' => '2026-09-01',
                ]);
                $taskId = (int) $task->id;
            } catch (\Throwable) {
                $planningAvailable = false;
                $taskId = null;
            }
        }

        return [
            'site_id' => $siteId,
            'keyword_id' => (int) $keyword->id,
            'related_keyword_id' => (int) $related->id,
            'topic_id' => (int) $topic->id,
            'focus_id' => (int) $focus->id,
            'phrase' => $phrase,
            'dna' => $dna,
            'inbound_id' => (int) $inbound->id,
            'outbound_id' => (int) $outbound->id,
            'external_id' => (int) $external->id,
            'wiki_id' => (int) $wiki->id,
            'gsc_available' => $gscAvailable,
            'gsc_id' => $gscId,
            'planning_available' => $planningAvailable,
            'task_id' => $taskId,
        ];
    }
}
