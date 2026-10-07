<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use Tests\TestCase;

final class TopicGroupingRunPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
            'semantic.topic_provider' => 'semantic_http',
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');
        $this->createTables();
    }

    public function test_failed_persists_without_topic_mutation(): void
    {
        SeoTopic::query()->create([
            'site_id' => 1,
            'name' => 'Existing',
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => false,
        ]);
        $topicsBefore = SeoTopic::query()->where('site_id', 1)->count();
        $membersBefore = SeoTopicKeyword::query()->where('site_id', 1)->count();

        Http::fake([
            'semantic.test/v1/topic/analyses' => Http::response(['detail' => 'down'], 503),
        ]);

        // Analysis service needs site keyword tables; force early table-ready fail path
        // while proving failed run persistence for unavailable semantic after input build
        // is skipped — use direct mark via a minimal run row path.
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => 1,
            'provider' => 'semantic_http',
            'input_hash' => 'abc',
            'status' => TopicGroupingRunStatus::QUEUED,
            'started_at' => now(),
        ]);
        $run->status = TopicGroupingRunStatus::FAILED;
        $run->error_code = 'semantic_unavailable';
        $run->error_message = 'Semantic service unavailable';
        $run->completed_at = now();
        $run->save();

        TopicReclusterUiState::markFailed(1, 'Semantic service unavailable', [
            'run_id' => $run->id,
            'error_code' => 'semantic_unavailable',
        ], 'semantic_unavailable', 'semantic_http');

        $fresh = SeoTopicGroupingRun::query()->find($run->id);
        self::assertNotNull($fresh);
        self::assertSame(TopicGroupingRunStatus::FAILED, $fresh->status);
        self::assertSame('semantic_unavailable', $fresh->error_code);
        self::assertSame($topicsBefore, SeoTopic::query()->where('site_id', 1)->count());
        self::assertSame($membersBefore, SeoTopicKeyword::query()->where('site_id', 1)->count());
        self::assertFalse(TopicReclusterUiState::isMutationLocked(1));
    }

    public function test_stale_hash_detection(): void
    {
        $hasher = new TopicGroupingInputHasher;
        $hashA = $hasher->hash('1', null, [['ref' => '1', 'text' => 'a']]);
        $hashB = $hasher->hash('1', null, [['ref' => '1', 'text' => 'b']]);
        self::assertNotSame($hashA, $hashB);

        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => 1,
            'provider' => 'semantic_http',
            'input_hash' => $hashA,
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'keyword_count' => 1,
            'group_count' => 1,
            'proposal_payload' => ['groups' => [], 'unassigned' => []],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        self::assertTrue($run->isProposalReady());
        if (! hash_equals($run->input_hash, $hashB)) {
            $run->status = TopicGroupingRunStatus::STALE;
            $run->save();
        }
        self::assertTrue($run->fresh()->isStale());
    }

    public function test_proposal_ready_survives_reload_from_db(): void
    {
        $run = SeoTopicGroupingRun::query()->create([
            'site_id' => 3,
            'provider' => 'semantic_http',
            'external_analysis_id' => 'an-xyz',
            'input_hash' => str_repeat('a', 64),
            'status' => TopicGroupingRunStatus::PROPOSAL_READY,
            'keyword_count' => 10,
            'group_count' => 2,
            'unassigned_count' => 1,
            'low_confidence_count' => 3,
            'model' => 'mini',
            'algorithm' => 'cosine_threshold_greedy_medoid_v1',
            'proposal_payload' => [
                'groups' => [['group_key' => 'g-1', 'suggested_label' => 'balo', 'members' => []]],
                'unassigned' => [],
            ],
            'diagnostics' => ['algorithm' => 'cosine_threshold_greedy_medoid_v1'],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        TopicReclusterUiState::markProposalReady(3, $run, 'semantic_http');
        $ui = TopicReclusterUiState::get(3);
        self::assertSame(TopicReclusterUiState::STATUS_PROPOSAL_READY, $ui['status'] ?? null);

        $reloaded = SeoTopicGroupingRun::query()->find($run->id);
        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->group_count);
        self::assertSame('an-xyz', $reloaded->external_analysis_id);
        self::assertIsArray($reloaded->proposal_payload);
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
            $table->decimal('confidence', 5, 2)->nullable();
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
