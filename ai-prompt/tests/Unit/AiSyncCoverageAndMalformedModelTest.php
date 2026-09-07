<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionCoverageService;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\MalformedAiModelRepairService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\SyncAllAiConnectionModelsService;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class AiSyncCoverageAndMalformedModelTest extends TestCase
{
    private AiConnectionCoverageService $coverage;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['seo_ai_models', 'api_connections', 'users', 'ai_routing_targets', 'ai_routing_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('role')->default('owner');
            $table->timestamps();
        });
        Schema::create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('seo_ai_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id');
            $table->string('category')->nullable();
            $table->string('raw_model_name');
            $table->string('display_name');
            $table->integer('priority')->default(100);
            $table->string('status')->default('active');
            $table->boolean('is_hidden')->default(false);
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => 1,
            'email' => 'owner@example.test',
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->coverage = app(AiConnectionCoverageService::class);
    }

    public function test_deepseek_sync_reconcile_adds_reasoning_route_without_reordering(): void
    {
        $or = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OR',
            'api_key' => 'sk-openrouter-long-key',
            'status' => 'active',
            'is_global' => false,
        ]);
        $ds = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => 'DeepSeek',
            'api_key' => 'sk-deepseek-long-key',
            'status' => 'active',
            'is_global' => false,
        ]);

        $claude = SeoAiModel::query()->create([
            'api_connection_id' => $or->id,
            'category' => AiModelCategory::CLAUDE_SONNET,
            'raw_model_name' => 'anthropic/claude-sonnet-4',
            'display_name' => 'Claude',
            'priority' => 10,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'omi_areas' => [
                    AiModelArea::TextReasoning->value => ['enabled' => true, 'priority' => 1, 'source' => 'manual'],
                ],
                'resolved' => ['text.reasoning', 'text.generate'],
            ],
        ]);
        $reasoner = SeoAiModel::query()->create([
            'api_connection_id' => $ds->id,
            'category' => AiModelCategory::DEEPSEEK_REASONER,
            'raw_model_name' => 'deepseek-reasoner',
            'display_name' => 'DeepSeek Reasoner',
            'priority' => 20,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'omi_areas' => [
                    AiModelArea::TextReasoning->value => ['enabled' => false, 'priority' => 200, 'source' => 'auto'],
                ],
                'resolved' => ['text.reasoning', 'text.generate'],
                'omi_primary_type' => AiModelArea::TextReasoning->value,
            ],
        ]);

        $before = app(AiModelPriorityService::class)->areaEnabledModels(1, AiModelArea::TextReasoning);
        $this->assertCount(1, $before);
        $this->assertSame((int) $claude->id, (int) $before[0]->id);

        $added = $this->coverage->reconcileArea(1, AiModelArea::TextReasoning);
        $this->assertSame(1, $added);

        $after = app(AiModelPriorityService::class)->areaEnabledModels(1, AiModelArea::TextReasoning);
        $this->assertGreaterThanOrEqual(2, count($after));
        $this->assertSame((int) $claude->id, (int) $after[0]->id, 'manual order preserved');
        $this->assertTrue(
            collect($after)->contains(fn ($m) => (int) $m->id === (int) $reasoner->id),
        );
    }

    public function test_malformed_a_b_c_are_quarantined_not_blind_deleted(): void
    {
        $or = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OR',
            'api_key' => 'sk-openrouter-long-key',
            'status' => 'active',
            'is_global' => false,
        ]);
        foreach (['a', 'b', 'c'] as $letter) {
            SeoAiModel::query()->create([
                'api_connection_id' => $or->id,
                'category' => AiModelCategory::GEMINI_FLASH,
                'raw_model_name' => $letter,
                'display_name' => $letter,
                'priority' => 100,
                'status' => SeoAiModel::STATUS_ACTIVE,
                'capabilities' => [
                    'omi_areas' => [
                        AiModelArea::TextFast->value => ['enabled' => true, 'priority' => 1],
                    ],
                ],
            ]);
        }

        $service = new MalformedAiModelRepairService();
        $this->assertTrue(MalformedAiModelRepairService::isMalformedProviderModelId('a'));
        $this->assertFalse(MalformedAiModelRepairService::isMalformedProviderModelId('deepseek-chat'));
        $applied = $service->auditAndRepair(true);
        $this->assertCount(3, $applied);

        foreach (SeoAiModel::query()->whereIn('raw_model_name', ['a', 'b', 'c'])->get() as $model) {
            $this->assertSame(SeoAiModel::STATUS_INACTIVE, (string) $model->status);
            $areas = is_array($model->capabilities['omi_areas'] ?? null) ? $model->capabilities['omi_areas'] : [];
            $this->assertFalse((bool) ($areas[AiModelArea::TextFast->value]['enabled'] ?? true));
        }
    }

    public function test_sync_all_continues_after_one_provider_failure(): void
    {
        ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OR',
            'api_key' => 'sk-openrouter-long-key',
            'status' => 'active',
            'is_global' => false,
        ]);
        ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => 'DS',
            'api_key' => 'x', // unusable → counted failed, does not abort
            'status' => 'active',
            'is_global' => false,
        ]);

        $result = app(SyncAllAiConnectionModelsService::class)->run(1);
        $this->assertArrayHasKey('rows', $result);
        $this->assertGreaterThanOrEqual(1, $result['failed'] + $result['ok'] + $result['skipped']);
        $this->assertNotEmpty($result['summary_lines']);
    }
}
