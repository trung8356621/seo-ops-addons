<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiFreeModelsAreaMigrator;
use Omnichannel\Addons\AiPrompt\Services\AiModelCatalogFreshnessService;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\FreePoolResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolHealthService;
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

/**
 * Free/Paid area isolation + OpenRouter Free Pool circuit breaker contracts.
 */
final class FreePaidRoutingAndFreePoolHealthTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private OpenRouterFreePoolHealthService $poolHealth;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->poolHealth = new OpenRouterFreePoolHealthService(new FreePoolResilienceSettingsService());
    }

    public function test_free_models_tab_and_migration_preserves_paid_order(): void
    {
        $or = $this->connection(1, 'A');
        $ds = $this->connection(1, 'B', ApiConnectionProviders::DEEPSEEK);
        $free = $this->model($or, 'meta/llama:free', true);
        $paid1 = $this->model($ds, 'deepseek-chat', false);
        $paid2 = $this->model($ds, 'deepseek-reasoner', false);

        // Seed legacy mixed area via capabilities (bypasses new cost-isolation append guard).
        $this->forceAreaEnabled($free, AiModelArea::TextFast, 1);
        $this->forceAreaEnabled($paid1, AiModelArea::TextFast, 2);
        $this->forceAreaEnabled($paid2, AiModelArea::TextFast, 3);
        $this->priorities->forgetMemo();

        (new AiFreeModelsAreaMigrator($this->priorities))->migrateUserIfNeeded(1);

        $fastIds = array_map(static fn (SeoAiModel $m): int => (int) $m->id, $this->priorities->areaEnabledModels(1, AiModelArea::TextFast));
        $this->assertSame([(int) $paid1->id, (int) $paid2->id], $fastIds);

        $freeIds = array_map(static fn (SeoAiModel $m): int => (int) $m->id, $this->priorities->areaEnabledModels(1, AiModelArea::FreeModels));
        $this->assertContains((int) $free->id, $freeIds);

        $this->priorities->appendToArea(1, AiModelArea::TextFast, [(int) $free->id]);
        $still = array_map(static fn (SeoAiModel $m): int => (int) $m->id, $this->priorities->areaEnabledModels(1, AiModelArea::TextFast));
        $this->assertNotContains((int) $free->id, $still);

        $lateFree = $this->model($or, 'google/gemma-late:free', true);
        $this->grant($or, $lateFree);
        $this->priorities->appendToArea(1, AiModelArea::TextFast, [(int) $lateFree->id]);
        $paidAfterAppend = array_map(static fn (SeoAiModel $m): int => (int) $m->id, $this->priorities->areaEnabledModels(1, AiModelArea::TextFast));
        $freeAfterAppend = array_map(static fn (SeoAiModel $m): int => (int) $m->id, $this->priorities->areaEnabledModels(1, AiModelArea::FreeModels));
        $this->assertNotContains((int) $lateFree->id, $paidAfterAppend);
        $this->assertContains((int) $lateFree->id, $freeAfterAppend);
    }

    public function test_paid_mode_never_includes_free_pool(): void
    {
        $this->seedFreeAndPaid(2);
        $targets = new AiRoutingTargetService(new ModelCapabilityRegistry(), priorities: $this->priorities);
        $paid = $targets->paidAreaCandidates(
            2,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 2, itemGenerationMode: 'paid_preferred'),
        );
        $merged = $targets->eligibleCandidates(
            2,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 2, itemGenerationMode: 'paid_preferred'),
        );
        foreach ($merged as $c) {
            $this->assertFalse($c->isFree, $c->model);
        }
        $this->assertSame(
            array_map(static fn (RoutedAiCandidate $c): string => $c->model, $paid),
            array_map(static fn (RoutedAiCandidate $c): string => $c->model, $merged),
        );
    }

    public function test_free_mode_never_includes_paid(): void
    {
        $this->seedFreeAndPaid(3);
        $targets = new AiRoutingTargetService(new ModelCapabilityRegistry(), priorities: $this->priorities);
        $free = $targets->freeModelsCandidates(
            3,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 3, costPolicy: AiCostPolicy::FreeOnly),
        );
        $merged = $targets->eligibleCandidates(
            3,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 3, costPolicy: AiCostPolicy::FreeOnly),
        );
        foreach ($merged as $c) {
            $this->assertTrue($c->isFree, $c->model);
        }
        // Paid models remain configured but FreeOnly must not call them.
        $paid = $targets->paidAreaCandidates(
            3,
            AiExecutionProfile::TextFast,
            new AiRoutingContext(userId: 3),
        );
        $paidModels = array_map(static fn (RoutedAiCandidate $c): string => $c->model, $paid);
        foreach ($merged as $c) {
            $this->assertNotContains($c->model, $paidModels);
        }
        unset($free);
    }

    public function test_hard_lock_at_70_percent_with_min_sample(): void
    {
        $conn = $this->connection(10, 'OR-A');
        $this->markCatalogFresh($conn);
        for ($i = 1; $i <= 7; $i++) {
            $this->poolHealth->recordQualifyingFailure(
                10,
                $this->freeCandidate($conn->fresh(), 'model-'.$i.':free', $i),
                $this->rateLimitDecision(),
                10,
            );
        }
        $snap = $this->poolHealth->snapshot($conn->fresh());
        $this->assertSame(FreePoolHealthState::HardLocked->value, $snap['free_pool_state']);
        $this->assertSame(7, (int) $snap['failed_distinct_models']);
        $this->assertSame(OpenRouterFreePoolHealthService::SKIP_HARD_LOCKED, $this->poolHealth->skipReasonForCandidate(
            $this->freeCandidate($conn->fresh(), 'model-8:free', 8),
        ));
    }

    public function test_below_threshold_does_not_hard_lock(): void
    {
        $conn = $this->connection(11, 'OR-B');
        for ($i = 1; $i <= 6; $i++) {
            $this->poolHealth->recordQualifyingFailure(
                11,
                $this->freeCandidate($conn, 'm'.$i.':free', $i),
                $this->rateLimitDecision(),
                10,
            );
        }
        $snap = $this->poolHealth->snapshot($conn->fresh());
        $this->assertNotSame(FreePoolHealthState::HardLocked->value, $snap['free_pool_state']);
        $this->assertSame(FreePoolHealthState::Degraded->value, $snap['free_pool_state']);
    }

    public function test_min_sample_blocks_ratio_lock(): void
    {
        $conn = $this->connection(12, 'OR-C');
        for ($i = 1; $i <= 2; $i++) {
            $this->poolHealth->recordQualifyingFailure(
                12,
                $this->freeCandidate($conn, 'x'.$i.':free', $i),
                $this->rateLimitDecision(),
                20,
            );
        }
        $snap = $this->poolHealth->snapshot($conn->fresh());
        $this->assertNotSame(FreePoolHealthState::HardLocked->value, $snap['free_pool_state']);
    }

    public function test_daily_quota_locks_immediately_without_ratio(): void
    {
        $conn = $this->connection(13, 'OR-D');
        $this->poolHealth->recordQualifyingFailure(
            13,
            $this->freeCandidate($conn, 'any:free', 1),
            new AiFailureDecision(
                category: AiFailureClass::DailyFreeQuotaExhausted,
                scope: AiFailureScope::ConnectionFree,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                suppressConnectionFree: true,
                affectsRuntimeHealth: true,
                limitSource: 'openrouter_free_tier_daily',
            ),
            10,
        );
        $snap = $this->poolHealth->snapshot($conn->fresh());
        $this->assertSame(FreePoolHealthState::DailyQuotaLocked->value, $snap['free_pool_state']);
        $this->assertNull($snap['last_catalog_sync_at'] ?? null);
    }

    public function test_generic_429_cools_model_not_catalog_resync(): void
    {
        $conn = $this->connection(14, 'OR-E');
        $candidate = $this->freeCandidate($conn, 'one:free', 1);
        $this->poolHealth->recordQualifyingFailure(14, $candidate, $this->rateLimitDecision(), 10);
        $snap = $this->poolHealth->snapshot($conn->fresh());
        $this->assertNotSame(FreePoolHealthState::HardLocked->value, $snap['free_pool_state']);
        $this->assertNotSame(FreePoolHealthState::Resyncing->value, $snap['free_pool_state']);
        $this->assertSame('free_model_cooldown', $this->poolHealth->skipReasonForCandidate($candidate));
        $this->assertNull($snap['last_forced_sync_at'] ?? null);
    }

    public function test_catalog_ttl_enqueues_resync_only_when_stale(): void
    {
        $conn = $this->connection(15, 'OR-F');
        $conn->metadata = [
            OpenRouterFreePoolHealthService::META_KEY => [
                'last_catalog_sync_at' => Carbon::now()->subHours(8)->toIso8601String(),
            ],
        ];
        $conn->save();

        for ($i = 1; $i <= 7; $i++) {
            $this->poolHealth->recordQualifyingFailure(
                15,
                $this->freeCandidate($conn->fresh(), 'stale'.$i.':free', $i),
                $this->rateLimitDecision(),
                10,
            );
        }
        $snap = $this->poolHealth->snapshot($conn->fresh());
        $this->assertTrue(
            isset($snap['catalog_resync_requested_at']) || isset($snap['last_forced_sync_at'])
            || ($snap['free_pool_state'] ?? '') === FreePoolHealthState::Resyncing->value
            || ($snap['free_pool_state'] ?? '') === FreePoolHealthState::WaitingProbe->value,
        );

        $fresh = $this->connection(16, 'OR-G');
        $this->markCatalogFresh($fresh);
        for ($i = 1; $i <= 7; $i++) {
            $this->poolHealth->recordQualifyingFailure(
                16,
                $this->freeCandidate($fresh->fresh(), 'fresh'.$i.':free', $i),
                $this->rateLimitDecision(),
                10,
            );
        }
        $snapFresh = $this->poolHealth->snapshot($fresh->fresh());
        $this->assertSame(FreePoolHealthState::HardLocked->value, $snapFresh['free_pool_state']);
        $this->assertNull($snapFresh['catalog_resync_requested_at'] ?? null);
    }

    public function test_probe_backoff_after_waiting_probe_failure(): void
    {
        $conn = $this->connection(17, 'OR-H');
        $meta = [
            OpenRouterFreePoolHealthService::META_KEY => [
                'free_pool_state' => FreePoolHealthState::HardLocked->value,
                'free_pool_lock_until' => Carbon::now()->subMinute()->toIso8601String(),
                'next_probe_at' => Carbon::now()->subMinute()->toIso8601String(),
            ],
        ];
        $conn->metadata = $meta;
        $conn->save();

        $this->assertTrue($this->poolHealth->tryClaimProbe($conn->fresh()));
        $this->poolHealth->recordQualifyingFailure(
            17,
            $this->freeCandidate($conn->fresh(), 'probe:free', 1),
            $this->rateLimitDecision(),
            10,
        );
        $snap = $this->poolHealth->snapshot($conn->fresh());
        $this->assertSame(FreePoolHealthState::HardLocked->value, $snap['free_pool_state']);
        $this->assertSame(1, (int) $snap['consecutive_probe_failures']);
        $this->assertNotNull($snap['next_probe_at']);
    }

    public function test_multi_connection_isolation(): void
    {
        $a = $this->connection(18, 'Key-A');
        $b = $this->connection(18, 'Key-B');
        $this->markCatalogFresh($a);
        $this->markCatalogFresh($b);
        for ($i = 1; $i <= 7; $i++) {
            $this->poolHealth->recordQualifyingFailure(
                18,
                $this->freeCandidate($a->fresh(), 'a-model-'.$i.':free', $i),
                $this->rateLimitDecision(),
                10,
            );
        }
        $this->assertSame(
            FreePoolHealthState::HardLocked->value,
            $this->poolHealth->snapshot($a->fresh())['free_pool_state'],
        );
        $this->assertSame(
            FreePoolHealthState::Healthy->value,
            $this->poolHealth->snapshot($b->fresh())['free_pool_state'],
        );
        $this->assertNull($this->poolHealth->skipReasonForCandidate(
            $this->freeCandidate($b->fresh(), 'b-model:free', 99),
        ));
    }

    public function test_presenter_paid_tab_excludes_free_pool(): void
    {
        $or = $this->connection(20, 'OR');
        $free = $this->model($or, 'x:free', true);
        $paid = $this->model($or, 'openai/gpt', false);
        $this->priorities->appendToArea(20, AiModelArea::FreeModels, [(int) $free->id]);
        $this->priorities->appendToArea(20, AiModelArea::TextFast, [(int) $paid->id]);
        $rows = (new AiCenterModelPresenter())->areaRows(20, AiModelArea::TextFast);
        foreach ($rows as $row) {
            $this->assertTrue(empty($row['is_free']));
            $this->assertTrue(empty($row['is_free_pool']));
        }
    }

    private function markCatalogFresh(ApiConnection $connection): void
    {
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $bag = is_array($meta[OpenRouterFreePoolHealthService::META_KEY] ?? null)
            ? $meta[OpenRouterFreePoolHealthService::META_KEY]
            : [];
        $bag['last_catalog_sync_at'] = Carbon::now()->subHour()->toIso8601String();
        $bag['last_catalog_sync_status'] = 'ok';
        $meta[OpenRouterFreePoolHealthService::META_KEY] = $bag;
        // Generic catalog is the freshness authority Free Pool consults.
        $meta[AiModelCatalogFreshnessService::META_KEY] = [
            'status' => AiModelCatalogFreshnessService::STATUS_FRESH,
            'last_success_at' => Carbon::now()->subHour()->toIso8601String(),
            'last_sync_at' => Carbon::now()->subHour()->toIso8601String(),
            'catalog_count' => 1,
        ];
        $connection->metadata = $meta;
        $connection->save();
    }

    private function forceAreaEnabled(SeoAiModel $model, AiModelArea $area, int $priority): void
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
    }

    private function seedFreeAndPaid(int $userId): void
    {
        $or = $this->connection($userId, 'OR');
        $ds = $this->connection($userId, 'DS', ApiConnectionProviders::DEEPSEEK);
        $free = $this->model($or, 'meta/llama:free', true);
        $paid = $this->model($ds, 'deepseek-chat', false);
        $this->grant($or, $free);
        $this->grant($ds, $paid);
        $this->priorities->appendToArea($userId, AiModelArea::FreeModels, [(int) $free->id]);
        $this->priorities->appendToArea($userId, AiModelArea::TextFast, [(int) $paid->id]);
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
            safeMessage: 'model temporarily rate-limited upstream',
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

    private function connection(int $userId, string $name, string $provider = ApiConnectionProviders::OPENROUTER): ApiConnection
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
}
