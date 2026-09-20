<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ConnectionPaidLockService;
use Omnichannel\Addons\AiPrompt\Services\FreePoolResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolHealthService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolService;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\FreePoolHealthState;
use Omnichannel\Addons\AiPrompt\Support\PaidLockReason;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Temporary free-pool lock cycle — per physical connection_id, never provider-wide.
 */
final class FreePoolTemporaryLockCycleTest extends TestCase
{
    private OpenRouterFreePoolHealthService $poolHealth;

    private AiModelPriorityService $priorities;

    protected function setUp(): void
    {
        parent::setUp();
        OpenRouterFreePoolHealthService::clearProbeOwners();
        AiRuntimeHealthService::clearSuppressedFreeLanes();
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

        // Short cooldowns for assertions.
        (new FreePoolResilienceSettingsService())->save(0, [
            FreePoolResilienceSettingsService::KEY_FIRST_PROBE_MINUTES => 5,
            FreePoolResilienceSettingsService::KEY_PROBE_BACKOFF_MULTIPLIER => 2.0,
            FreePoolResilienceSettingsService::KEY_MAX_PROBE_HOURS => 1,
            FreePoolResilienceSettingsService::KEY_SUCCESS_PROBES_TO_UNLOCK => 1,
            FreePoolResilienceSettingsService::KEY_FIRST_COOLDOWN_MINUTES => 2,
            FreePoolResilienceSettingsService::KEY_REPEAT_COOLDOWN_MINUTES => 4,
            FreePoolResilienceSettingsService::KEY_HARD_LOCK_RATIO_PERCENT => 70,
            FreePoolResilienceSettingsService::KEY_MIN_DISTINCT_FAILURE_MODELS => 4,
            FreePoolResilienceSettingsService::KEY_HARD_LOCK_WHEN_ALL_ATTEMPTED_FAIL => true,
        ]);

        $this->poolHealth = new OpenRouterFreePoolHealthService(new FreePoolResilienceSettingsService());
        $this->priorities = new AiModelPriorityService();
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
    }

    public function test_1_or_a_temporary_lock_or_b_still_runs(): void
    {
        $a = $this->connection(1, 'OR-A');
        $b = $this->connection(1, 'OR-B');
        $this->lockTemporaryFree($a, 'daily');

        $this->assertSame(
            OpenRouterFreePoolHealthService::SKIP_DAILY_QUOTA,
            $this->poolHealth->skipReasonForCandidate($this->freeCandidate($a->fresh(), 'a:free', 1)),
        );
        $this->assertNull(
            $this->poolHealth->skipReasonForCandidate($this->freeCandidate($b->fresh(), 'b:free', 2)),
        );

        $filtered = $this->poolHealth->filterCandidatesForCircuit([
            $this->freeCandidate($a->fresh(), 'a:free', 1),
            $this->freeCandidate($b->fresh(), 'b:free', 2),
        ]);
        $this->assertCount(1, $filtered);
        $this->assertSame((int) $b->id, (int) $filtered[0]->connection->id);
    }

    public function test_2_expired_lock_becomes_probe_eligible_automatically(): void
    {
        $a = $this->connection(2, 'OR-A');
        $this->seedExpiredTemporaryLock($a, FreePoolHealthState::DailyQuotaLocked);

        $state = $this->poolHealth->state($a->fresh());
        $this->assertSame(FreePoolHealthState::WaitingProbe, $state);
        $this->assertTrue($this->poolHealth->ownsProbeClaim($a->fresh()));
        $this->assertTrue($this->poolHealth->allowsMemberExpansion($a->fresh()));
    }

    public function test_3_expired_probe_fails_quota_again_gets_new_future_lock_not_permanent(): void
    {
        $a = $this->connection(3, 'OR-A');
        $this->seedExpiredTemporaryLock($a, FreePoolHealthState::HardLocked);
        $this->assertTrue($this->poolHealth->tryClaimProbe($a->fresh()) || $this->poolHealth->ownsProbeClaim($a->fresh()));

        $this->poolHealth->recordQualifyingFailure(
            3,
            $this->freeCandidate($a->fresh(), 'probe:free', 1),
            $this->dailyQuotaDecision(),
            5,
        );
        $snap = $this->poolHealth->snapshot($a->fresh());
        $this->assertSame(FreePoolHealthState::DailyQuotaLocked->value, $snap['free_pool_state']);
        $this->assertNotNull($snap['free_pool_lock_until']);
        $this->assertTrue(Carbon::parse((string) $snap['free_pool_lock_until'])->isFuture());
        $this->assertTrue(FreePoolHealthState::DailyQuotaLocked->isTemporaryLock());
        // No permanent / manual unlock semantics in free-pool bag.
        $this->assertArrayNotHasKey('manual_unlock_required', $snap);
    }

    public function test_4_expired_probe_success_clears_to_healthy(): void
    {
        $a = $this->connection(4, 'OR-A');
        $this->seedExpiredTemporaryLock($a, FreePoolHealthState::HardLocked);
        $this->poolHealth->state($a->fresh()); // claim probe
        $candidate = $this->freeCandidate($a->fresh(), 'ok:free', 1);
        $this->poolHealth->recordSuccess($candidate);
        $snap = $this->poolHealth->snapshot($a->fresh());
        $this->assertSame(FreePoolHealthState::Healthy->value, $snap['free_pool_state']);
        $this->assertNull($snap['free_pool_lock_until']);
        $this->assertNull($snap['free_pool_lock_reason']);
    }

    public function test_5_free_quota_lock_does_not_block_paid_lane(): void
    {
        $a = $this->connection(5, 'OR-A');
        $this->lockTemporaryFree($a, 'daily');
        $paid = new RoutedAiCandidate(
            profile: 'text.fast',
            connection: $a->fresh(),
            provider: ApiConnectionProviders::OPENROUTER,
            model: 'openai/gpt-paid',
            capabilities: [],
            priority: 1,
            seoAiModelId: 99,
            isFree: false,
        );
        $this->assertNull($this->poolHealth->skipReasonForCandidate($paid));
        $filtered = $this->poolHealth->filterCandidatesForCircuit([
            $this->freeCandidate($a->fresh(), 'a:free', 1),
            $paid,
        ]);
        $this->assertCount(1, $filtered);
        $this->assertFalse($filtered[0]->isFree);
    }

    public function test_6_paid_budget_lock_does_not_block_free_lane(): void
    {
        $a = $this->connection(6, 'OR-A');
        app(ConnectionPaidLockService::class)->addReason($a, PaidLockReason::BudgetLimited);
        $a->refresh();
        $this->assertTrue((bool) $a->paid_locked);

        $this->assertNull(
            $this->poolHealth->skipReasonForCandidate($this->freeCandidate($a->fresh(), 'a:free', 1)),
        );
        $this->assertSame(FreePoolHealthState::Healthy, $this->poolHealth->state($a->fresh()));
    }

    public function test_7_model_specific_429_cools_only_that_model(): void
    {
        $a = $this->connection(7, 'OR-A');
        $hit = $this->freeCandidate($a, 'hit:free', 1);
        $sib = $this->freeCandidate($a, 'sib:free', 2);
        $this->poolHealth->recordQualifyingFailure(7, $hit, $this->rateLimitDecision(), 10);
        $this->assertSame('free_model_cooldown', $this->poolHealth->skipReasonForCandidate($hit));
        $this->assertNull($this->poolHealth->skipReasonForCandidate($sib));
        $this->assertNotSame(FreePoolHealthState::HardLocked->value, $this->poolHealth->snapshot($a->fresh())['free_pool_state']);
    }

    public function test_8_both_temporarily_locked_free_only_exhausted_until_expiry(): void
    {
        $a = $this->connection(8, 'OR-A');
        $b = $this->connection(8, 'OR-B');
        $this->lockTemporaryFree($a, 'daily');
        $this->lockTemporaryFree($b, 'daily');
        $filtered = $this->poolHealth->filterCandidatesForCircuit([
            $this->freeCandidate($a->fresh(), 'a:free', 1),
            $this->freeCandidate($b->fresh(), 'b:free', 2),
        ]);
        $this->assertSame([], $filtered);
    }

    public function test_9_one_expiry_restores_candidate_without_user_intervention(): void
    {
        $a = $this->connection(9, 'OR-A');
        $b = $this->connection(9, 'OR-B');
        $this->seedExpiredTemporaryLock($a, FreePoolHealthState::DailyQuotaLocked);
        $this->lockTemporaryFree($b, 'daily');

        $this->poolHealth->state($a->fresh());
        $filtered = $this->poolHealth->filterCandidatesForCircuit([
            $this->freeCandidate($a->fresh(), 'a:free', 1),
            $this->freeCandidate($b->fresh(), 'b:free', 2),
        ]);
        $this->assertCount(1, $filtered);
        $this->assertSame((int) $a->id, (int) $filtered[0]->connection->id);
    }

    public function test_10_credential_invalid_on_a_does_not_lock_provider_or_b(): void
    {
        $a = $this->connection(10, 'OR-A');
        $b = $this->connection(10, 'OR-B');
        // Credential failures are outside free-pool temporary lock — free pool on B stays healthy.
        $this->assertSame(FreePoolHealthState::Healthy, $this->poolHealth->state($a));
        $this->assertNull(
            $this->poolHealth->skipReasonForCandidate($this->freeCandidate($b->fresh(), 'b:free', 2)),
        );
        $this->assertSame(FreePoolHealthState::Healthy, $this->poolHealth->state($b->fresh()));
    }

    public function test_11_no_provider_wide_state_indexed_only_by_openrouter(): void
    {
        $a = $this->connection(11, 'OR-A');
        $b = $this->connection(11, 'OR-B');
        $this->lockTemporaryFree($a, 'ratio');
        $this->assertSame(FreePoolHealthState::HardLocked->value, $this->poolHealth->snapshot($a->fresh())['free_pool_state']);
        $this->assertSame(FreePoolHealthState::Healthy->value, $this->poolHealth->snapshot($b->fresh())['free_pool_state']);
        // Metadata bag is per-connection, not a global openrouter key.
        $metaA = is_array($a->fresh()->metadata) ? $a->fresh()->metadata : [];
        $metaB = is_array($b->fresh()->metadata) ? $b->fresh()->metadata : [];
        $this->assertArrayHasKey(OpenRouterFreePoolHealthService::META_KEY, $metaA);
        $this->assertNotEquals(
            $metaA[OpenRouterFreePoolHealthService::META_KEY]['free_pool_state'] ?? null,
            $metaB[OpenRouterFreePoolHealthService::META_KEY]['free_pool_state'] ?? FreePoolHealthState::Healthy->value,
        );
    }

    public function test_12_no_promotional_quota_constants_in_lock_fallback(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Services/OpenRouterFreePoolHealthService.php');
        $this->assertIsString($src);
        $this->assertStringNotContainsString('1000/day', $src);
        $this->assertStringNotContainsString('50/day', $src);
        $this->assertStringNotContainsString('addDay()->startOfDay()', $src);
        $this->assertStringNotContainsString('$10', $src);

        $runtimeSrc = file_get_contents(dirname(__DIR__, 2).'/src/Services/AiRuntimeHealthService.php');
        $this->assertIsString($runtimeSrc);
        $this->assertStringNotContainsString('addDay()->startOfDay()', $runtimeSrc);
    }

    public function test_free_models_area_includes_fast_text_primary_free_members(): void
    {
        $or = $this->connection(20, 'OR');
        $model = SeoAiModel::query()->create([
            'api_connection_id' => $or->id,
            'raw_model_name' => 'meta/llama:free',
            'display_name' => 'meta/llama:free',
            'category' => AiModelCategory::GEMINI_FLASH,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'omi_primary_type' => AiModelArea::TextFast->value,
                'resolved' => ['text.generate'],
                'provider_metadata' => [
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                    'architecture' => ['modality' => 'text->text', 'output_modalities' => ['text']],
                ],
            ],
        ]);
        AiModelCapabilityRow::query()->create([
            'api_connection_id' => $or->id,
            'seo_ai_model_id' => $model->id,
            'model_key' => $model->raw_model_name,
            'capability' => AiModelCapability::TextGenerate->value,
            'enabled' => true,
        ]);

        $pool = new OpenRouterFreePoolService();
        $catalog = $pool->catalogCandidates(20, AiModelArea::FreeModels);
        $this->assertNotSame([], $catalog);
        $this->assertSame('meta/llama:free', $catalog[0]['provider_model_id']);
    }

    public function test_daily_quota_without_provider_reset_uses_configurable_cooldown_not_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 23:50:00'));
        $a = $this->connection(0, 'OR-A'); // settings saved under user 0 in setUp
        $this->poolHealth->lockDailyQuota($a, $this->dailyQuotaDecision(resetAt: null));
        $until = Carbon::parse((string) $this->poolHealth->snapshot($a->fresh())['free_pool_lock_until']);
        $midnight = Carbon::now()->copy()->addDay()->startOfDay();
        $this->assertFalse($until->equalTo($midnight), 'must not invent marketing midnight reset');
        $minutes = Carbon::now()->diffInMinutes($until, false);
        $this->assertGreaterThan(0, $minutes);
        $this->assertLessThanOrEqual(60, $minutes); // configurable probe window, not a calendar day
        Carbon::setTestNow();
    }

    private function lockTemporaryFree(ApiConnection $connection, string $mode): void
    {
        if ($mode === 'daily') {
            $this->poolHealth->lockDailyQuota($connection, $this->dailyQuotaDecision(
                resetAt: Carbon::now()->addHour()->toIso8601String(),
            ));

            return;
        }
        for ($i = 1; $i <= 7; $i++) {
            $this->poolHealth->recordQualifyingFailure(
                (int) $connection->user_id,
                $this->freeCandidate($connection->fresh(), 'm'.$i.':free', $i),
                $this->rateLimitDecision(),
                10,
            );
        }
    }

    private function seedExpiredTemporaryLock(ApiConnection $connection, FreePoolHealthState $state): void
    {
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $meta[OpenRouterFreePoolHealthService::META_KEY] = [
            'free_pool_state' => $state->value,
            'free_pool_lock_until' => Carbon::now()->subMinute()->toIso8601String(),
            'next_probe_at' => Carbon::now()->subMinute()->toIso8601String(),
            'free_pool_lock_reason' => 'test_expired',
            'consecutive_probe_failures' => 1,
        ];
        $connection->metadata = $meta;
        $connection->save();
    }

    private function dailyQuotaDecision(?string $resetAt = '2099-01-01T00:00:00+00:00'): AiFailureDecision
    {
        return new AiFailureDecision(
            category: AiFailureClass::DailyFreeQuotaExhausted,
            scope: AiFailureScope::ConnectionFree,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            suppressConnectionFree: true,
            affectsRuntimeHealth: true,
            httpStatus: 429,
            freeDailyResetAt: $resetAt,
            limitSource: 'openrouter_free_tier_daily',
            safeMessage: 'free quota exhausted',
        );
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

    private function connection(int $userId, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => $name,
            'api_key' => 'test-key-'.$userId.'-'.$name,
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }
}
