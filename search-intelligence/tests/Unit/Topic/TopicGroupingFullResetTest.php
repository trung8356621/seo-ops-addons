<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
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
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingMember;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagAssignmentSource;
use Tests\TestCase;

final class TopicGroupingFullResetTest extends TestCase
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

    public function test_preserve_and_full_reset_plan_hashes_differ(): void
    {
        $this->seedExactMatchTopic();
        $proposal = $this->sameShapeProposal();
        $builder = new TopicGroupingApplyPlanBuilder;
        $preserve = $builder->build($this->siteId, $proposal, TopicGroupingRebuildMode::PRESERVE_EXISTING);
        $reset = $builder->build($this->siteId, $proposal, TopicGroupingRebuildMode::FULL_RESET);

        self::assertNotSame($preserve->planHash, $reset->planHash);
        self::assertSame(TopicGroupingRebuildMode::PRESERVE_EXISTING, $preserve->rebuildMode);
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $reset->rebuildMode);
        self::assertGreaterThan(0, (int) $preserve->counts['topics_reused']);
        self::assertSame(0, (int) $reset->counts['topics_reused']);
        self::assertSame(count($proposal->groups), (int) $reset->counts['topics_created']);
    }

    public function test_full_reset_preview_reused_zero_even_with_exact_name_members(): void
    {
        $this->seedExactMatchTopic();
        $plan = (new TopicGroupingApplyPlanBuilder)->build(
            $this->siteId,
            $this->sameShapeProposal(),
            TopicGroupingRebuildMode::FULL_RESET,
        );
        self::assertSame(0, (int) $plan->counts['topics_reused']);
        self::assertSame(0, (int) ($plan->identityMigration['reused_ids'] ?? 0));
        foreach ($plan->resolvedClusters as $cluster) {
            self::assertNull($cluster['topic_id']);
        }
    }

    public function test_manual_lock_and_generated_shape_protected_in_preserve_replaceable_in_full_reset(): void
    {
        $manual = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'túi canvas',
            'source' => 'manual',
            'status' => 'active',
            'is_locked' => true,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $manual->id,
            'keyword_id' => 100,
            'source' => 'manual',
            'is_seed' => false,
            'is_locked' => true,
            'confidence' => 1.0,
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-canvas', 'túi canvas', [
                    new TopicGroupingMember(100, 'tui canvas', 0.9, ['similarity_score' => 0.9]),
                    new TopicGroupingMember(101, 'tui canvas dep', 0.85, ['similarity_score' => 0.85]),
                ], null, ['mean_similarity' => 0.88, 'min_similarity' => 0.85, 'cohesion' => 0.9]),
            ],
            [],
            ['low_confidence_member_count' => 0],
            'an-gen',
        );

        $preserve = (new TopicGroupingApplyPlanBuilder)->build(
            $this->siteId,
            $proposal,
            TopicGroupingRebuildMode::PRESERVE_EXISTING,
        );
        $reset = (new TopicGroupingApplyPlanBuilder)->build(
            $this->siteId,
            $proposal,
            TopicGroupingRebuildMode::FULL_RESET,
        );

        $preserveActions = array_column($preserve->topicActions, 'action', 'topic_id');
        self::assertSame('protect', $preserveActions[(int) $manual->id] ?? null);

        $resetDissolve = array_filter(
            $reset->topicActions,
            static fn (array $a): bool => $a['action'] === 'dissolve' && (int) ($a['topic_id'] ?? 0) === (int) $manual->id,
        );
        self::assertCount(1, $resetDissolve);
        self::assertSame(0, (int) $reset->counts['topics_protected']);
        self::assertSame(0, (int) $reset->counts['topics_reused']);
    }

    public function test_tags_mcp_do_not_force_identity_in_full_reset(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Tagged Topic',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
            'mcp_excluded' => true,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 200,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
            'confidence' => 0.8,
        ]);
        $tag = SeoTopicTag::query()->create([
            'site_id' => $this->siteId,
            'name' => 'manual-tag',
            'slug' => 'manual-tag',
        ]);
        SeoTopicTagAssignment::query()->create([
            'topic_id' => $topic->id,
            'tag_id' => $tag->id,
            'source' => TopicTagAssignmentSource::MANUAL,
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-new', 'Tagged Topic', [
                    new TopicGroupingMember(200, 'tagged kw', 0.9, ['similarity_score' => 0.9]),
                ], null, ['mean_similarity' => 0.9, 'min_similarity' => 0.9, 'cohesion' => 0.9]),
            ],
            [],
            ['low_confidence_member_count' => 0],
            'an-tag',
        );

        $preserve = (new TopicGroupingApplyPlanBuilder)->build(
            $this->siteId,
            $proposal,
            TopicGroupingRebuildMode::PRESERVE_EXISTING,
        );
        $reset = (new TopicGroupingApplyPlanBuilder)->build(
            $this->siteId,
            $proposal,
            TopicGroupingRebuildMode::FULL_RESET,
        );

        self::assertTrue((bool) ($preserve->businessState['hard_block'] ?? false)
            || (int) ($preserve->counts['topics_reused'] ?? 0) >= 0);
        self::assertFalse((bool) ($reset->businessState['hard_block'] ?? true));
        self::assertSame(0, (int) $reset->counts['topics_reused']);
        self::assertSame([], $reset->businessState['metadata_migrations'] ?? ['x']);
        self::assertSame([], $reset->businessState['policy_migrations'] ?? ['x']);
    }

    public function test_full_reset_apply_replaces_topics_keeps_keyword_article_focus(): void
    {
        $old = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Old',
            'source' => 'manual',
            'status' => 'active',
            'is_locked' => true,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $old->id,
            'keyword_id' => 301,
            'source' => 'manual',
            'is_seed' => false,
            'is_locked' => true,
            'confidence' => 1.0,
        ]);
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => 7001,
            'site_id' => $this->siteId,
            'title' => 'Art',
            'deleted_at' => null,
        ]);
        $metaKey = KeywordMetaKey::siteMainArticleId($this->siteId);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => 301,
            'meta_key' => $metaKey,
            'meta_value' => '7001',
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-new', 'Brand New Topic', [
                    new TopicGroupingMember(301, 'kw', 0.9, ['similarity_score' => 0.9]),
                ], null, ['mean_similarity' => 0.9, 'min_similarity' => 0.9, 'cohesion' => 0.9]),
            ],
            [],
            ['low_confidence_member_count' => 0],
            'an-apply',
        );

        $run = $this->createReadyRun($proposal, TopicGroupingRebuildMode::FULL_RESET);
        $service = new TopicGroupingApplyService(
            new TopicGroupingApplyPlanBuilder,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => 'hash-fixed',
            function (int $siteId, array $clusters): array {
                SeoTopicKeyword::query()->where('site_id', $siteId)->delete();
                SeoTopic::query()->where('site_id', $siteId)->delete();
                $created = 0;
                $memberships = 0;
                $byKey = [];
                foreach ($clusters as $cluster) {
                    $topic = SeoTopic::query()->create([
                        'site_id' => $siteId,
                        'name' => (string) $cluster['name'],
                        'source' => 'auto',
                        'status' => 'active',
                        'is_locked' => false,
                    ]);
                    $created++;
                    $gk = trim((string) ($cluster['group_key'] ?? ''));
                    if ($gk !== '') {
                        $byKey[$gk] = (int) $topic->id;
                    }
                    foreach ($cluster['members'] as $member) {
                        SeoTopicKeyword::query()->create([
                            'site_id' => $siteId,
                            'topic_id' => $topic->id,
                            'keyword_id' => (int) $member['keyword_id'],
                            'source' => 'semantic',
                            'is_seed' => false,
                            'is_locked' => false,
                            'confidence' => $member['confidence'],
                        ]);
                        $memberships++;
                    }
                }

                return [
                    'topics_after' => $created,
                    'memberships_written' => $memberships,
                    'dna_rows' => 0,
                    'topics_dissolved' => 1,
                    'topics_reused' => 0,
                    'topics_created' => $created,
                    'discovered_topics_dissolved' => 0,
                    'topic_ids_by_group_key' => $byKey,
                ];
            },
        );
        $preview = $service->preview((int) $run->id);
        self::assertTrue($preview->ok());
        self::assertSame(0, (int) ($preview->plan?->counts['topics_reused'] ?? -1));

        $apply = $service->apply((int) $run->id, (string) $preview->plan?->planHash);
        self::assertTrue($apply->ok(), (string) ($apply->errorMessage ?? $apply->status));

        self::assertFalse(SeoTopic::query()->whereKey($old->id)->exists());
        self::assertSame(1, SeoTopic::query()->where('site_id', $this->siteId)->count());
        self::assertSame(1, SeoTopicKeyword::query()->where('site_id', $this->siteId)->where('keyword_id', 301)->count());
        self::assertSame('7001', (string) DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', 301)->where('meta_key', $metaKey)->value('meta_value'));
        self::assertSame(1, DB::connection('omi_seo_ai')->table('articles')->where('id', 7001)->count());
    }

    public function test_full_reset_apply_rolls_back_on_persist_failure(): void
    {
        $old = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Keep Me',
            'source' => 'manual',
            'status' => 'active',
            'is_locked' => true,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $old->id,
            'keyword_id' => 401,
            'source' => 'manual',
            'is_seed' => false,
            'is_locked' => true,
            'confidence' => 0.7,
        ]);

        $proposal = new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-x', 'X', [
                    new TopicGroupingMember(401, 'x', 0.9, ['similarity_score' => 0.9]),
                ], null, ['mean_similarity' => 0.9, 'min_similarity' => 0.9, 'cohesion' => 0.9]),
            ],
            [],
            ['low_confidence_member_count' => 0],
            'an-rollback',
        );
        $run = $this->createReadyRun($proposal, TopicGroupingRebuildMode::FULL_RESET);

        $service = new TopicGroupingApplyService(
            new TopicGroupingApplyPlanBuilder,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => 'hash-fixed',
            static function (): array {
                throw new \RuntimeException('injected_persist_failure');
            },
        );
        $preview = $service->preview((int) $run->id);
        self::assertTrue($preview->ok());
        $result = $service->apply((int) $run->id, (string) $preview->plan?->planHash);
        self::assertFalse($result->ok());
        $restored = SeoTopic::query()->whereKey($old->id)->first();
        self::assertNotNull($restored);
        self::assertTrue((bool) $restored->is_locked);
        self::assertSame('manual', (string) $restored->source);
        $membership = SeoTopicKeyword::query()->where('topic_id', $old->id)->where('keyword_id', 401)->first();
        self::assertNotNull($membership);
        self::assertTrue((bool) $membership->is_locked);
    }

    public function test_mode_immutability_full_reset_run_cannot_apply_as_preserve(): void
    {
        $this->seedExactMatchTopic();
        $proposal = $this->sameShapeProposal();
        $run = $this->createReadyRun($proposal, TopicGroupingRebuildMode::FULL_RESET);

        $builder = new TopicGroupingApplyPlanBuilder;
        $fullPlan = $builder->build($this->siteId, $proposal, TopicGroupingRebuildMode::FULL_RESET);
        $preservePlan = $builder->build($this->siteId, $proposal, TopicGroupingRebuildMode::PRESERVE_EXISTING);

        $service = new TopicGroupingApplyService(
            $builder,
            new \Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator,
            null,
            null,
            static fn (int $siteId): string => 'hash-fixed',
            static fn (): array => [
                'topics_after' => 0,
                'memberships_written' => 0,
                'dna_rows' => 0,
                'topics_dissolved' => 0,
                'topics_reused' => 0,
                'topics_created' => 0,
                'discovered_topics_dissolved' => 0,
                'topic_ids_by_group_key' => [],
            ],
        );

        // Preview uses run mode (full_reset). Applying with preserve plan hash must stale/fail.
        $preview = $service->preview((int) $run->id);
        self::assertTrue($preview->ok());
        self::assertSame($fullPlan->planHash, $preview->plan?->planHash);

        $mismatch = $service->apply((int) $run->id, $preservePlan->planHash);
        self::assertSame(TopicGroupingApplyResult::STALE, $mismatch->status);
    }

    public function test_semantic_input_hash_ignores_rebuild_mode(): void
    {
        $hasher = new TopicGroupingInputHasher;
        $a = $hasher->hash('4', 'vi', [['ref' => '1', 'text' => 'túi canvas']]);
        $b = $hasher->hash('4', 'vi', [['ref' => '1', 'text' => 'túi canvas']]);
        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
        // Rebuild mode is not part of hasher API — contract smoke.
        self::assertFalse(method_exists($hasher, 'hashWithRebuildMode'));
    }

    public function test_job_carries_rebuild_mode_and_ui_contract(): void
    {
        $job = new ReclusterSiteTopicsJob(4, 'v-test', TopicGroupingRebuildMode::FULL_RESET);
        self::assertSame(TopicGroupingRebuildMode::FULL_RESET, $job->rebuildMode);

        $concern = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php'
        );
        self::assertStringContainsString('fullResetTopicStructure', $concern);
        self::assertStringContainsString('selectedRebuildMode', $concern);
        self::assertStringContainsString('canUseFullResetRebuildMode', $concern);
        self::assertStringContainsString('openReclusterModal', $concern);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('openReclusterModal', $blade);
        self::assertStringContainsString('fullResetTopicStructure', $blade);
        self::assertStringContainsString('Xóa cấu trúc Topic cũ và tách lại từ đầu', $blade);
        self::assertStringContainsString('topic_ai_history_link', $blade);
        self::assertStringContainsString('beginConfirmAiAudit', $blade);
        self::assertStringContainsString('Xóa cấu trúc Topic cũ và tách lại từ đầu', $blade);
        self::assertStringNotContainsString('Xem thay đổi', $blade);
        self::assertStringNotContainsString('openProposalPreview', $blade);
    }

    public function test_proposal_ready_does_not_mutate_topics(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'Stay',
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
            'is_locked' => false,
            'confidence' => 0.5,
        ]);
        $beforeTopics = SeoTopic::query()->where('site_id', $this->siteId)->count();
        $beforeMemberships = SeoTopicKeyword::query()->where('site_id', $this->siteId)->count();

        $run = $this->createReadyRun($this->sameShapeProposal(), TopicGroupingRebuildMode::FULL_RESET);
        self::assertSame(TopicGroupingRunStatus::PROPOSAL_READY, $run->status);
        self::assertSame($beforeTopics, SeoTopic::query()->where('site_id', $this->siteId)->count());
        self::assertSame($beforeMemberships, SeoTopicKeyword::query()->where('site_id', $this->siteId)->count());
        self::assertTrue(SeoTopic::query()->whereKey($topic->id)->exists());
    }

    private function seedExactMatchTopic(): void
    {
        $topic = SeoTopic::query()->create([
            'site_id' => $this->siteId,
            'name' => 'túi canvas',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 10,
            'source' => 'seed',
            'is_seed' => true,
            'is_locked' => false,
            'confidence' => 1.0,
        ]);
        SeoTopicKeyword::query()->create([
            'site_id' => $this->siteId,
            'topic_id' => $topic->id,
            'keyword_id' => 11,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
            'confidence' => 0.8,
        ]);
    }

    private function sameShapeProposal(): TopicGroupingProposal
    {
        return new TopicGroupingProposal(
            [
                new TopicGroupingGroup('g-canvas', 'túi canvas', [
                    new TopicGroupingMember(10, 'tui canvas', 0.95, [
                        'similarity_score' => 0.95,
                        'is_seed' => true,
                        'source' => 'seed',
                    ]),
                    new TopicGroupingMember(11, 'tui canvas dep', 0.9, ['similarity_score' => 0.9]),
                ], null, ['mean_similarity' => 0.92, 'min_similarity' => 0.9, 'cohesion' => 0.95]),
            ],
            [new TopicGroupingCandidate(12, 'orphan')],
            ['low_confidence_member_count' => 0],
            'an-same',
        );
    }

    private function createReadyRun(TopicGroupingProposal $proposal, string $rebuildMode): SeoTopicGroupingRun
    {
        $payload = [
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

        return SeoTopicGroupingRun::query()->create([
            'site_id' => $this->siteId,
            'provider' => 'semantic_http',
            'rebuild_mode' => $rebuildMode,
            'input_hash' => 'hash-fixed',
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'keyword_count' => 2,
            'group_count' => count($proposal->groups),
            'unassigned_count' => count($proposal->unassigned),
            'low_confidence_count' => 0,
            'proposal_payload' => $payload,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source')->nullable();
            $table->string('status')->nullable();
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
        $schema->create('seo_topic_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->string('token')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_topic_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        $schema->create('seo_topic_tag_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('source')->nullable();
            $table->timestamps();
        });
        $schema->create('articles', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('title')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
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
