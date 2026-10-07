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
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyPlanBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyResult;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingGroup;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingMember;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagAssignmentSource;
use Tests\TestCase;

/**
 * TASK 6 hardening: stale business state, concurrency, rollback mid-policy.
 */
final class TopicGroupingHardeningAcceptanceTest extends TestCase
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

    public function test_mcp_exclusion_change_after_preview_stales_plan_hash(): void
    {
        $topic = $this->seedTopicWithSeedMember('T1', 1);
        $proposal = $this->seedProposal('T1', 1);
        $builder = new TopicGroupingApplyPlanBuilder;
        $before = $builder->build($this->siteId, $proposal);
        $topic->mcp_excluded = true;
        $topic->save();
        $after = $builder->build($this->siteId, $proposal);
        self::assertNotSame($before->planHash, $after->planHash);
        self::assertNotSame($before->businessSnapshotHash, $after->businessSnapshotHash);
    }

    public function test_manual_tag_change_after_preview_stales_plan_hash(): void
    {
        $topic = $this->seedTopicWithSeedMember('T2', 2);
        $proposal = $this->seedProposal('T2', 2);
        $builder = new TopicGroupingApplyPlanBuilder;
        $before = $builder->build($this->siteId, $proposal);
        $tag = SeoTopicTag::query()->create([
            'site_id' => $this->siteId,
            'name' => 'NewTag',
            'slug' => 'newtag',
        ]);
        SeoTopicTagAssignment::query()->create([
            'topic_id' => $topic->id,
            'tag_id' => $tag->id,
            'source' => TopicTagAssignmentSource::MANUAL,
            'created_at' => now(),
        ]);
        $after = $builder->build($this->siteId, $proposal);
        self::assertNotSame($before->planHash, $after->planHash);
    }

    public function test_topic_lock_change_after_preview_stales_plan_hash(): void
    {
        $topic = $this->seedTopicWithSeedMember('T3', 3);
        $proposal = $this->seedProposal('T3', 3);
        $builder = new TopicGroupingApplyPlanBuilder;
        $before = $builder->build($this->siteId, $proposal);
        $topic->is_locked = true;
        $topic->save();
        $after = $builder->build($this->siteId, $proposal);
        self::assertNotSame($before->planHash, $after->planHash);
    }

    public function test_membership_change_after_preview_stales_plan_hash(): void
    {
        $this->seedTopicWithSeedMember('T4', 4);
        $proposal = $this->seedProposal('T4', 4);
        $builder = new TopicGroupingApplyPlanBuilder;
        $before = $builder->build($this->siteId, $proposal);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => SeoTopic::query()->where('name', 'T4')->value('id'),
            'keyword_id' => 44,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
            'confidence' => 0.5,
        ]);
        $after = $builder->build($this->siteId, $proposal);
        self::assertNotSame($before->planHash, $after->planHash);
    }

    public function test_second_apply_is_already_applied_without_second_persist(): void
    {
        $this->seedTopicWithSeedMember('Seed Topic', 1);
        $proposal = $this->seedProposal('Seed Topic', 1);
        $payload = $this->proposalPayload($proposal);
        $inputHash = str_repeat('1', 64);
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => $inputHash,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'proposal_payload' => $payload,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $persistCalls = 0;
        $service = new TopicGroupingApplyService(
            new TopicGroupingApplyPlanBuilder,
            new TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => $inputHash,
            static function () use (&$persistCalls): array {
                $persistCalls++;

                return [
                    'topics_after' => 1,
                    'memberships_written' => 1,
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
        $first = $service->apply((int) $run->id, $preview->plan->planHash);
        self::assertTrue($first->ok());
        $second = $service->apply((int) $run->id, $preview->plan->planHash);
        self::assertSame(TopicGroupingApplyResult::ALREADY_APPLIED, $second->status);
        self::assertSame(1, $persistCalls);
    }

    public function test_exception_after_persist_before_policy_rolls_back_marker(): void
    {
        $this->seedTopicWithSeedMember('Seed Topic', 1);
        $proposal = $this->seedProposal('Seed Topic', 1);
        $inputHash = str_repeat('2', 64);
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => $inputHash,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'proposal_payload' => $this->proposalPayload($proposal),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $forcingPlanner = new class extends \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingBusinessStatePlanner
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
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalMapper,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingIdentityMatcher,
            $forcingPlanner,
        );

        $marker = 'tx_marker_'.uniqid();
        $before = SeoTopic::query()->where('site_id', $this->siteId)->count();
        $service = new TopicGroupingApplyService(
            $builder,
            new TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => $inputHash,
            static function () use ($marker): array {
                SeoTopic::query()->create([
                    'site_id' => 4,
                    'name' => $marker,
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
        self::assertSame(TopicGroupingRunStatus::APPLY_FAILED, $run->fresh()->status);
        self::assertNotSame(TopicGroupingRunStatus::APPLIED, $run->fresh()->status);
        self::assertSame($before, SeoTopic::query()->where('site_id', $this->siteId)->count());
        self::assertNull(SeoTopic::query()->where('name', $marker)->first());
    }

    public function test_exception_before_persist_leaves_topics_untouched(): void
    {
        $topic = $this->seedTopicWithSeedMember('Keep', 9);
        $proposal = $this->seedProposal('Keep', 9);
        $inputHash = str_repeat('3', 64);
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => $inputHash,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'proposal_payload' => $this->proposalPayload($proposal),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $service = new TopicGroupingApplyService(
            new TopicGroupingApplyPlanBuilder,
            new TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => $inputHash,
            static function (): array {
                throw new \RuntimeException('simulated_before_write');
            },
        );
        $preview = $service->preview((int) $run->id);
        $applied = $service->apply((int) $run->id, $preview->plan->planHash);
        self::assertSame(TopicGroupingApplyResult::APPLY_FAILED, $applied->status);
        self::assertNotNull(SeoTopic::query()->find($topic->id));
        self::assertSame(TopicGroupingRunStatus::APPLY_FAILED, $run->fresh()->status);
    }

    private function seedTopicWithSeedMember(string $name, int $keywordId): SeoTopic
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => $name,
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => $keywordId,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);

        return $topic;
    }

    private function seedProposal(string $label, int $keywordId): TopicGroupingProposal
    {
        return new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-1', $label, [
                    new TopicGroupingMember($keywordId, 'seed phrase', 0.95, [
                        'similarity_score' => 0.95,
                        'is_seed' => true,
                        'source' => 'seed',
                    ]),
                ], null, [
                    'mean_similarity' => 0.95,
                    'min_similarity' => 0.95,
                    'cohesion' => 1.0,
                ]),
            ],
            [],
            ['low_confidence_member_count' => 0],
            'an-hardening',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalPayload(TopicGroupingProposal $proposal): array
    {
        return [
            'analysis_ref' => $proposal->analysisRef,
            'groups' => array_map(static function ($group): array {
                return [
                    'group_key' => $group->groupKey,
                    'suggested_label' => $group->suggestedLabel,
                    'existing_topic_ref' => $group->existingTopicRef,
                    'metadata' => $group->metadata,
                    'members' => array_map(static function ($member): array {
                        return [
                            'keyword_ref' => $member->keywordRef,
                            'text' => $member->text,
                            'confidence' => $member->confidence,
                            'evidence' => $member->evidence,
                        ];
                    }, $group->members),
                ];
            }, $proposal->groups),
            'unassigned' => [],
            'metadata' => $proposal->metadata,
        ];
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
        $schema->create('seo_topic_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        $schema->create('seo_topic_tag_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('source')->default('manual');
            $table->timestamp('created_at')->nullable();
            $table->primary(['topic_id', 'tag_id']);
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
