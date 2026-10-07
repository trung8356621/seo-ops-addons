<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupManualService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticRefreshService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticSearchService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Tests\TestCase;

final class KeywordGroupRenameAndTopicCandidateTest extends TestCase
{
    private const SITE = 12;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php')->up();
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_07_190000_add_is_topic_candidate_to_seo_keyword_group_keywords.php')->up();
    }

    public function test_rename_enrichment_appends_only_accepted_unassigned_matches(): void
    {
        $vi = $this->createArticle(self::SITE, 'vi');
        $accepted = $this->createInventoryKeyword('xưởng may balo quà tặng giá rẻ tại Hợp Phát', $vi);
        // Lexically overlaps query tokens but Python marks nearest-only (not accepted).
        $neighbor = $this->createInventoryKeyword('balo học sinh quà tặng xa nghĩa', $vi);
        $assignedElsewhere = $this->createInventoryKeyword('xưởng may balo quà tặng tại tphcm', $vi);
        $other = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Other',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $other->id,
            'keyword_id' => $assignedElsewhere,
            'source' => KeywordGroupSource::MANUAL,
            'is_topic_candidate' => true,
        ]);

        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Xưởng may balo quà tặng',
            'source' => KeywordGroupSource::SEMANTIC,
            'is_locked' => false,
        ]);

        $searchCalls = 0;
        Http::fake([
            'semantic.test/v1/keyword-groups/search' => function ($request) use (
                &$searchCalls,
                $accepted,
                $neighbor,
                $assignedElsewhere,
            ) {
                $searchCalls++;
                $data = $request->data();
                $refs = array_column($data['keywords'] ?? [], 'ref');
                self::assertSame('balo quà tặng', $data['query'] ?? null);
                self::assertContains((string) $accepted, $refs);
                self::assertContains((string) $neighbor, $refs);
                self::assertNotContains((string) $assignedElsewhere, $refs);

                return Http::response([
                    'scope_ref' => (string) self::SITE,
                    'query' => 'balo quà tặng',
                    'language' => 'vi',
                    'candidate_count' => 2,
                    'acceptance_min_score' => 0.70,
                    'embedding_cache' => ['hits' => 0, 'misses' => 2],
                    'hits' => [
                        [
                            'ref' => (string) $accepted,
                            'text' => 'xưởng may balo quà tặng giá rẻ tại Hợp Phát',
                            'similarity_score' => 0.91,
                            'accepted' => true,
                        ],
                        [
                            'ref' => (string) $neighbor,
                            'text' => 'balo học sinh quà tặng xa nghĩa',
                            'similarity_score' => 0.42,
                            'accepted' => false,
                        ],
                    ],
                ], 200);
            },
        ]);

        $renamed = app(KeywordGroupManualService::class)->rename(
            self::SITE,
            (int) $group->id,
            'balo quà tặng',
        );
        self::assertSame('balo quà tặng', $renamed->name);

        $result = app(KeywordGroupSemanticSearchService::class)->appendAcceptedMatchesAfterRename(
            self::SITE,
            (int) $group->id,
            (string) $renamed->name,
            ['vi'],
        );

        self::assertSame(1, $searchCalls);
        self::assertFalse($result['semantic_failed']);
        self::assertSame([$accepted], $result['appended_ids']);
        self::assertTrue(
            SeoKeywordGroupKeyword::query()
                ->where('group_id', $group->id)
                ->where('keyword_id', $accepted)
                ->exists(),
        );
        self::assertFalse(
            SeoKeywordGroupKeyword::query()
                ->where('group_id', $group->id)
                ->where('keyword_id', $neighbor)
                ->exists(),
        );
        self::assertSame(
            (int) $other->id,
            (int) SeoKeywordGroupKeyword::query()
                ->where('keyword_id', $assignedElsewhere)
                ->value('group_id'),
        );
        self::assertSame(KeywordGroupSource::MANUAL, SeoKeywordGroup::query()->find($group->id)?->source);
    }

    public function test_rename_survives_when_semantic_enrichment_fails(): void
    {
        $vi = $this->createArticle(self::SITE, 'vi');
        $this->createInventoryKeyword('balo quà tặng ứng viên', $vi);

        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Old name',
            'source' => KeywordGroupSource::SEMANTIC,
            'is_locked' => false,
        ]);

        Http::fake([
            'semantic.test/v1/keyword-groups/search' => Http::response(['error' => 'down'], 500),
        ]);

        $renamed = app(KeywordGroupManualService::class)->rename(self::SITE, (int) $group->id, 'Balo quà tặng');
        self::assertSame('Balo quà tặng', $renamed->name);
        self::assertSame(KeywordGroupSource::MANUAL, $renamed->source);

        $result = app(KeywordGroupSemanticSearchService::class)->appendAcceptedMatchesAfterRename(
            self::SITE,
            (int) $group->id,
            'Balo quà tặng',
            ['vi'],
        );
        self::assertTrue($result['semantic_failed']);
        self::assertSame([], $result['appended_ids']);
        self::assertSame('Balo quà tặng', SeoKeywordGroup::query()->find($group->id)?->name);
        self::assertSame(0, SeoKeywordGroupKeyword::query()->where('group_id', $group->id)->count());
    }

    public function test_missing_accepted_flag_is_not_auto_appended(): void
    {
        $vi = $this->createArticle(self::SITE, 'vi');
        $topNeighbor = $this->createInventoryKeyword('xưởng may balo quà tặng giá rẻ', $vi);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);

        Http::fake([
            'semantic.test/v1/keyword-groups/search' => Http::response([
                'scope_ref' => (string) self::SITE,
                'query' => 'balo',
                'hits' => [
                    [
                        'ref' => (string) $topNeighbor,
                        'text' => 'xưởng may balo quà tặng giá rẻ',
                        'similarity_score' => 0.95,
                        // no accepted → Laravel must not append
                    ],
                ],
                'candidate_count' => 1,
                'embedding_cache' => ['hits' => 0, 'misses' => 1],
            ], 200),
        ]);

        $result = app(KeywordGroupSemanticSearchService::class)->appendAcceptedMatchesAfterRename(
            self::SITE,
            (int) $group->id,
            'balo',
            ['vi'],
        );
        self::assertSame([], $result['appended_ids']);
        self::assertSame(0, SeoKeywordGroupKeyword::query()->where('group_id', $group->id)->count());
    }

    public function test_semantic_search_returns_only_unassigned_sorted_and_limited(): void
    {
        $vi = $this->createArticle(self::SITE, 'vi');
        $assigned = $this->createInventoryKeyword('balo quà tặng đã gán', $vi);
        $best = $this->createInventoryKeyword('xưởng may balo quà tặng giá rẻ', $vi);
        $second = $this->createInventoryKeyword('xưởng may balo quà tặng tại tphcm', $vi);
        $noise = $this->createInventoryKeyword('cách giặt áo thun cotton', $vi);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => $assigned,
            'source' => KeywordGroupSource::MANUAL,
            'is_topic_candidate' => true,
        ]);

        Http::fake([
            'semantic.test/v1/keyword-groups/search' => function ($request) use ($best, $second, $noise, $assigned) {
                $data = $request->data();
                $refs = array_column($data['keywords'] ?? [], 'ref');
                self::assertSame((string) self::SITE, $data['scope_ref'] ?? null);
                self::assertSame('balo quà tặng', $data['query'] ?? null);
                self::assertContains((string) $best, $refs);
                self::assertContains((string) $second, $refs);
                self::assertNotContains((string) $assigned, $refs);
                unset($noise);

                return Http::response([
                    'scope_ref' => (string) self::SITE,
                    'query' => 'balo quà tặng',
                    'language' => 'vi',
                    'candidate_count' => 3,
                    'embedding_cache' => ['hits' => 0, 'misses' => 3],
                    'hits' => [
                        [
                            'ref' => (string) $best,
                            'text' => 'xưởng may balo quà tặng giá rẻ',
                            'similarity_score' => 0.91,
                            'accepted' => true,
                        ],
                        [
                            'ref' => (string) $second,
                            'text' => 'xưởng may balo quà tặng tại tphcm',
                            'similarity_score' => 0.88,
                            'accepted' => true,
                        ],
                    ],
                ], 200);
            },
        ]);

        $hits = (new KeywordGroupSemanticSearchService(
            new SemanticAnalyticsClient,
            new TopicGroupingInputHasher,
            app(KeywordGroupReadModel::class),
            app(KeywordGroupManualService::class),
        ))->suggestUnassigned(self::SITE, 'balo quà tặng', ['vi'], 20);

        self::assertCount(2, $hits);
        self::assertSame($best, $hits[0]['keyword_id']);
        self::assertSame($second, $hits[1]['keyword_id']);
        self::assertTrue($hits[0]['accepted']);
        self::assertTrue($hits[0]['similarity_score'] >= $hits[1]['similarity_score']);
    }

    public function test_read_model_exposes_is_topic_candidate_false(): void
    {
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => 77,
            'source' => KeywordGroupSource::MANUAL,
            'is_topic_candidate' => false,
        ]);

        $chunk = app(KeywordGroupReadModel::class)->groupMembers(self::SITE, (int) $group->id, 50, 0);
        self::assertCount(1, $chunk['members']);
        self::assertArrayHasKey('is_topic_candidate', $chunk['members'][0]);
        self::assertFalse($chunk['members'][0]['is_topic_candidate']);
    }

    public function test_topic_candidate_flag_toggles_without_leaving_group_or_changing_count(): void
    {
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => 77,
            'source' => KeywordGroupSource::MANUAL,
            'is_topic_candidate' => true,
        ]);

        $manual = app(KeywordGroupManualService::class);
        $manual->setTopicCandidate(self::SITE, (int) $group->id, 77, false);
        $row = SeoKeywordGroupKeyword::query()->where('keyword_id', 77)->first();
        self::assertFalse((bool) $row?->is_topic_candidate);
        self::assertSame((int) $group->id, (int) $row?->group_id);
        self::assertSame(1, SeoKeywordGroupKeyword::query()->where('group_id', $group->id)->count());

        $manual->setTopicCandidate(self::SITE, (int) $group->id, 77, true);
        self::assertTrue((bool) SeoKeywordGroupKeyword::query()->where('keyword_id', 77)->value('is_topic_candidate'));
        self::assertSame(1, SeoKeywordGroupKeyword::query()->where('group_id', $group->id)->count());
    }

    public function test_remove_still_clears_membership(): void
    {
        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Balo',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => 88,
            'source' => KeywordGroupSource::MANUAL,
            'is_topic_candidate' => false,
        ]);

        app(KeywordGroupManualService::class)->assignKeyword(self::SITE, 88, null);
        self::assertSame(0, SeoKeywordGroupKeyword::query()->where('group_id', $group->id)->count());
    }

    public function test_semantic_refresh_preserves_false_flag_on_manual_group(): void
    {
        $manual = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Manual keep',
            'source' => KeywordGroupSource::MANUAL,
            'representative_keyword_id' => 10,
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $manual->id,
            'keyword_id' => 10,
            'source' => KeywordGroupSource::MANUAL,
            'is_topic_candidate' => false,
        ]);

        Http::fake([
            'semantic.test/v1/keyword-groups/analyses' => Http::response([
                'analysis_id' => 'a1',
                'status' => 'completed',
                'scope_ref' => (string) self::SITE,
                'language' => 'vi',
                'input_hash' => str_repeat('cd', 32),
                'groups' => [[
                    'group_ref' => 'g-new',
                    'representative_ref' => '20',
                    'representative_text' => 'new semantic',
                    'member_count' => 1,
                    'mean_similarity' => 1,
                    'min_similarity' => 1,
                    'cohesion' => 1,
                    'members' => [
                        ['ref' => '20', 'text' => 'new semantic', 'similarity_score' => 1, 'is_representative' => true],
                    ],
                ]],
                'unassigned' => [],
                'diagnostics' => ['algorithm' => 'hybrid_semantic_lexical_v1'],
            ], 200),
        ]);

        (new KeywordGroupSemanticRefreshService(
            new SemanticAnalyticsClient,
            new TopicGroupingInputHasher,
            new \Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader,
        ))->refreshKeywords(self::SITE, 'vi', [
            ['keyword_id' => 10, 'phrase' => 'manual keep'],
            ['keyword_id' => 20, 'phrase' => 'new semantic'],
        ]);

        self::assertFalse((bool) SeoKeywordGroupKeyword::query()
            ->where('group_id', $manual->id)
            ->where('keyword_id', 10)
            ->value('is_topic_candidate'));
        $new = SeoKeywordGroup::query()->where('semantic_group_ref', 'g-new')->first();
        self::assertNotNull($new);
        self::assertTrue((bool) SeoKeywordGroupKeyword::query()
            ->where('group_id', $new->id)
            ->where('keyword_id', 20)
            ->value('is_topic_candidate'));
    }

    public function test_ui_exposes_rename_enrichment_and_blocked_chip_state(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordGroups.php');
        self::assertStringContainsString('appendAcceptedMatchesAfterRename', $page);
        self::assertStringContainsString('toggleTopicCandidate', $page);
        self::assertStringContainsString('keyword_group_rename_semantic_failed', $page);

        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-groups.blade.php');
        self::assertStringContainsString('@dblclick', $blade);
        self::assertStringContainsString('toggleTopicCandidate', $blade);
        self::assertStringContainsString('keyword-group-member-chip--topic-blocked', $blade);
        self::assertStringContainsString('aria-pressed', $blade);
        self::assertStringContainsString('data-topic-candidate', $blade);
        self::assertStringContainsString('keyword_group_rename_hint', $blade);
        self::assertStringNotContainsString("\$wire.renameGroup({{ \$groupId }}, name)", $blade);
        self::assertStringNotContainsString('<x-select', $blade);
        self::assertStringNotContainsString('keyword_group_move', $blade);

        $css = (string) file_get_contents(dirname(__DIR__, 4).'/seo/resources/css/keyword-workspace.css');
        self::assertStringContainsString(
            '.keyword-group-member-chip.keyword-group-member-chip--topic-blocked',
            $css,
        );
    }

    private function createInventoryKeyword(string $phrase, int $sourceArticleId): int
    {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => 'active',
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => (int) $keyword->id,
            'source_article_id' => $sourceArticleId,
            'target_article_id' => $sourceArticleId,
            'anchor_text' => $phrase,
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $keyword->id;
    }

    private function createArticle(int $siteId, string $language): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
            'title' => 'A',
            'language' => $language,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->string('review_status')->default('active');
            $table->timestamps();
        });
        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        $schema->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->unsignedBigInteger('source_article_id');
            $table->unsignedBigInteger('target_article_id')->nullable();
            $table->text('anchor_text');
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->timestamps();
        });
    }
}
