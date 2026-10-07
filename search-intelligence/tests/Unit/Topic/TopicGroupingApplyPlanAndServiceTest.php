<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyPlanBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyResult;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingGroup;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingMember;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Tests\TestCase;

final class TopicGroupingApplyPlanAndServiceTest extends TestCase
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
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');
        $this->createTables();
    }

    public function test_plan_is_deterministic_for_identical_state(): void
    {
        $this->seedBaseTopics();
        $proposal = $this->sampleProposal();
        $builder = new TopicGroupingApplyPlanBuilder;
        $a = $builder->build($this->siteId, $proposal);
        $b = $builder->build($this->siteId, $proposal);
        self::assertSame($a->planHash, $b->planHash);
        self::assertSame($a->counts, $b->counts);
    }

    public function test_identity_reuse_and_create_and_move(): void
    {
        $existing = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Balo du lịch',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $existing->id,
            'keyword_id' => 10,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $existing->id,
            'keyword_id' => 11,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
            'confidence' => 0.5,
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-1', 'Balo du lịch', [
                    new TopicGroupingMember(10, 'balo du lich', 0.9, ['similarity_score' => 0.9, 'is_seed' => true, 'source' => 'seed']),
                    new TopicGroupingMember(12, 'balo keo', 0.8, ['similarity_score' => 0.8]),
                ], null, ['mean_similarity' => 0.85, 'min_similarity' => 0.8, 'cohesion' => 0.9]),
                new TopicGroupingGroup('g-2', 'Vali mới', [
                    new TopicGroupingMember(20, 'vali keo', 0.7, ['similarity_score' => 0.7]),
                ], null, ['mean_similarity' => 0.7, 'min_similarity' => 0.7, 'cohesion' => 0.7]),
            ],
            [new TopicGroupingCandidate(11, 'orphan phrase')],
            ['low_confidence_member_count' => 0],
            'an-test',
        );

        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        $actions = array_column($plan->topicActions, 'action', 'name');
        self::assertSame('reuse', $actions['Balo du lịch'] ?? null);
        self::assertSame('create', $actions['Vali mới'] ?? null);

        $kw = [];
        foreach ($plan->keywordActions as $row) {
            $kw[$row['keyword_id']] = $row['action'];
        }
        self::assertSame('keep', $kw[10] ?? null);
        self::assertSame('assign', $kw[12] ?? null);
        self::assertSame('assign', $kw[20] ?? null);
        self::assertSame('unassign', $kw[11] ?? null);
        self::assertSame((int) $existing->id, $plan->resolvedClusters[0]['topic_id'] ?? null);
    }

    public function test_manual_and_lock_protection_appear_in_plan(): void
    {
        $manual = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Manual Keep',
            'source' => 'manual',
            'status' => 'active',
            'is_locked' => false,
        ]);
        $locked = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Locked Keep',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => true,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $manual->id,
            'keyword_id' => 100,
            'source' => 'manual',
            'is_seed' => false,
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $locked->id,
            'keyword_id' => 101,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => true,
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-x', 'Something else', [
                    new TopicGroupingMember(200, 'new kw', 0.9, []),
                ], null, []),
            ],
            [],
            [],
            null,
        );
        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        $byId = [];
        foreach ($plan->topicActions as $action) {
            if ($action['topic_id'] !== null) {
                $byId[(int) $action['topic_id']] = $action['action'];
            }
        }
        self::assertSame('protect', $byId[(int) $manual->id] ?? null);
        self::assertSame('protect', $byId[(int) $locked->id] ?? null);
        self::assertGreaterThan(0, $plan->counts['topics_protected']);
    }

    public function test_keyword_lock_prevents_unassign_in_plan(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Has locked member',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 55,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => true,
        ]);

        $proposal = new TopicGroupingProposal([], [new TopicGroupingCandidate(55, 'locked')], [], null);
        $plan = (new TopicGroupingApplyPlanBuilder)->build($this->siteId, $proposal);
        $row = null;
        foreach ($plan->keywordActions as $action) {
            if ($action['keyword_id'] === 55) {
                $row = $action;
                break;
            }
        }
        self::assertNotNull($row);
        self::assertSame('keep', $row['action']);
        self::assertTrue($row['protected']);
    }

    public function test_apply_preview_apply_double_apply_and_stale_plan_hash(): void
    {
        $this->seedBaseTopics();
        $proposal = $this->sampleProposal();
        $payload = $this->proposalPayload($proposal);
        $inputHash = str_repeat('1', 64);
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => $inputHash,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'keyword_count' => 2,
            'group_count' => 1,
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
            static function (int $siteId, array $clusters) use (&$persistCalls): array {
                $persistCalls++;

                return [
                    'topics_after' => 1,
                    'memberships_written' => count($clusters[0]['members'] ?? []),
                    'dna_rows' => 0,
                    'topics_dissolved' => 0,
                    'topics_reused' => 1,
                    'topics_created' => 0,
                    'discovered_topics_dissolved' => 0,
                ];
            },
        );

        $preview = $service->preview((int) $run->id);
        self::assertTrue($preview->ok());
        self::assertNotNull($preview->plan);
        $planHash = $preview->plan->planHash;
        self::assertSame($planHash, $run->fresh()->plan_hash);

        $applied = $service->apply((int) $run->id, $planHash);
        self::assertTrue($applied->ok(), (string) ($applied->errorMessage ?? $applied->status));
        self::assertSame(1, $persistCalls);
        self::assertSame(TopicGroupingRunStatus::APPLIED, $run->fresh()->status);
        self::assertNotNull($run->fresh()->applied_at);

        $second = $service->apply((int) $run->id, $planHash);
        self::assertSame(TopicGroupingApplyResult::ALREADY_APPLIED, $second->status);
        self::assertSame(1, $persistCalls);

        // New proposal_ready run — business change then plan_hash mismatch
        $run2 = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => $inputHash,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'keyword_count' => 2,
            'group_count' => 1,
            'proposal_payload' => $payload,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $preview2 = $service->preview((int) $run2->id);
        self::assertTrue($preview2->ok());
        $oldHash = $preview2->plan->planHash;

        SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Post-preview topic',
            'source' => 'manual',
            'status' => 'active',
            'is_locked' => false,
        ]);

        $stale = $service->apply((int) $run2->id, $oldHash);
        self::assertSame(TopicGroupingApplyResult::STALE, $stale->status);
        self::assertSame(TopicGroupingRunStatus::STALE, $run2->fresh()->status);
        self::assertSame(1, $persistCalls);
    }

    public function test_input_hash_mismatch_marks_stale_without_mutation(): void
    {
        $before = SeoTopic::query()->where('site_id', $this->siteId)->count();
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => str_repeat('a', 64),
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'proposal_payload' => $this->proposalPayload($this->sampleProposal()),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $persistCalls = 0;
        $service = new TopicGroupingApplyService(
            new TopicGroupingApplyPlanBuilder,
            new TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => str_repeat('b', 64),
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
        $result = $service->preview((int) $run->id);
        self::assertSame(TopicGroupingApplyResult::STALE, $result->status);
        self::assertSame(TopicGroupingRunStatus::STALE, $run->fresh()->status);
        self::assertSame(0, $persistCalls);
        self::assertSame($before, SeoTopic::query()->where('site_id', $this->siteId)->count());
    }

    public function test_discard_does_not_mutate_topics(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Keep',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'input_hash' => str_repeat('c', 64),
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'proposal_payload' => ['groups' => [], 'unassigned' => []],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $service = new TopicGroupingApplyService;
        $result = $service->discard((int) $run->id);
        self::assertTrue($result->ok());
        self::assertSame(TopicGroupingRunStatus::DISCARDED, $run->fresh()->status);
        self::assertNotNull(SeoTopic::query()->find($topic->id));
    }

    public function test_hydrator_roundtrip_and_ui_gates_source(): void
    {
        $proposal = $this->sampleProposal();
        $payload = $this->proposalPayload($proposal);
        $hydrated = (new TopicGroupingProposalHydrator)->fromPayload($payload);
        self::assertCount(1, $hydrated->groups);
        self::assertSame('g-1', $hydrated->groups[0]->groupKey);

        $concern = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );
        self::assertStringContainsString('openProposalPreview', $concern);
        self::assertStringContainsString('applyProposal', $concern);
        self::assertStringContainsString('discardProposal', $concern);
        self::assertStringContainsString('canApplyProposal', $concern);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('openReclusterModal', $blade);
        self::assertStringContainsString('topic-recluster-modal-title', $blade);
        self::assertStringContainsString('Xem thay đổi', $blade);
        self::assertStringContainsString('beginConfirmApplyProposal', $blade);
        self::assertStringContainsString("'proposal_ready'", $blade);
        self::assertStringContainsString("'stale'", $blade);

        self::assertTrue(defined(TopicGroupingRunStatus::class.'::APPLY_FAILED'));
        self::assertSame('apply_failed', TopicGroupingRunStatus::APPLY_FAILED);
    }

    public function test_legacy_provider_mode_still_supported(): void
    {
        config(['semantic.topic_provider' => 'legacy', 'semantic.topic_grouping_provider' => 'legacy']);
        putenv('TOPIC_GROUPING_PROVIDER=legacy');
        self::assertTrue(TopicGroupingProviderMode::isLegacy());
        self::assertFalse(TopicGroupingProviderMode::isSemanticHttp());
    }

    private function seedBaseTopics(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Seed Topic',
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
    }

    private function sampleProposal(): TopicGroupingProposal
    {
        return new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-1', 'Seed Topic', [
                    new TopicGroupingMember(1, 'seed phrase', 0.95, [
                        'similarity_score' => 0.95,
                        'is_seed' => true,
                        'source' => 'seed',
                    ]),
                    new TopicGroupingMember(2, 'new member', 0.8, ['similarity_score' => 0.8]),
                ], null, [
                    'mean_similarity' => 0.88,
                    'min_similarity' => 0.8,
                    'cohesion' => 0.9,
                ]),
            ],
            [],
            ['low_confidence_member_count' => 0, 'algorithm' => 'cosine_average_linkage_v1'],
            'an-fixture',
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
            'unassigned' => array_map(static fn ($c): array => [
                'keyword_ref' => $c->keywordRef,
                'text' => $c->text,
            ], $proposal->unassigned),
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
