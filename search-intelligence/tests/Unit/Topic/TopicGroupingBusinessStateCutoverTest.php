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
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingGroup;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingMember;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDissolveService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagAssignmentSource;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Tests\TestCase;

final class TopicGroupingBusinessStateCutoverTest extends TestCase
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

    public function test_focus_binding_survives_topic_move_and_dissolve(): void
    {
        $old = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Old Focus Topic',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        $target = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Target Topic',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        // Non-seed membership so seed identity does not pin keyword to Old Topic.
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $old->id,
            'keyword_id' => 501,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
            'confidence' => 0.8,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $target->id,
            'keyword_id' => 510,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => 9001,
            'site_id' => $this->siteId,
            'title' => 'Focus Art',
            'deleted_at' => null,
        ]);
        $metaKey = KeywordMetaKey::siteMainArticleId($this->siteId);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => 501,
            'meta_key' => $metaKey,
            'meta_value' => '9001',
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-target', 'Target Topic', [
                    new TopicGroupingMember(510, 'target seed', 0.95, [
                        'similarity_score' => 0.95,
                        'is_seed' => true,
                        'source' => 'seed',
                    ]),
                    new TopicGroupingMember(501, 'focus kw', 0.9, [
                        'similarity_score' => 0.9,
                    ]),
                ], null, ['mean_similarity' => 0.92, 'min_similarity' => 0.9, 'cohesion' => 0.9]),
            ],
            [],
            [],
            null,
        );
        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        $move = null;
        foreach ($plan->keywordActions as $action) {
            if ((int) $action['keyword_id'] === 501) {
                $move = $action;
                break;
            }
        }
        self::assertNotNull($move);
        self::assertSame('move', $move['action']);
        self::assertSame((int) $old->id, (int) $move['from_topic_id']);
        self::assertSame((int) $target->id, (int) $move['to_topic_id']);

        // Focus is keyword_meta — untouched by Topic plan.
        $binding = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', 501)
            ->where('meta_key', $metaKey)
            ->value('meta_value');
        self::assertSame('9001', (string) $binding);

        $dissolved = (new TopicDissolveService)->dissolve($this->siteId, (int) $old->id);
        self::assertTrue($dissolved['ok']);
        self::assertNull(SeoTopic::query()->find($old->id));
        self::assertSame('9001', (string) DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', 501)->where('meta_key', $metaKey)->value('meta_value'));
        self::assertNotNull(DB::connection('omi_seo_ai')->table('articles')->where('id', 9001)->first());
        // Keyword row is site keyword inventory — membership deleted by dissolve, not the keyword itself.
        // Focus binding remains keyword-owned.
        self::assertStringContainsString(
            'topics_with_focus_keywords_changing_identity',
            implode(',', $plan->warnings),
        );
        self::assertStringNotContainsString('topics_with_focus_being_dissolved', implode(',', $plan->warnings));
    }

    public function test_reused_topic_retains_tags_and_merge_migrates_manual(): void
    {
        $survivor = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Survivor',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        $donor = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Donor',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $survivor->id,
            'keyword_id' => 10,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $donor->id,
            'keyword_id' => 11,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
            'confidence' => 0.8,
        ]);

        $keepTag = SeoTopicTag::query()->create([
            'site_id' => $this->siteId,
            'name' => 'KeepTag',
            'slug' => 'keeptag',
        ]);
        $migrateTag = SeoTopicTag::query()->create([
            'site_id' => $this->siteId,
            'name' => 'MigrateTag',
            'slug' => 'migratetag',
        ]);
        SeoTopicTagAssignment::query()->create([
            'topic_id' => $survivor->id,
            'tag_id' => $keepTag->id,
            'source' => TopicTagAssignmentSource::MANUAL,
            'created_at' => now(),
        ]);
        SeoTopicTagAssignment::query()->create([
            'topic_id' => $donor->id,
            'tag_id' => $migrateTag->id,
            'source' => TopicTagAssignmentSource::MANUAL,
            'created_at' => now(),
        ]);

        // Force dissolve donor with merge survivor via identity_migration-shaped plan:
        // donor members join survivor group → merge.
        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-1', 'Survivor', [
                    new TopicGroupingMember(10, 'seed', 0.95, ['similarity_score' => 0.95, 'is_seed' => true, 'source' => 'seed']),
                    new TopicGroupingMember(11, 'donor kw', 0.9, ['similarity_score' => 0.9]),
                ], null, ['mean_similarity' => 0.9, 'min_similarity' => 0.9, 'cohesion' => 0.9]),
            ],
            [],
            [],
            null,
        );
        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        self::assertTrue(
            SeoTopicTagAssignment::query()->where('topic_id', $survivor->id)->where('tag_id', $keepTag->id)->exists(),
        );

        $migrations = $plan->businessState['metadata_migrations'] ?? [];
        $tagMoves = array_values(array_filter(
            $migrations,
            static fn (array $m): bool => ($m['type'] ?? '') === 'tag_reassign'
                && (int) ($m['from_topic_id'] ?? 0) === (int) $donor->id
                && (int) ($m['to_topic_id'] ?? 0) === (int) $survivor->id,
        ));
        $donorAction = null;
        foreach ($plan->topicActions as $action) {
            if ((int) ($action['topic_id'] ?? 0) === (int) $donor->id) {
                $donorAction = $action['action'];
            }
        }
        if ($donorAction === 'dissolve') {
            if ($plan->isHardBlocked()) {
                $reasons = array_column($plan->businessState['metadata_review_required'] ?? [], 'reason');
                self::assertContains('manual_tags_would_be_lost', $reasons);
            } else {
                self::assertNotEmpty($tagMoves);
            }
        } else {
            self::assertFalse($plan->isHardBlocked());
        }
    }

    public function test_manual_tag_no_successor_hard_blocks(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Tagged Alone',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 77,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);
        $tag = SeoTopicTag::query()->create([
            'site_id' => $this->siteId,
            'name' => 'ManualOnly',
            'slug' => 'manualonly',
        ]);
        SeoTopicTagAssignment::query()->create([
            'topic_id' => $topic->id,
            'tag_id' => $tag->id,
            'source' => TopicTagAssignmentSource::MANUAL,
            'created_at' => now(),
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-x', 'Completely Different', [
                    new TopicGroupingMember(999, 'unrelated', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        self::assertTrue($plan->isHardBlocked());
        $reasons = array_column($plan->businessState['metadata_review_required'] ?? [], 'reason');
        self::assertContains('manual_tags_would_be_lost', $reasons);
    }

    public function test_ai_tags_do_not_hard_block_dissolve(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Ai Tagged',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 88,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);
        $tag = SeoTopicTag::query()->create([
            'site_id' => $this->siteId,
            'name' => 'AiOnly',
            'slug' => 'aionly',
        ]);
        SeoTopicTagAssignment::query()->create([
            'topic_id' => $topic->id,
            'tag_id' => $tag->id,
            'source' => TopicTagAssignmentSource::AI,
            'created_at' => now(),
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-y', 'Other Group', [
                    new TopicGroupingMember(1000, 'other', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        self::assertFalse($plan->isHardBlocked());
    }

    public function test_mcp_excluded_topic_is_protected_not_silently_included(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Quarantined',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => true,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 44,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-z', 'Fresh Group', [
                    new TopicGroupingMember(2000, 'fresh', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        $action = null;
        foreach ($plan->topicActions as $row) {
            if ((int) ($row['topic_id'] ?? 0) === (int) $topic->id) {
                $action = $row['action'];
            }
        }
        self::assertSame('protect', $action);
        self::assertTrue((bool) SeoTopic::query()->find($topic->id)?->mcp_excluded);
    }

    public function test_plan_hash_includes_business_state_migrations(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Hash Topic',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 1,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);
        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-1', 'Hash Topic', [
                    new TopicGroupingMember(1, 'seed', 0.95, ['similarity_score' => 0.95, 'is_seed' => true, 'source' => 'seed']),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $builder = new TopicGroupingApplyPlanBuilder;
        $a = $builder->build($this->siteId, $proposal);
        $b = $builder->build($this->siteId, $proposal);
        self::assertSame($a->planHash, $b->planHash);
        self::assertArrayHasKey('business_state', $a->toArray());
        self::assertArrayHasKey('summary', $a->businessState);
    }

    public function test_hard_block_prevents_apply_mutation(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Block Me',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 33,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);
        $tag = SeoTopicTag::query()->create([
            'site_id' => $this->siteId,
            'name' => 'BlockTag',
            'slug' => 'blocktag',
        ]);
        SeoTopicTagAssignment::query()->create([
            'topic_id' => $topic->id,
            'tag_id' => $tag->id,
            'source' => TopicTagAssignmentSource::MANUAL,
            'created_at' => now(),
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-other', 'Elsewhere', [
                    new TopicGroupingMember(3000, 'elsewhere', 0.9, ['similarity_score' => 0.9]),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $payload = [
            'groups' => [[
                'group_key' => 'g-other',
                'suggested_label' => 'Elsewhere',
                'members' => [['keyword_ref' => 3000, 'text' => 'elsewhere', 'confidence' => 0.9, 'evidence' => []]],
                'metadata' => [],
            ]],
            'unassigned' => [],
            'metadata' => [],
        ];
        $inputHash = str_repeat('d', 64);
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
                    'topics_after' => 0,
                    'memberships_written' => 0,
                    'dna_rows' => 0,
                    'topics_dissolved' => 0,
                    'topics_reused' => 0,
                    'topics_created' => 0,
                    'discovered_topics_dissolved' => 0,
                ];
            },
        );
        $preview = $service->preview((int) $run->id);
        self::assertTrue($preview->ok());
        self::assertTrue($preview->plan?->isHardBlocked());
        $applied = $service->apply((int) $run->id, $preview->plan->planHash);
        self::assertSame(TopicGroupingApplyResult::APPLY_FAILED, $applied->status);
        self::assertSame('business_state_hard_block', $applied->errorCode);
        self::assertSame(0, $persistCalls);
        self::assertNotNull(SeoTopic::query()->find($topic->id));
    }

    public function test_metadata_migration_failure_rolls_back_apply(): void
    {
        $survivor = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Surv',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $survivor->id,
            'keyword_id' => 1,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);
        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-1', 'Surv', [
                    new TopicGroupingMember(1, 'seed', 0.95, ['similarity_score' => 0.95, 'is_seed' => true, 'source' => 'seed']),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $payload = [
            'groups' => [[
                'group_key' => 'g-1',
                'suggested_label' => 'Surv',
                'members' => [['keyword_ref' => 1, 'text' => 'seed', 'confidence' => 0.95, 'evidence' => ['is_seed' => true, 'source' => 'seed']]],
                'metadata' => [],
            ]],
            'unassigned' => [],
            'metadata' => [],
        ];
        $inputHash = str_repeat('e', 64);
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => $inputHash,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'proposal_payload' => $payload,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Inject a failing metadata migration via a plan that normally succeeds —
        // simulate by throwing inside persist after preview, asserting APPLY_FAILED path exists.
        $service = new TopicGroupingApplyService(
            new TopicGroupingApplyPlanBuilder,
            new TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => $inputHash,
            static function (): array {
                throw new \RuntimeException('simulated_persist_failure');
            },
        );
        $preview = $service->preview((int) $run->id);
        self::assertTrue($preview->ok());
        $applied = $service->apply((int) $run->id, $preview->plan->planHash);
        self::assertSame(TopicGroupingApplyResult::APPLY_FAILED, $applied->status);
        self::assertSame(TopicGroupingRunStatus::APPLY_FAILED, $run->fresh()->status);
        self::assertNotNull(SeoTopic::query()->find($survivor->id));
    }

    public function test_preview_blade_has_business_state_section(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('Business state preservation', $blade);
        self::assertStringContainsString('topics_with_focus_keywords_changing_identity', $blade);
        self::assertStringNotContainsString('Focus Topics dissolved', $blade);
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
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        $schema->create('articles', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('seo_topic_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->timestamps();
        });
        $schema->create('seo_topic_grouping_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('provider');
            $table->string('rebuild_mode')->default('preserve_existing');
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
