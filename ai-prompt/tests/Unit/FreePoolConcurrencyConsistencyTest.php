<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiFreeModelsAreaMigrator;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\FreePoolResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolHealthService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterModelEconomics;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\FreePoolHealthState;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class FreePoolConcurrencyConsistencyTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private OpenRouterFreePoolHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        OpenRouterFreePoolHealthService::clearProbeOwners();
        Cache::flush();
        foreach (['seo_ai_models', 'api_connections', 'wp_options', 'ai_model_capabilities'] as $table) {
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
        Schema::create('ai_model_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->unsignedBigInteger('api_connection_id')->nullable();
            $table->string('model_key');
            $table->string('capability');
            $table->string('source')->default('built_in');
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        $this->priorities = new AiModelPriorityService();
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
        $this->health = new OpenRouterFreePoolHealthService(new FreePoolResilienceSettingsService());
    }

    public function test_a_probe_single_flight_exactly_one_owner(): void
    {
        $conn = $this->orConnection(40, 'OR');
        $this->setHealth($conn, [
            'free_pool_state' => FreePoolHealthState::HardLocked->value,
            'free_pool_lock_until' => Carbon::now()->subMinute()->toIso8601String(),
            'next_probe_at' => Carbon::now()->subMinute()->toIso8601String(),
        ]);

        $owners = 0;
        for ($i = 0; $i < 10; $i++) {
            OpenRouterFreePoolHealthService::clearProbeOwners();
            $svc = new OpenRouterFreePoolHealthService(new FreePoolResilienceSettingsService());
            if ($svc->tryClaimProbe($conn->fresh())) {
                $owners++;
            }
        }
        $this->assertSame(1, $owners);
        $snap = $this->health->snapshot($conn->fresh());
        $this->assertSame(FreePoolHealthState::WaitingProbe->value, $snap['free_pool_state']);
        $this->assertNotEmpty($snap['probe_owner']);
    }

    public function test_b_resync_single_flight_one_sync(): void
    {
        $conn = $this->orConnection(41, 'OR');
        $this->setHealth($conn, [
            'last_catalog_sync_at' => Carbon::now()->subHours(8)->toIso8601String(),
        ]);

        $syncCalls = 0;
        $mock = \Mockery::mock(new AiModelRouterService())->makePartial();
        $mock->shouldReceive('syncModelsForConnection')->once()->andReturnUsing(function () use (&$syncCalls): bool {
            $syncCalls++;

            return true;
        });
        $this->app->instance(AiModelRouterService::class, $mock);

        $cfg = (new FreePoolResilienceSettingsService())->defaults();
        for ($i = 0; $i < 5; $i++) {
            $svc = new OpenRouterFreePoolHealthService(new FreePoolResilienceSettingsService());
            $snap = $svc->snapshot($conn->fresh());
            $ref = new \ReflectionClass($svc);
            $method = $ref->getMethod('maybeEnqueueCatalogResync');
            $method->setAccessible(true);
            $method->invokeArgs($svc, [$conn->fresh(), &$snap, $cfg, 41, false]);
        }

        $this->assertSame(1, $syncCalls);
    }

    public function test_c_metadata_merge_preserves_unrelated_keys(): void
    {
        $conn = $this->orConnection(42, 'OR');
        $conn->metadata = [
            'wallet_balance' => 12.5,
            'openrouter_free_language_gate' => ['vi' => ['enabled' => true]],
        ];
        $conn->save();

        $this->health->lockDailyQuota($conn->fresh(), new AiFailureDecision(
            category: AiFailureClass::DailyFreeQuotaExhausted,
            scope: AiFailureScope::ConnectionFree,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            suppressConnectionFree: true,
        ));

        $fresh = $conn->fresh();
        $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
        $this->assertSame(12.5, $meta['wallet_balance'] ?? null);
        $this->assertTrue((bool) ($meta['openrouter_free_language_gate']['vi']['enabled'] ?? false));
        $this->assertSame(
            FreePoolHealthState::DailyQuotaLocked->value,
            $meta[OpenRouterFreePoolHealthService::META_KEY]['free_pool_state'] ?? null,
        );
    }

    public function test_d_migration_idempotent_no_second_reorder(): void
    {
        $or = $this->orConnection(43, 'OR');
        $ds = $this->connection(43, 'DS', ApiConnectionProviders::DEEPSEEK);
        $free = $this->model($or, 'meta/llama:free', true);
        $paid = $this->model($ds, 'deepseek-chat', false);
        $this->forceArea($free, AiModelArea::TextFast, 1);
        $this->forceArea($paid, AiModelArea::TextFast, 2);

        $migrator = new AiFreeModelsAreaMigrator($this->priorities);
        $this->assertTrue($migrator->migrateUserIfNeeded(43));
        $this->assertTrue($migrator->isMigrated(43));

        $orderAfterFirst = array_map(
            static fn (SeoAiModel $m): int => (int) $m->id,
            $this->priorities->areaEnabledModels(43, AiModelArea::FreeModels),
        );
        // Manual reorder: move free to priority 99 via append of a second free then keep.
        $free2 = $this->model($or, 'google/gemma:free', true);
        $this->priorities->appendToArea(43, AiModelArea::FreeModels, [(int) $free2->id]);
        $manual = array_map(
            static fn (SeoAiModel $m): int => (int) $m->id,
            $this->priorities->areaEnabledModels(43, AiModelArea::FreeModels),
        );

        $this->assertFalse($migrator->migrateUserIfNeeded(43));
        $afterSecond = array_map(
            static fn (SeoAiModel $m): int => (int) $m->id,
            $this->priorities->areaEnabledModels(43, AiModelArea::FreeModels),
        );
        $this->assertSame($manual, $afterSecond);
        $this->assertNotSame($orderAfterFirst, $afterSecond);
    }

    public function test_e_hard_lock_skips_before_expansion(): void
    {
        $conn = $this->orConnection(44, 'OR');
        $router = $this->model($conn, OpenRouterModelEconomics::FREE_ROUTER_ID, true);
        for ($i = 1; $i <= 5; $i++) {
            $this->model($conn, "m{$i}:free", true);
        }
        $this->setHealth($conn, [
            'free_pool_state' => FreePoolHealthState::HardLocked->value,
            'free_pool_lock_until' => Carbon::now()->addHour()->toIso8601String(),
        ]);
        $this->grant($conn, $router);
        $this->priorities->appendToArea(44, AiModelArea::FreeModels, [(int) $router->id]);

        $health = new OpenRouterFreePoolHealthService();
        $this->assertFalse($health->allowsMemberExpansion($conn->fresh()));

        $targets = new AiRoutingTargetService(new ModelCapabilityRegistry(), priorities: $this->priorities);
        $candidates = $targets->eligibleCandidates(
            44,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 44, costPolicy: AiCostPolicy::FreeOnly),
        );
        $diag = $targets->lastEligibilityDiagnostics();
        $this->assertGreaterThan(0, (int) ($diag['free_pool_anchors_blocked_before_expand'] ?? 0));
        foreach ($candidates as $c) {
            $this->assertNotSame(OpenRouterModelEconomics::FREE_ROUTER_ID, $c->model);
            // Members of hard-locked Free Router must not appear.
            $this->assertFalse(str_starts_with($c->model, 'm') && str_ends_with($c->model, ':free'));
        }
    }

    public function test_f_daily_quota_not_overwritten_by_generic_429(): void
    {
        $conn = $this->orConnection(45, 'OR');
        $this->health->lockDailyQuota($conn->fresh(), new AiFailureDecision(
            category: AiFailureClass::DailyFreeQuotaExhausted,
            scope: AiFailureScope::ConnectionFree,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            suppressConnectionFree: true,
            freeDailyResetAt: Carbon::now()->addHours(6)->toIso8601String(),
        ));
        $this->health->recordQualifyingFailure(
            45,
            $this->freeCandidate($conn->fresh(), 'x:free', 1),
            $this->rateLimitDecision(),
            10,
        );
        $snap = $this->health->snapshot($conn->fresh());
        $this->assertSame(FreePoolHealthState::DailyQuotaLocked->value, $snap['free_pool_state']);
        $this->assertSame('daily_free_quota_exhausted', $snap['free_pool_lock_reason']);
    }

    public function test_g_success_probes_setting_requires_two(): void
    {
        (new FreePoolResilienceSettingsService())->save(46, [
            FreePoolResilienceSettingsService::KEY_SUCCESS_PROBES_TO_UNLOCK => 2,
        ]);
        $conn = $this->orConnection(46, 'OR');
        $this->setHealth($conn, [
            'free_pool_state' => FreePoolHealthState::WaitingProbe->value,
            'probe_owner' => 'owner-a',
            'probe_claimed_at' => Carbon::now()->toIso8601String(),
            'probe_expires_at' => Carbon::now()->addMinutes(5)->toIso8601String(),
        ]);
        $ref = new \ReflectionClass(OpenRouterFreePoolHealthService::class);
        $prop = $ref->getProperty('probeOwners');
        $prop->setAccessible(true);
        $prop->setValue(null, [((int) $conn->id) => 'owner-a']);

        $svc = new OpenRouterFreePoolHealthService(new FreePoolResilienceSettingsService());
        $cand = $this->freeCandidate($conn->fresh(), 'p:free', 9);
        $svc->recordSuccess($cand);
        $this->assertSame(
            FreePoolHealthState::WaitingProbe->value,
            $svc->snapshot($conn->fresh())['free_pool_state'],
        );
        $svc->recordSuccess($cand);
        $this->assertSame(
            FreePoolHealthState::Healthy->value,
            $svc->snapshot($conn->fresh())['free_pool_state'],
        );
    }

    public function test_h_denominator_zero_no_ratio_lock(): void
    {
        $conn = $this->orConnection(47, 'OR');
        $this->health->recordQualifyingFailure(
            47,
            $this->freeCandidate($conn, 'z:free', 1),
            $this->rateLimitDecision(),
            0,
        );
        $snap = $this->health->snapshot($conn->fresh());
        $this->assertNotSame(FreePoolHealthState::HardLocked->value, $snap['free_pool_state']);
        $this->assertSame(FreePoolHealthState::Unavailable->value, $snap['free_pool_state']);
    }

    public function test_i_paid_and_free_mode_isolation_still_holds(): void
    {
        $or = $this->orConnection(48, 'OR');
        $ds = $this->connection(48, 'DS', ApiConnectionProviders::DEEPSEEK);
        $free = $this->model($or, 'meta/llama:free', true);
        $paid = $this->model($ds, 'deepseek-chat', false);
        $this->grant($or, $free);
        $this->grant($ds, $paid);
        $this->priorities->appendToArea(48, AiModelArea::FreeModels, [(int) $free->id]);
        $this->priorities->appendToArea(48, AiModelArea::TextFast, [(int) $paid->id]);

        $targets = new AiRoutingTargetService(new ModelCapabilityRegistry(), priorities: $this->priorities);
        $paidOnly = $targets->eligibleCandidates(
            48,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 48, itemGenerationMode: 'paid_preferred'),
        );
        foreach ($paidOnly as $c) {
            $this->assertFalse($c->isFree);
        }
        $freeOnly = $targets->eligibleCandidates(
            48,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 48, costPolicy: AiCostPolicy::FreeOnly),
        );
        foreach ($freeOnly as $c) {
            $this->assertTrue($c->isFree);
        }
    }

    public function test_i2_denominator_uses_capability_eligible_not_all_db_models(): void
    {
        $conn = $this->orConnection(50, 'OR');
        $eligible = [];
        for ($i = 1; $i <= 5; $i++) {
            $m = $this->model($conn, "ok{$i}:free", true);
            $this->grant($conn, $m);
            $this->priorities->appendToArea(50, AiModelArea::FreeModels, [(int) $m->id]);
            $eligible[] = $m;
        }
        for ($i = 1; $i <= 15; $i++) {
            // Extra DB free rows that are inactive — must not inflate denominator.
            $skip = $this->model($conn, "skip{$i}:free", true);
            $skip->status = SeoAiModel::STATUS_INACTIVE;
            $skip->save();
        }

        $runtime = new AiRuntimeHealthService();
        $ref = new \ReflectionClass($runtime);
        $method = $ref->getMethod('estimateEligibleFreePoolSize');
        $method->setAccessible(true);
        $cand = $this->freeCandidate($conn->fresh(), (string) $eligible[0]->raw_model_name, (int) $eligible[0]->id);
        $count = $method->invoke($runtime, 50, $cand);
        $this->assertSame(5, $count);
        $this->assertSame(20, SeoAiModel::query()->where('api_connection_id', $conn->id)->count());
    }

    public function test_operational_alert_includes_lock_reason(): void
    {
        $conn = $this->orConnection(49, 'OR');
        $this->setHealth($conn, [
            'free_pool_state' => FreePoolHealthState::HardLocked->value,
            'free_pool_lock_reason' => 'broad_failure_ratio_70_percent',
            'failure_ratio' => 70,
            'failed_distinct_models' => 7,
            'eligible_model_count' => 10,
            'free_pool_lock_until' => Carbon::now()->addHour()->toIso8601String(),
            'next_probe_at' => Carbon::now()->addHour()->toIso8601String(),
            'last_catalog_sync_at' => Carbon::now()->subHour()->toIso8601String(),
            'last_forced_sync_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
        ]);
        $alerts = $this->health->operationalAlerts(49);
        $this->assertNotEmpty($alerts);
        $this->assertSame('broad_failure_ratio_70_percent', $alerts[0]['lock_reason'] ?? null);
        $this->assertArrayHasKey('last_forced_sync_at', $alerts[0]);
    }

    private function setHealth(ApiConnection $connection, array $bag): void
    {
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $meta[OpenRouterFreePoolHealthService::META_KEY] = array_merge(
            $meta[OpenRouterFreePoolHealthService::META_KEY] ?? [],
            $bag,
        );
        $connection->metadata = $meta;
        $connection->save();
    }

    private function rateLimitDecision(): AiFailureDecision
    {
        return new AiFailureDecision(
            category: AiFailureClass::RateLimited,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            httpStatus: 429,
            applyCooldown: true,
            affectsRuntimeHealth: true,
        );
    }

    private function freeCandidate(ApiConnection $connection, string $model, int $seoId): RoutedAiCandidate
    {
        return new RoutedAiCandidate(
            profile: 'text.fast',
            connection: $connection,
            provider: ApiConnectionProviders::OPENROUTER,
            model: $model,
            capabilities: [],
            priority: 1,
            seoAiModelId: $seoId,
            isFree: true,
        );
    }

    private function orConnection(int $userId, string $name): ApiConnection
    {
        return $this->connection($userId, $name, ApiConnectionProviders::OPENROUTER);
    }

    private function connection(int $userId, string $name, string $provider): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'test-key-'.$userId.'-'.$name,
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function model(ApiConnection $connection, string $raw, bool $free): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'category' => AiModelCategory::GEMINI_FLASH,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'provider_metadata' => [
                    'pricing' => $free
                        ? ['prompt' => '0', 'completion' => '0']
                        : ['prompt' => '0.1', 'completion' => '0.2'],
                    'architecture' => ['modality' => 'text->text'],
                ],
            ],
        ]);
    }

    private function grant(ApiConnection $connection, SeoAiModel $model): void
    {
        AiModelCapabilityRow::query()->create([
            'api_connection_id' => $connection->id,
            'seo_ai_model_id' => $model->id,
            'model_key' => $model->raw_model_name,
            'capability' => AiModelCapability::TextGenerate->value,
            'enabled' => true,
        ]);
    }

    private function forceArea(SeoAiModel $model, AiModelArea $area, int $priority): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        $areas[$area->value] = [
            'enabled' => true,
            'priority' => $priority,
            'source' => AiModelArea::SOURCE_MANUAL,
        ];
        $caps[AiModelPriorityService::AREAS_KEY] = $areas;
        $model->capabilities = $caps;
        $model->save();
        $this->priorities->forgetMemo();
    }
}
