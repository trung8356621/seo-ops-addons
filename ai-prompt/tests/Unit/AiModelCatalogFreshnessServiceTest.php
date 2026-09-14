<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelCatalogFreshnessService;
use Omnichannel\Addons\AiPrompt\Services\AiModelCatalogSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderModelCatalogGateway;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Generic model catalog freshness: authority sync, LKG, single-flight, strong-stale.
 */
final class AiModelCatalogFreshnessServiceTest extends TestCase
{
    private AiModelCatalogFreshnessService $catalog;

    private AiModelRouterService $router;

    private AiModelPriorityService $priorities;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['seo_ai_models', 'api_connections', 'wp_options'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->boolean('paid_locked')->default(false);
            $table->json('paid_lock_reasons')->nullable();
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
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();
        Cache::flush();

        $this->priorities = new AiModelPriorityService();
        $registry = new ModelCapabilityRegistry();
        $this->router = new AiModelRouterService($registry);
        $this->app->instance(AiModelRouterService::class, $this->router);
        $this->catalog = new AiModelCatalogFreshnessService(
            new AiModelCatalogSettingsService(),
            new AiProviderModelCatalogGateway($this->router),
        );
        $this->app->instance(AiModelCatalogFreshnessService::class, $this->catalog);
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
    }

    public function test_authoritative_sync_deactivates_missing_keeps_history(): void
    {
        $conn = $this->deepseek(70);
        $this->seedModel($conn, 'deepseek-a');
        $this->seedModel($conn, 'deepseek-b');

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'deepseek-b', 'object' => 'model'],
                    ['id' => 'deepseek-c', 'object' => 'model'],
                ],
            ], 200),
        ]);

        $result = $this->catalog->requestRefresh($conn, 70, forced: true, blocking: true, respectForcedDebounce: false);
        $this->assertTrue($result['ok']);

        $byRaw = SeoAiModel::query()->where('api_connection_id', $conn->id)->get()->keyBy('raw_model_name');
        $this->assertSame(SeoAiModel::STATUS_INACTIVE, (string) $byRaw['deepseek-a']->status);
        $this->assertSame(SeoAiModel::STATUS_ACTIVE, (string) $byRaw['deepseek-b']->status);
        $this->assertSame(SeoAiModel::STATUS_ACTIVE, (string) $byRaw['deepseek-c']->status);
        $this->assertTrue($byRaw->has('deepseek-a'));
        $this->assertSame(
            AiModelCatalogFreshnessService::STATUS_FRESH,
            $this->catalog->catalogStatus($conn->fresh(), 70),
        );
    }

    public function test_sync_failure_preserves_last_known_good(): void
    {
        $conn = $this->deepseek(71);
        $this->seedModel($conn, 'deepseek-a');
        $this->seedModel($conn, 'deepseek-b');
        $this->markFresh($conn, Carbon::now()->subHour());

        Http::fake([
            'api.deepseek.com/*' => Http::response('timeout', 504),
        ]);

        $result = $this->catalog->requestRefresh($conn, 71, forced: true, blocking: true, respectForcedDebounce: false);
        $this->assertFalse($result['ok']);

        $byRaw = SeoAiModel::query()->where('api_connection_id', $conn->id)->get()->keyBy('raw_model_name');
        $this->assertSame(SeoAiModel::STATUS_ACTIVE, (string) $byRaw['deepseek-a']->status);
        $this->assertSame(SeoAiModel::STATUS_ACTIVE, (string) $byRaw['deepseek-b']->status);
        $status = $this->catalog->catalogStatus($conn->fresh(), 71);
        $this->assertContains($status, [
            AiModelCatalogFreshnessService::STATUS_FAILED,
            AiModelCatalogFreshnessService::STATUS_STALE,
            AiModelCatalogFreshnessService::STATUS_FRESH,
        ]);
    }

    public function test_suspicious_empty_does_not_wipe_inventory(): void
    {
        $conn = $this->deepseek(72);
        $this->seedModel($conn, 'deepseek-a');
        $this->seedModel($conn, 'deepseek-b');

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'object' => 'list',
                'data' => [],
            ], 200),
        ]);

        $result = $this->catalog->requestRefresh($conn, 72, forced: true, blocking: true, respectForcedDebounce: false);
        $this->assertFalse($result['ok']);

        $active = SeoAiModel::query()
            ->where('api_connection_id', $conn->id)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->count();
        $this->assertSame(2, $active);
    }

    public function test_stale_ttl_single_flight_one_provider_sync(): void
    {
        $conn = $this->deepseek(73);
        $this->seedModel($conn, 'deepseek-a');
        $this->markFresh($conn, Carbon::now()->subHours(10));

        $syncCalls = 0;
        $mock = \Mockery::mock($this->router)->makePartial();
        $mock->shouldReceive('syncModelsForConnection')->once()->andReturnUsing(function () use (&$syncCalls): bool {
            $syncCalls++;

            return true;
        });
        $this->app->instance(AiModelRouterService::class, $mock);
        $catalog = new AiModelCatalogFreshnessService(
            new AiModelCatalogSettingsService(),
            new AiProviderModelCatalogGateway($mock),
        );

        for ($i = 0; $i < 5; $i++) {
            $catalog->requestRefresh($conn->fresh(), 73, forced: false, blocking: true);
        }

        $this->assertSame(1, $syncCalls);
    }

    public function test_fresh_catalog_skips_provider_sync(): void
    {
        $conn = $this->deepseek(74);
        $this->seedModel($conn, 'deepseek-a');
        $this->markFresh($conn, Carbon::now()->subMinutes(10));

        $mock = \Mockery::mock($this->router)->makePartial();
        $mock->shouldReceive('syncModelsForConnection')->never();
        $catalog = new AiModelCatalogFreshnessService(
            new AiModelCatalogSettingsService(),
            new AiProviderModelCatalogGateway($mock),
        );

        $result = $catalog->requestRefresh($conn->fresh(), 74, forced: false, blocking: true);
        $this->assertTrue($result['ok']);
        $this->assertSame('already_fresh', $result['reason']);
    }

    public function test_strong_stale_triggers_debounced_forced_sync_once(): void
    {
        $conn = $this->deepseek(75);
        $this->seedModel($conn, 'deepseek-a');

        $syncCalls = 0;
        $mock = \Mockery::mock($this->router)->makePartial();
        $mock->shouldReceive('syncModelsForConnection')->once()->andReturnUsing(function () use (&$syncCalls): bool {
            $syncCalls++;

            return true;
        });
        $catalog = new AiModelCatalogFreshnessService(
            new AiModelCatalogSettingsService(),
            new AiProviderModelCatalogGateway($mock),
        );

        $decision = new AiFailureDecision(
            category: AiFailureClass::ModelNotFound,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            safeMessage: 'model_not_found',
        );

        $catalog->onStrongStaleModelError($conn->fresh(), $decision, 75);
        $catalog->onStrongStaleModelError($conn->fresh(), $decision, 75);

        $this->assertSame(1, $syncCalls);
    }

    public function test_generic_429_does_not_trigger_catalog_sync(): void
    {
        $conn = $this->deepseek(76);
        $this->seedModel($conn, 'deepseek-a');

        $mock = \Mockery::mock($this->router)->makePartial();
        $mock->shouldReceive('syncModelsForConnection')->never();
        $catalog = new AiModelCatalogFreshnessService(
            new AiModelCatalogSettingsService(),
            new AiProviderModelCatalogGateway($mock),
        );

        $decision = new AiFailureDecision(
            category: AiFailureClass::RateLimited,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            httpStatus: 429,
            safeMessage: 'rate limited',
        );

        $this->assertNull($catalog->onStrongStaleModelError($conn->fresh(), $decision, 76));
    }

    public function test_manual_sort_survives_sync_new_model_appended_not_reordered(): void
    {
        $conn = $this->deepseek(77);
        $b = $this->seedModel($conn, 'deepseek-b');
        $a = $this->seedModel($conn, 'deepseek-a');
        $this->priorities->appendToArea(77, AiModelArea::TextReasoning, [(int) $b->id, (int) $a->id]);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'deepseek-b', 'object' => 'model'],
                    ['id' => 'deepseek-a', 'object' => 'model'],
                    ['id' => 'deepseek-c', 'object' => 'model'],
                ],
            ], 200),
        ]);

        $this->assertTrue(
            $this->catalog->requestRefresh($conn, 77, forced: true, blocking: true, respectForcedDebounce: false)['ok'],
        );

        $ordered = array_map(
            static fn (SeoAiModel $m): string => (string) $m->raw_model_name,
            $this->priorities->areaEnabledModels(77, AiModelArea::TextReasoning),
        );
        $this->assertSame('deepseek-b', $ordered[0] ?? null);
        $this->assertSame('deepseek-a', $ordered[1] ?? null);
        // New model is not auto-injected into Reasoning unless coverage runs; order of B/A intact.
        $this->assertNotContains('deepseek-c', array_slice($ordered, 0, 2));
    }

    public function test_gemini_provider_success_does_not_reactivate_static_text_seed(): void
    {
        $conn = ApiConnection::query()->create([
            'user_id' => 78,
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'Gemini',
            'api_key' => 'AIza-test-key-long-enough',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $stale = $this->seedModel($conn, 'gemini-1.5-flash', AiModelCategory::GEMINI_FLASH);

        Http::fake([
            '*generativelanguage.googleapis.com/*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-flash-live',
                        'displayName' => 'Gemini Flash Live',
                        'supportedGenerationMethods' => ['generateContent'],
                    ],
                ],
            ], 200),
            '*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-flash-live',
                        'displayName' => 'Gemini Flash Live',
                        'supportedGenerationMethods' => ['generateContent'],
                    ],
                ],
            ], 200),
        ]);

        $ok = $this->router->syncGeminiModels((int) $conn->id);
        $this->assertTrue($ok);
        $this->assertSame(SeoAiModel::STATUS_INACTIVE, (string) $stale->fresh()->status);
        $this->assertSame(
            SeoAiModel::STATUS_ACTIVE,
            (string) SeoAiModel::query()
                ->where('api_connection_id', $conn->id)
                ->where('raw_model_name', 'gemini-flash-live')
                ->value('status'),
        );
    }

    public function test_metadata_merge_owns_only_model_catalog_key(): void
    {
        $conn = $this->deepseek(79);
        $conn->metadata = [
            'wallet_balance' => 9.9,
            'openrouter_free_pool_health' => ['free_pool_state' => 'healthy'],
        ];
        $conn->save();
        $this->seedModel($conn, 'deepseek-a');

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'object' => 'list',
                'data' => [['id' => 'deepseek-a', 'object' => 'model']],
            ], 200),
        ]);

        $this->catalog->requestRefresh($conn->fresh(), 79, forced: true, blocking: true, respectForcedDebounce: false);
        $meta = is_array($conn->fresh()->metadata) ? $conn->fresh()->metadata : [];
        $this->assertSame(9.9, $meta['wallet_balance'] ?? null);
        $this->assertSame('healthy', $meta['openrouter_free_pool_health']['free_pool_state'] ?? null);
        $this->assertArrayHasKey(AiModelCatalogFreshnessService::META_KEY, $meta);
    }

    private function deepseek(int $userId): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => 'DeepSeek',
            'api_key' => 'sk-deepseek-test-key-long-enough',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function seedModel(
        ApiConnection $connection,
        string $raw,
        string $category = AiModelCategory::DEEPSEEK_CHAT,
    ): SeoAiModel {
        return SeoAiModel::query()->create([
            'api_connection_id' => (int) $connection->id,
            'category' => $category,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => ['source' => 'test'],
        ]);
    }

    private function markFresh(ApiConnection $connection, Carbon $at): void
    {
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $meta[AiModelCatalogFreshnessService::META_KEY] = [
            'status' => AiModelCatalogFreshnessService::STATUS_FRESH,
            'last_success_at' => $at->toIso8601String(),
            'last_sync_at' => $at->toIso8601String(),
            'catalog_count' => 1,
        ];
        $connection->metadata = $meta;
        $connection->save();
    }
}
