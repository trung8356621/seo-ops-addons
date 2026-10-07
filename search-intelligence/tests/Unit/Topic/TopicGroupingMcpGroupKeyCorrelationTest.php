<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyPlanBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyResult;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingBusinessStatePlanner;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingGroup;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingMember;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalMapper;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Tests\TestCase;

final class TopicGroupingMcpGroupKeyCorrelationTest extends TestCase
{
    private int $siteId = 4;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'semantic.topic_provider' => 'semantic_http',
        ]);
        DB::purge('omi_seo_ai');
        $this->createTables();
    }

    public function test_mapper_preserves_group_key_and_persist_returns_mapping(): void
    {
        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-dup-a', 'Same Label', [
                    new TopicGroupingMember(1, 'a', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
                new TopicGroupingGroup('g-dup-b', 'Same Label', [
                    new TopicGroupingMember(2, 'b', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $clusters = (new TopicGroupingProposalMapper)->toReclusterClusters($proposal);
        self::assertSame('g-dup-a', $clusters[0]['group_key']);
        self::assertSame('g-dup-b', $clusters[1]['group_key']);
        self::assertSame('Same Label', $clusters[0]['name']);
        self::assertSame('Same Label', $clusters[1]['name']);

        $written = $this->persistClusters($clusters);
        $map = $written['topic_ids_by_group_key'] ?? [];
        self::assertArrayHasKey('g-dup-a', $map);
        self::assertArrayHasKey('g-dup-b', $map);
        self::assertNotSame($map['g-dup-a'], $map['g-dup-b']);

        $topicA = SeoTopic::query()->find($map['g-dup-a']);
        $topicB = SeoTopic::query()->find($map['g-dup-b']);
        self::assertSame('Same Label', (string) $topicA?->name);
        self::assertSame('Same Label', (string) $topicB?->name);
    }

    public function test_mcp_policy_targets_intended_group_not_unrelated_same_name(): void
    {
        $unrelated = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Shared Name',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $unrelated->id,
            'keyword_id' => 900,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);

        $clusters = [
            [
                'group_key' => 'g-new-target',
                'name' => 'Shared Name',
                'topic_id' => null,
                'is_locked' => false,
                'members' => [[
                    'keyword_id' => 10,
                    'phrase' => 'new',
                    'source' => 'auto',
                    'is_seed' => false,
                    'confidence' => 0.9,
                    'is_locked' => false,
                ]],
            ],
            [
                'group_key' => 'g-keep-unrelated',
                'name' => 'Shared Name',
                'topic_id' => (int) $unrelated->id,
                'is_locked' => false,
                'members' => [[
                    'keyword_id' => 900,
                    'phrase' => 'seed',
                    'source' => 'seed',
                    'is_seed' => true,
                    'confidence' => 1.0,
                    'is_locked' => false,
                ]],
            ],
        ];
        $written = $this->persistClusters($clusters);
        $map = $written['topic_ids_by_group_key'];
        self::assertSame((int) $unrelated->id, (int) $map['g-keep-unrelated']);
        self::assertNotSame((int) $unrelated->id, (int) $map['g-new-target']);

        $policy = [
            'type' => 'mcp_exclude_group',
            'group_key' => 'g-new-target',
            'from_topic_id' => 1,
            'group_name' => 'Shared Name',
        ];
        $patched = new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyPlan(
            'hash',
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            'snap',
            [],
            [
                'policy_migrations' => [$policy],
                'hard_block' => false,
                'metadata_migrations' => [],
                'metadata_review_required' => [],
                'summary' => [],
            ],
        );

        $service = new TopicGroupingApplyService;
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('executePolicyMigrations');
        $method->setAccessible(true);
        $method->invoke($service, $this->siteId, $patched, $map);

        self::assertTrue((bool) SeoTopic::query()->find($map['g-new-target'])?->mcp_excluded);
        self::assertFalse((bool) SeoTopic::query()->find($unrelated->id)?->mcp_excluded);
    }

    public function test_split_mcp_uses_group_key_not_name(): void
    {
        $old = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Parent',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => true,
        ]);
        // Unrelated same-name Topic must not be touched.
        $decoy = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Child B',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $decoy->id,
            'keyword_id' => 50,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);

        $identityMigration = [
            'splits' => [[
                'topic_id' => (int) $old->id,
                'topic_name' => 'Parent',
                'retained_group' => 'Child A',
                'retained_group_key' => 'g-child-a',
                'other_groups' => [
                    ['group_key' => 'g-child-b', 'group_name' => 'Child B', 'identity' => 'new'],
                ],
            ]],
            'merges' => [],
            'topics_with_focus_keywords_changing_identity' => 0,
        ];
        $topicActions = [
            ['topic_id' => (int) $old->id, 'name' => 'Parent', 'action' => 'reuse'],
            ['topic_id' => null, 'name' => 'Child B', 'action' => 'create'],
            ['topic_id' => (int) $decoy->id, 'name' => 'Child B', 'action' => 'reuse'],
        ];
        $planned = (new TopicGroupingBusinessStatePlanner)->plan($this->siteId, $topicActions, $identityMigration);
        $policies = $planned['policy_migrations'];
        self::assertNotEmpty($policies);
        foreach ($policies as $row) {
            self::assertSame('mcp_exclude_group', $row['type']);
            self::assertSame('g-child-b', $row['group_key']);
            self::assertArrayNotHasKey('orderByDesc', $row);
        }

        $map = [
            'g-child-a' => (int) $old->id,
            'g-child-b' => 7777,
        ];
        // Create the intended child topic id 7777 via direct insert for exclusion target.
        $child = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Child B',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => false,
        ]);
        $map['g-child-b'] = (int) $child->id;

        $plan = new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyPlan(
            'hash',
            [],
            $topicActions,
            [],
            [],
            [],
            [],
            [],
            'snap',
            $identityMigration,
            $planned,
        );
        $service = new TopicGroupingApplyService;
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('executePolicyMigrations');
        $method->setAccessible(true);
        $method->invoke($service, $this->siteId, $plan, $map);

        self::assertTrue((bool) $old->fresh()->mcp_excluded);
        self::assertTrue((bool) $child->fresh()->mcp_excluded);
        self::assertFalse((bool) $decoy->fresh()->mcp_excluded);
    }

    public function test_missing_group_key_correlation_rolls_back_apply(): void
    {
        $inputHash = str_repeat('9', 64);
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => $inputHash,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'proposal_payload' => [
                'groups' => [[
                    'group_key' => 'g-1',
                    'suggested_label' => 'Only',
                    'members' => [['keyword_ref' => 1, 'text' => 'only', 'confidence' => 0.9, 'evidence' => []]],
                    'metadata' => [],
                ]],
                'unassigned' => [],
                'metadata' => [],
            ],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Only',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $forcingPlanner = new class extends TopicGroupingBusinessStatePlanner
        {
            public function plan(int $siteId, array $topicActions, array $identityMigration): array
            {
                $base = parent::plan($siteId, $topicActions, $identityMigration);
                $base['hard_block'] = false;
                $base['metadata_review_required'] = [];
                $base['policy_migrations'] = [[
                    'type' => 'mcp_exclude_group',
                    'group_key' => 'g-missing',
                    'from_topic_id' => 1,
                ]];

                return $base;
            }
        };
        $builder = new TopicGroupingApplyPlanBuilder(
            new TopicGroupingProposalMapper,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingIdentityMatcher,
            $forcingPlanner,
        );

        $markerName = 'pre_apply_marker_'.uniqid();
        $before = SeoTopic::query()->where('site_id', $this->siteId)->count();
        $service = new TopicGroupingApplyService(
            $builder,
            new TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => $inputHash,
            static function () use ($markerName): array {
                SeoTopic::query()->create([
                    'site_id' => 4,
                    'name' => $markerName,
                    'source' => 'auto',
                    'status' => 'active',
                    'is_locked' => false,
                ]);

                return [
                    'topics_after' => 2,
                    'memberships_written' => 0,
                    'dna_rows' => 0,
                    'topics_dissolved' => 0,
                    'topics_reused' => 1,
                    'topics_created' => 0,
                    'discovered_topics_dissolved' => 0,
                    'topic_ids_by_group_key' => ['g-1' => 1],
                ];
            },
        );
        $preview = $service->preview((int) $run->id);
        self::assertTrue($preview->ok());
        $applied = $service->apply((int) $run->id, $preview->plan->planHash);
        self::assertSame(TopicGroupingApplyResult::APPLY_FAILED, $applied->status);
        self::assertStringContainsString('mcp_policy_group_key_unresolved', (string) $applied->errorMessage);
        self::assertSame(TopicGroupingRunStatus::APPLY_FAILED, $run->fresh()->status);
        // Persist marker must roll back with the failed Apply transaction.
        self::assertSame($before, SeoTopic::query()->where('site_id', $this->siteId)->count());
        self::assertNull(SeoTopic::query()->where('name', $markerName)->first());
    }

    public function test_plan_hash_changes_when_group_key_target_changes(): void
    {
        $old = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Old',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => true,
        ]);
        $baseActions = [
            ['topic_id' => (int) $old->id, 'name' => 'Old', 'action' => 'reuse'],
        ];
        $planner = new TopicGroupingBusinessStatePlanner;

        $a = $planner->plan($this->siteId, $baseActions, [
            'splits' => [[
                'topic_id' => (int) $old->id,
                'other_groups' => [['group_key' => 'g-a', 'group_name' => 'X']],
            ]],
            'merges' => [],
        ]);
        $b = $planner->plan($this->siteId, $baseActions, [
            'splits' => [[
                'topic_id' => (int) $old->id,
                'other_groups' => [['group_key' => 'g-b', 'group_name' => 'X']],
            ]],
            'merges' => [],
        ]);
        self::assertSame('g-a', $a['policy_migrations'][0]['group_key'] ?? null);
        self::assertSame('g-b', $b['policy_migrations'][0]['group_key'] ?? null);
        self::assertNotSame(
            json_encode($a['policy_migrations']),
            json_encode($b['policy_migrations']),
        );

        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $old->id,
            'keyword_id' => 1,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);
        $proposalA = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-a', 'Old', [
                    new TopicGroupingMember(1, 'seed', 0.95, ['similarity_score' => 0.95, 'is_seed' => true, 'source' => 'seed']),
                ], null, []),
                new TopicGroupingGroup('g-sibling-a', 'Sibling', [
                    new TopicGroupingMember(2, 'sib', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $proposalB = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-a', 'Old', [
                    new TopicGroupingMember(1, 'seed', 0.95, ['similarity_score' => 0.95, 'is_seed' => true, 'source' => 'seed']),
                ], null, []),
                new TopicGroupingGroup('g-sibling-b', 'Sibling', [
                    new TopicGroupingMember(2, 'sib', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        // Force identical membership plans but different policy group_keys via planner injection.
        $builderA = new TopicGroupingApplyPlanBuilder(
            new TopicGroupingProposalMapper,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingIdentityMatcher,
            new class((int) $old->id, 'g-sibling-a') extends TopicGroupingBusinessStatePlanner
            {
                public function __construct(private int $tid, private string $gk) {}

                public function plan(int $siteId, array $topicActions, array $identityMigration): array
                {
                    $base = parent::plan($siteId, $topicActions, $identityMigration);
                    $base['hard_block'] = false;
                    $base['metadata_review_required'] = [];
                    $base['policy_migrations'] = [[
                        'type' => 'mcp_exclude_group',
                        'group_key' => $this->gk,
                        'from_topic_id' => $this->tid,
                    ]];

                    return $base;
                }
            },
        );
        $builderB = new TopicGroupingApplyPlanBuilder(
            new TopicGroupingProposalMapper,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingIdentityMatcher,
            new class((int) $old->id, 'g-sibling-b') extends TopicGroupingBusinessStatePlanner
            {
                public function __construct(private int $tid, private string $gk) {}

                public function plan(int $siteId, array $topicActions, array $identityMigration): array
                {
                    $base = parent::plan($siteId, $topicActions, $identityMigration);
                    $base['hard_block'] = false;
                    $base['metadata_review_required'] = [];
                    $base['policy_migrations'] = [[
                        'type' => 'mcp_exclude_group',
                        'group_key' => $this->gk,
                        'from_topic_id' => $this->tid,
                    ]];

                    return $base;
                }
            },
        );
        $planA = $builderA->build($this->siteId, $proposalA);
        $planB = $builderB->build($this->siteId, $proposalB);
        self::assertNotSame($planA->planHash, $planB->planHash);

        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/Grouping/TopicGroupingApplyService.php'
        );
        self::assertStringNotContainsString("where('name'", $src);
        self::assertStringNotContainsString('mcp_exclude_by_group_name', $src);
        self::assertStringContainsString('mcp_exclude_group', $src);
        self::assertStringContainsString('topic_ids_by_group_key', $src);
    }

    /**
     * @param  list<array<string, mixed>>  $clusters
     * @return array<string, mixed>
     */
    private function persistClusters(array $clusters): array
    {
        $class = new \ReflectionClass(TopicReclusterService::class);
        $svc = $class->newInstanceWithoutConstructor();
        $dnaProp = $class->getProperty('dna');
        $dnaProp->setAccessible(true);
        // DNA table intentionally absent → rebuildForTopic no-ops; mapping still returned.
        $dnaProp->setValue($svc, (new \ReflectionClass(TopicDnaService::class))->newInstanceWithoutConstructor());

        $locked = [
            'locked_topic_ids' => [],
            'preserved_topic_ids' => [],
            'locked_keyword_ids' => [],
            'locked_memberships_by_topic' => [],
            'topics' => [],
        ];
        $ref = $class->getMethod('persistClusters');
        $ref->setAccessible(true);

        return $ref->invoke($svc, $this->siteId, $clusters, $locked, [], []);
    }

    private function createTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_locked')->default(false);
            $table->boolean('mcp_excluded')->default(false);
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
        $schema->create('seo_topic_tag_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('source')->default('manual');
            $table->timestamp('created_at')->nullable();
            $table->primary(['topic_id', 'tag_id']);
        });
        $schema->create('seo_topic_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        $schema->create('seo_topic_grouping_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('provider');
            $table->string('external_analysis_id')->nullable();
            $table->string('input_hash');
            $table->string('plan_hash')->nullable();
            $table->string('status');
            $table->unsignedInteger('keyword_count')->default(0);
            $table->unsignedInteger('group_count')->default(0);
            $table->unsignedInteger('unassigned_count')->default(0);
            $table->unsignedInteger('low_confidence_count')->default(0);
            $table->string('model')->nullable();
            $table->string('model_version')->nullable();
            $table->string('algorithm')->nullable();
            $table->json('proposal_payload')->nullable();
            $table->json('apply_plan_payload')->nullable();
            $table->json('diagnostics')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('apply_error_code')->nullable();
            $table->text('apply_error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }
}
