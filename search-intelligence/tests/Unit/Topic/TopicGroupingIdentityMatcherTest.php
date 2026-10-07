<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyPlanBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingGroup;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingIdentityMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingMember;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Tests\TestCase;

final class TopicGroupingIdentityMatcherTest extends TestCase
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
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');
        $schema = Schema::connection('omi_seo_ai');
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
            $table->decimal('confidence', 8, 4)->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'keyword_id']);
        });
    }

    public function test_one_to_one_continuity_reuses_topic_id(): void
    {
        $matcher = new TopicGroupingIdentityMatcher;
        $clusters = [[
            'name' => 'Balo du lịch',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [
                $this->member(1),
                $this->member(2),
                $this->member(3),
            ],
        ]];
        $inventory = [[
            'topic_id' => 100,
            'name' => 'Old balo',
            'member_keyword_ids' => [1, 2, 3],
            'member_count' => 3,
            'is_locked' => false,
            'has_focus' => false,
        ]];
        $result = $matcher->apply($clusters, $inventory);
        self::assertSame(100, $result['clusters'][0]['topic_id']);
        self::assertSame(1, $result['diagnostics']['reused']);
        self::assertCount(1, $result['diagnostics']['one_to_one']);
    }

    public function test_split_exactly_one_child_inherits(): void
    {
        $matcher = new TopicGroupingIdentityMatcher;
        $clusters = [
            [
                'name' => 'Child strong',
                'topic_id' => null,
                'is_locked' => false,
                'members' => [$this->member(1), $this->member(2), $this->member(3), $this->member(10)],
            ],
            [
                'name' => 'Child weak',
                'topic_id' => null,
                'is_locked' => false,
                'members' => [$this->member(4), $this->member(5), $this->member(20)],
            ],
            [
                'name' => 'Child tiny',
                'topic_id' => null,
                'is_locked' => false,
                'members' => [$this->member(5), $this->member(4), $this->member(30)],
            ],
        ];
        $inventory = [[
            'topic_id' => 200,
            'name' => 'Parent',
            'member_keyword_ids' => [1, 2, 3, 4, 5],
            'member_count' => 5,
            'is_locked' => false,
            'has_focus' => true,
        ]];
        $result = $matcher->apply($clusters, $inventory);
        $ids = array_column($result['clusters'], 'topic_id');
        self::assertSame(1, count(array_filter($ids, static fn ($id) => $id === 200)));
        self::assertSame(200, $result['clusters'][0]['topic_id']);
        self::assertNull($result['clusters'][1]['topic_id']);
        self::assertNull($result['clusters'][2]['topic_id']);
        self::assertNotEmpty($result['diagnostics']['splits']);
    }

    public function test_merge_exactly_one_old_id_survives(): void
    {
        $matcher = new TopicGroupingIdentityMatcher;
        $clusters = [[
            'name' => 'Merged',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [
                $this->member(1), $this->member(2), $this->member(3),
                $this->member(4), $this->member(5), $this->member(6),
            ],
        ]];
        $inventory = [
            [
                'topic_id' => 10,
                'name' => 'A',
                'member_keyword_ids' => [1, 2, 3],
                'member_count' => 3,
                'is_locked' => false,
                'has_focus' => false,
            ],
            [
                'topic_id' => 11,
                'name' => 'B',
                'member_keyword_ids' => [4, 5],
                'member_count' => 2,
                'is_locked' => false,
                'has_focus' => false,
            ],
            [
                'topic_id' => 12,
                'name' => 'C',
                'member_keyword_ids' => [6, 99],
                'member_count' => 2,
                'is_locked' => false,
                'has_focus' => false,
            ],
        ];
        $result = $matcher->apply($clusters, $inventory);
        self::assertSame(10, $result['clusters'][0]['topic_id']);
        self::assertSame(1, $result['diagnostics']['reused']);
        self::assertNotEmpty($result['diagnostics']['merges']);
    }

    public function test_no_duplicate_topic_reuse(): void
    {
        $matcher = new TopicGroupingIdentityMatcher;
        $clusters = [
            [
                'name' => 'G1',
                'topic_id' => null,
                'is_locked' => false,
                'members' => [$this->member(1), $this->member(2)],
            ],
            [
                'name' => 'G2',
                'topic_id' => null,
                'is_locked' => false,
                'members' => [$this->member(1), $this->member(2), $this->member(3)],
            ],
        ];
        $inventory = [[
            'topic_id' => 55,
            'name' => 'Only',
            'member_keyword_ids' => [1, 2, 3],
            'member_count' => 3,
            'is_locked' => false,
            'has_focus' => false,
        ]];
        $result = $matcher->apply($clusters, $inventory);
        $assigned = array_filter(array_column($result['clusters'], 'topic_id'));
        self::assertCount(1, $assigned);
        self::assertSame([55], array_values($assigned));
    }

    public function test_deterministic_tie_break(): void
    {
        $matcher = new TopicGroupingIdentityMatcher;
        $clusters = [[
            'name' => 'G',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [$this->member(1), $this->member(2)],
        ]];
        $inventory = [
            [
                'topic_id' => 30,
                'name' => 'B',
                'member_keyword_ids' => [1, 2],
                'member_count' => 2,
                'is_locked' => false,
                'has_focus' => false,
            ],
            [
                'topic_id' => 20,
                'name' => 'A',
                'member_keyword_ids' => [1, 2],
                'member_count' => 2,
                'is_locked' => false,
                'has_focus' => false,
            ],
        ];
        $a = $matcher->apply($clusters, $inventory);
        $b = $matcher->apply($clusters, $inventory);
        self::assertSame($a['clusters'][0]['topic_id'], $b['clusters'][0]['topic_id']);
        self::assertSame(20, $a['clusters'][0]['topic_id']);
    }

    public function test_locked_inventory_is_ignored_by_matcher(): void
    {
        $matcher = new TopicGroupingIdentityMatcher;
        $clusters = [[
            'name' => 'G',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [$this->member(1), $this->member(2)],
        ]];
        $inventory = [[
            'topic_id' => 77,
            'name' => 'Locked',
            'member_keyword_ids' => [1, 2],
            'member_count' => 2,
            'is_locked' => true,
            'has_focus' => false,
        ]];
        $result = $matcher->apply($clusters, $inventory);
        self::assertNull($result['clusters'][0]['topic_id']);
        self::assertSame(0, $result['diagnostics']['reused']);
    }

    public function test_plan_builder_includes_identity_mapping_in_hash(): void
    {
        $siteId = 9;
        SeoTopic::query()->create([
            'site_id' => $siteId,
            'name' => 'Canvas',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        $topic = SeoTopic::query()->where('site_id', $siteId)->first();
        SeoTopicKeyword::query()->create([
            'site_id' => $siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 1,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 2,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-1', 'Canvas bags', [
                    new TopicGroupingMember(1, 'a', 0.9, []),
                    new TopicGroupingMember(2, 'b', 0.9, []),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $builder = new TopicGroupingApplyPlanBuilder;
        $planA = $builder->build($siteId, $proposal);
        $planB = $builder->build($siteId, $proposal);
        self::assertSame($planA->planHash, $planB->planHash);
        self::assertArrayHasKey('identity_mapping', $planA->identityMigration);
        self::assertSame((int) $topic->id, $planA->resolvedClusters[0]['topic_id'] ?? null);
        self::assertGreaterThanOrEqual(1, (int) $planA->counts['topics_reused']);
    }

    public function test_thresholds_are_centralized_constants(): void
    {
        self::assertSame(2, TopicGroupingIdentityMatcher::MIN_INTERSECTION);
        self::assertSame(1, TopicGroupingIdentityMatcher::MIN_INTERSECTION_STRONG);
        self::assertEqualsWithDelta(0.15, TopicGroupingIdentityMatcher::MIN_JACCARD, 0.0001);
        self::assertEqualsWithDelta(0.25, TopicGroupingIdentityMatcher::MIN_EXISTING_COVERAGE, 0.0001);
        self::assertEqualsWithDelta(0.35, TopicGroupingIdentityMatcher::MIN_EXISTING_COVERAGE_STRONG, 0.0001);
    }

    /**
     * @return array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}
     */
    private function member(int $id): array
    {
        return [
            'keyword_id' => $id,
            'phrase' => 'kw-'.$id,
            'source' => 'semantic',
            'is_seed' => false,
            'confidence' => 0.8,
            'is_locked' => false,
        ];
    }
}
