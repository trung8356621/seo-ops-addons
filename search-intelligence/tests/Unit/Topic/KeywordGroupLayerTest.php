<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\RefreshKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupCandidateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupManualService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticRefreshService;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Tests\TestCase;

final class KeywordGroupLayerTest extends TestCase
{
    private const SITE = 7;

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

        $schema = Schema::connection('omi_seo_ai');
        $schema->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->timestamps();
        });
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
        $schema->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source')->nullable();
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
        $schema->create('seo_topic_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('value');
            $table->timestamps();
        });

        $now = now();
        DB::connection('omi_seo_ai')->table('seo_topics')->insert([
            'id' => 1,
            'site_id' => self::SITE,
            'name' => 'Legacy topic',
            'source' => 'manual',
            'status' => 'active',
            'is_locked' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('omi_seo_ai')->table('seo_topic_keywords')->insert([
            'site_id' => self::SITE,
            'topic_id' => 1,
            'keyword_id' => 99,
            'source' => 'manual',
            'is_seed' => 0,
            'is_locked' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('omi_seo_ai')->table('seo_topic_keyword_dna')->insert([
            'site_id' => self::SITE,
            'topic_id' => 1,
            'keyword_id' => 99,
            'value' => 'hoc-sinh',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php';
        $migration->up();
    }

    public function test_schema_models_unique_and_topic_relation(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        self::assertTrue($schema->hasTable('seo_keyword_groups'));
        self::assertTrue($schema->hasTable('seo_keyword_group_keywords'));
        self::assertTrue($schema->hasColumn('seo_topics', 'keyword_group_id'));

        $legacy = SeoTopic::query()->find(1);
        self::assertNotNull($legacy);
        self::assertSame('Legacy topic', $legacy->name);
        self::assertNull($legacy->keyword_group_id);
        self::assertSame(1, DB::connection('omi_seo_ai')->table('seo_topic_keywords')->count());
        self::assertSame('hoc-sinh', DB::connection('omi_seo_ai')->table('seo_topic_keyword_dna')->value('value'));

        $group = SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => 'Học sinh',
            'source' => KeywordGroupSource::MANUAL,
            'is_locked' => false,
        ]);
        SeoTopic::query()->whereKey(1)->update(['keyword_group_id' => $group->id]);
        SeoTopic::query()->create([
            'site_id' => self::SITE,
            'name' => 'Second topic',
            'source' => 'manual',
            'status' => 'active',
            'keyword_group_id' => $group->id,
        ]);

        self::assertSame(2, $group->topics()->count());
        self::assertSame((int) $group->id, (int) SeoTopic::query()->find(1)?->keywordGroup?->id);
        self::assertSame(1, DB::connection('omi_seo_ai')->table('seo_topic_keywords')->count());

        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => 10,
            'source' => KeywordGroupSource::MANUAL,
        ]);

        $this->expectException(QueryException::class);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => 10,
            'source' => KeywordGroupSource::MANUAL,
        ]);
    }

    public function test_manual_and_locked_groups_survive_semantic_refresh(): void
    {
        $manual = $this->group('Manual balo', KeywordGroupSource::MANUAL, false, 10);
        $this->member($manual, 10, KeywordGroupSource::MANUAL);
        $locked = $this->group('Locked cặp', KeywordGroupSource::SEMANTIC, true, 30);
        $this->member($locked, 30, KeywordGroupSource::SEMANTIC);
        $oldSemantic = $this->group('Old auto', KeywordGroupSource::SEMANTIC, false, 20);
        $this->member($oldSemantic, 20, KeywordGroupSource::SEMANTIC);

        Http::fake([
            'semantic.test/v1/keyword-groups/analyses' => Http::response($this->analysis([
                $this->apiGroup('g-new', '11', 'balo học sinh', [
                    ['ref' => '11', 'text' => 'balo học sinh', 'similarity_score' => 1, 'is_representative' => true],
                    ['ref' => '12', 'text' => 'cặp học sinh', 'similarity_score' => 0.8, 'is_representative' => false],
                ]),
            ]), 200),
        ]);

        $result = $this->service()->refreshKeywords(self::SITE, 'vi', [
            ['keyword_id' => 10, 'phrase' => 'manual keyword'],
            ['keyword_id' => 30, 'phrase' => 'locked keyword'],
            ['keyword_id' => 11, 'phrase' => 'balo học sinh'],
            ['keyword_id' => 12, 'phrase' => 'cặp học sinh'],
            ['keyword_id' => 20, 'phrase' => 'old semantic keyword'],
        ]);

        self::assertFalse($result->skipped);
        self::assertSame(2, $result->preservedGroupCount);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $data = $request->data();
            $refs = array_column($data['keywords'] ?? [], 'ref');
            sort($refs);

            return str_ends_with($request->url(), '/v1/keyword-groups/analyses')
                && ($data['scope_ref'] ?? null) === (string) self::SITE
                && ($data['language'] ?? null) === 'vi'
                && $refs === ['11', '12', '20']
                && ! array_key_exists('site_ref', $data)
                && ! array_key_exists('rebuild_mode', $data)
                && ! array_key_exists('topics', $data)
                && ! array_key_exists('ids', $data);
        });

        $manual->refresh();
        $locked->refresh();
        self::assertSame(KeywordGroupSource::MANUAL, $manual->source);
        self::assertSame('Manual balo', $manual->name);
        self::assertSame($manual->id, $this->groupIdForKeyword(10));
        self::assertTrue($locked->is_locked);
        self::assertSame($locked->id, $this->groupIdForKeyword(30));
        self::assertNull(SeoKeywordGroup::query()->find($oldSemantic->id));
        self::assertNull($this->groupIdForKeyword(20));
        $created = SeoKeywordGroup::query()->where('semantic_group_ref', 'g-new')->first();
        self::assertNotNull($created);
        self::assertSame(KeywordGroupSource::SEMANTIC, $created->source);
        self::assertSame('balo học sinh', $created->name);
        self::assertSame(11, (int) $created->representative_keyword_id);
        self::assertSame([11, 12], $this->keywordIdsForGroup((int) $created->id));
        $this->assertTopicsUntouched();
    }

    public function test_http_and_contract_failures_leave_groups_and_topics_intact(): void
    {
        $semantic = $this->group('Keep me', KeywordGroupSource::SEMANTIC, false, 11);
        $this->member($semantic, 11, KeywordGroupSource::SEMANTIC);
        $beforeTopics = $this->topicSnapshot();

        $duplicate = $this->analysis([
            $this->apiGroup('g1', '11', 'one', [
                ['ref' => '11', 'text' => 'one', 'similarity_score' => 1, 'is_representative' => true],
            ]),
            $this->apiGroup('g2', '11', 'dup', [
                ['ref' => '11', 'text' => 'dup', 'similarity_score' => 1, 'is_representative' => true],
            ]),
        ]);
        $attempt = 0;
        Http::fake(function () use (&$attempt, $duplicate) {
            $attempt++;

            return $attempt === 1
                ? Http::response(['error' => 'down'], 500)
                : Http::response($duplicate, 200);
        });

        try {
            $this->service()->refreshKeywords(self::SITE, 'vi', [
                ['keyword_id' => 11, 'phrase' => 'balo học sinh'],
            ]);
            self::fail('expected transport failure');
        } catch (SemanticTransportException) {
        }

        self::assertNotNull(SeoKeywordGroup::query()->find($semantic->id));
        self::assertSame($semantic->id, $this->groupIdForKeyword(11));
        self::assertSame($beforeTopics, $this->topicSnapshot());

        try {
            $this->service()->refreshKeywords(self::SITE, 'vi', [
                ['keyword_id' => 11, 'phrase' => 'balo học sinh'],
            ]);
            self::fail('expected contract failure');
        } catch (SemanticInvalidResponseException) {
        }

        self::assertSame('Keep me', SeoKeywordGroup::query()->find($semantic->id)?->name);
        self::assertSame(1, SeoKeywordGroup::query()->count());
        self::assertSame($beforeTopics, $this->topicSnapshot());
    }

    public function test_manual_edit_promotes_semantic_group_and_blocks_reassignment(): void
    {
        $semantic = $this->group('Auto family', KeywordGroupSource::SEMANTIC, false, 1);
        $this->member($semantic, 1, KeywordGroupSource::SEMANTIC);
        $this->member($semantic, 2, KeywordGroupSource::SEMANTIC);
        $manual = app(KeywordGroupManualService::class)->create(self::SITE, 'Moved');
        app(KeywordGroupManualService::class)->assignKeyword(self::SITE, 1, (int) $manual->id);

        $semantic->refresh();
        self::assertSame(KeywordGroupSource::MANUAL, $semantic->source);
        self::assertSame((int) $manual->id, $this->groupIdForKeyword(1));
        self::assertSame((int) $semantic->id, $this->groupIdForKeyword(2));

        app(KeywordGroupManualService::class)->rename(self::SITE, (int) $semantic->id, 'Renamed family');
        app(KeywordGroupManualService::class)->assignKeyword(self::SITE, 2, null);
        $semantic->refresh();
        self::assertSame('Renamed family', $semantic->name);
        self::assertNull($this->groupIdForKeyword(2));

        Http::fake([
            'semantic.test/v1/keyword-groups/analyses' => Http::response($this->analysis([
                $this->apiGroup('g-back', '2', 'stolen', [
                    ['ref' => '2', 'text' => 'stolen', 'similarity_score' => 1, 'is_representative' => true],
                    ['ref' => '3', 'text' => 'new', 'similarity_score' => 0.7, 'is_representative' => false],
                ]),
            ]), 200),
        ]);

        $this->service()->refreshKeywords(self::SITE, 'vi', [
            ['keyword_id' => 1, 'phrase' => 'kept'],
            ['keyword_id' => 2, 'phrase' => 'removed'],
            ['keyword_id' => 3, 'phrase' => 'fresh'],
        ]);

        self::assertSame((int) $manual->id, $this->groupIdForKeyword(1));
        self::assertSame('Renamed family', SeoKeywordGroup::query()->find($semantic->id)?->name);
        self::assertNotSame((int) $semantic->id, $this->groupIdForKeyword(2));
        self::assertSame(KeywordGroupSource::SEMANTIC, SeoKeywordGroup::query()->where('semantic_group_ref', 'g-back')->value('source'));
        $this->assertTopicsUntouched();
    }

    public function test_paginate_groups_and_lazy_members_without_full_hydration(): void
    {
        $this->phrase(10, 'balo học sinh');
        $this->phrase(11, 'cặp học sinh');
        for ($i = 1; $i <= 25; $i++) {
            $group = $this->group(sprintf('Group %02d', $i), KeywordGroupSource::SEMANTIC, false, 10);
            if ($i === 1) {
                $this->member($group, 10, KeywordGroupSource::SEMANTIC);
                $this->member($group, 11, KeywordGroupSource::SEMANTIC);
            }
        }

        $reader = app(KeywordGroupReadModel::class);
        $page1 = $reader->paginateGroups(self::SITE, 1, 20);
        self::assertSame(25, $page1->total());
        self::assertCount(20, $page1->items());
        self::assertArrayHasKey('members', $page1->items()[0]);
        self::assertCount(2, $page1->items()[0]['members']);
        self::assertFalse($page1->items()[0]['members_has_more']);
        self::assertSame(2, (int) $page1->items()[0]['member_count']);
        self::assertSame('balo học sinh', $page1->items()[0]['representative_phrase']);
        self::assertFalse($page1->items()[0]['is_manual']);
        self::assertSame([], $page1->items()[1]['members']);

        $page2 = $reader->paginateGroups(self::SITE, 2, 20);
        self::assertCount(5, $page2->items());

        $firstId = (int) $page1->items()[0]['id'];
        $members = $reader->groupMembers(self::SITE, $firstId, 50, 0);
        self::assertSame(2, $members['total']);
        self::assertFalse($members['has_more']);
        self::assertSame(['balo học sinh', 'cặp học sinh'], array_column($members['members'], 'phrase'));
        self::assertSame(1, $reader->pageForGroup(self::SITE, $firstId, 20));
        self::assertSame(2, $reader->pageForGroup(self::SITE, (int) $page2->items()[0]['id'], 20));
    }

    public function test_workspace_nav_chip_and_refresh_do_not_apply_topics(): void
    {
        $nav = (string) file_get_contents((string) (new \ReflectionClass(HasKeywordWorkspaceNavigation::class))->getFileName());
        preg_match_all("/'key' => '([^']+)'/", $nav, $keys);
        $order = $keys[1];
        $focus = array_search('focus', $order, true);
        $groups = array_search('groups', $order, true);
        $topics = array_search('clusters', $order, true);
        $tags = array_search('tags', $order, true);
        self::assertNotFalse($focus);
        self::assertNotFalse($groups);
        self::assertNotFalse($topics);
        self::assertNotFalse($tags);
        self::assertTrue($groups > $focus && $groups < $topics && $topics < $tags);
        self::assertStringContainsString('KeywordWorkspaceMetricCache::GROUPS', $nav);
        self::assertStringContainsString('SeoKeywordGroup::query()', $nav);
        self::assertStringContainsString("summary(\$siteId, \$languageVariants)['topic_count']", $nav);

        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordGroups.php');
        self::assertStringContainsString("return 'groups';", $page);
        self::assertStringContainsString('resolveKeywordWorkspaceSiteId', $page);
        self::assertStringContainsString('RefreshKeywordGroupsJob', $page);
        self::assertStringContainsString('addKeywordToGroup', $page);
        self::assertStringContainsString('removeKeywordFromGroup', $page);
        self::assertStringContainsString('searchUnassignedKeywords', $page);
        self::assertStringContainsString('WithPagination', $page);
        self::assertStringContainsString('focusGroupPageIfNeeded', $page);
        self::assertStringNotContainsString('toggleExpandGroup', $page);
        self::assertStringNotContainsString('expandedGroupIds', $page);
        self::assertStringNotContainsString('ensureMembersForGroups', $page);
        self::assertStringNotContainsString('ReclusterSiteTopicsJob', $page);
        self::assertStringNotContainsString('TopicGroupingApplyService', $page);
        self::assertStringNotContainsString('getKeywordGroupView', $page);

        $groupsBlade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-groups.blade.php');
        self::assertStringContainsString('keyword_group_unassigned_summary', $groupsBlade);
        self::assertStringContainsString('keyword_group_search_unassigned', $groupsBlade);
        self::assertStringContainsString('keyword-group-member-chip', $groupsBlade);
        self::assertStringContainsString('keyword-group-member-chip__label', $groupsBlade);
        self::assertStringContainsString('removeKeywordFromGroup', $groupsBlade);
        self::assertStringContainsString('addKeywordToGroup', $groupsBlade);
        self::assertStringContainsString('keyword_group_source_manual', $groupsBlade);
        self::assertStringContainsString('keyword_group_source_auto', $groupsBlade);
        self::assertStringContainsString('->links()', $groupsBlade);
        self::assertStringNotContainsString('toggleExpandGroup', $groupsBlade);
        self::assertStringNotContainsString('keyword_group_move', $groupsBlade);
        self::assertStringNotContainsString('<x-select', $groupsBlade);
        self::assertStringNotContainsString('wire:change="assignKeyword', $groupsBlade);

        $css = (string) file_get_contents(dirname(__DIR__, 4).'/seo/resources/css/keyword-workspace.css');
        self::assertStringContainsString('.keyword-group-member-chip', $css);
        self::assertStringContainsString('font-size: 0.875rem', $css);

        $chip = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/topic-group-chip.blade.php');
        self::assertStringContainsString('topic-group-chip', $chip);
        self::assertStringContainsString("['group' => \$groupId]", $chip);
        self::assertStringContainsString("getUrl('groups'", $chip);
        self::assertStringNotContainsString('TopicUserTag', $chip);
        self::assertStringNotContainsString('seo_topic_tags', $chip);

        $topicBlade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');
        self::assertStringContainsString('topic-group-chip', $topicBlade);

        $refresh = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/KeywordGroup/KeywordGroupSemanticRefreshService.php');
        $job = (string) file_get_contents(dirname(__DIR__, 3).'/src/Jobs/RefreshKeywordGroupsJob.php');
        self::assertStringContainsString('/v1/keyword-groups/analyses', $refresh);
        self::assertStringNotContainsString('TopicGroupingApplyService', $refresh);
        self::assertStringNotContainsString('ReclusterSiteTopicsJob', $refresh);
        self::assertStringNotContainsString('/v1/topic/analyses', $refresh);
        self::assertStringNotContainsString('seo_topics', $refresh);
        self::assertStringNotContainsString('TopicGroupingApplyService', $job);
        self::assertStringContainsString('refreshSite', $job);
        self::assertTrue(class_exists(ReclusterSiteTopicsJob::class));
        self::assertTrue(class_exists(TopicGroupingApplyService::class));
        self::assertTrue(class_exists(RefreshKeywordGroupsJob::class));
    }

    private function service(): KeywordGroupSemanticRefreshService
    {
        return new KeywordGroupSemanticRefreshService(
            new SemanticAnalyticsClient,
            new TopicGroupingInputHasher,
            new KeywordGroupCandidateLoader,
        );
    }

    private function group(string $name, string $source, bool $locked, int $representativeId): SeoKeywordGroup
    {
        return SeoKeywordGroup::query()->create([
            'site_id' => self::SITE,
            'name' => $name,
            'source' => $source,
            'representative_keyword_id' => $representativeId,
            'is_locked' => $locked,
        ]);
    }

    private function member(SeoKeywordGroup $group, int $keywordId, string $source): void
    {
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => self::SITE,
            'group_id' => $group->id,
            'keyword_id' => $keywordId,
            'source' => $source,
        ]);
    }

    private function phrase(int $id, string $phrase): void
    {
        DB::connection('omi_seo_ai')->table('keywords')->insert([
            'id' => $id,
            'phrase' => $phrase,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function groupIdForKeyword(int $keywordId): ?int
    {
        $id = SeoKeywordGroupKeyword::query()
            ->where('site_id', self::SITE)
            ->where('keyword_id', $keywordId)
            ->value('group_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<int>
     */
    private function keywordIdsForGroup(int $groupId): array
    {
        return SeoKeywordGroupKeyword::query()
            ->where('group_id', $groupId)
            ->orderBy('keyword_id')
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function analysis(array $groups): array
    {
        return [
            'analysis_id' => 'an-1',
            'status' => 'completed',
            'scope_ref' => (string) self::SITE,
            'language' => 'vi',
            'input_hash' => str_repeat('ab', 32),
            'groups' => $groups,
            'unassigned' => [],
            'diagnostics' => [
                'algorithm' => 'hybrid_semantic_lexical_v1',
                'keyword_count' => 1,
                'group_count' => count($groups),
                'unassigned_count' => 0,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function apiGroup(string $ref, string $representativeRef, string $name, array $members): array
    {
        return [
            'group_ref' => $ref,
            'representative_ref' => $representativeRef,
            'representative_text' => $name,
            'member_count' => count($members),
            'mean_similarity' => 0.9,
            'min_similarity' => 0.7,
            'cohesion' => 0.8,
            'members' => $members,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function topicSnapshot(): array
    {
        return [
            'topics' => DB::connection('omi_seo_ai')->table('seo_topics')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
            'members' => DB::connection('omi_seo_ai')->table('seo_topic_keywords')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
            'dna' => DB::connection('omi_seo_ai')->table('seo_topic_keyword_dna')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
        ];
    }

    private function assertTopicsUntouched(): void
    {
        self::assertSame('Legacy topic', DB::connection('omi_seo_ai')->table('seo_topics')->where('id', 1)->value('name'));
        self::assertNull(DB::connection('omi_seo_ai')->table('seo_topics')->where('id', 1)->value('keyword_group_id'));
        self::assertSame(1, DB::connection('omi_seo_ai')->table('seo_topic_keywords')->count());
        self::assertSame(99, (int) DB::connection('omi_seo_ai')->table('seo_topic_keywords')->value('keyword_id'));
        self::assertSame('hoc-sinh', DB::connection('omi_seo_ai')->table('seo_topic_keyword_dna')->value('value'));
    }
}
