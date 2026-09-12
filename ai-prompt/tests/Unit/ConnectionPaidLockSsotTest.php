<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ConnectionPaidLockService;
use Omnichannel\Addons\AiPrompt\Services\SetAiConnectionFreeOnly;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\AiPrompt\Support\PaidLockReason;
use Tests\TestCase;

/**
 * ONE real paid lock per API connection (api_connections.paid_locked + paid_lock_reasons).
 * Tests A–K from the connection paid-lock SSOT contract.
 */
final class ConnectionPaidLockSsotTest extends TestCase
{
    private AiRuntimeHealthService $health;

    private ConnectionPaidLockService $paidLock;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ai_runtime_health_states');
        Schema::connection('mysql')->dropIfExists('ai_runtime_health_states');
        Schema::dropIfExists('api_connections');
        Schema::dropIfExists('seo_ai_models');

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

        Schema::create('ai_runtime_health_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('api_connection_id')->nullable()->index();
            $table->string('health_status', 32)->default('no_data');
            $table->boolean('paid_locked')->default(false);
            $table->boolean('manual_unlock_required')->default(false);
            $table->timestamp('cooldown_until')->nullable();
            $table->unsignedInteger('total_attempts')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->json('failure_counts')->nullable();
            $table->string('last_error_code', 32)->nullable();
            $table->string('last_failure_class', 64)->nullable();
            $table->text('last_failure_message')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'subject_type', 'subject_id']);
        });

        $this->health = new AiRuntimeHealthService(notifications: null);
        $this->paidLock = new ConnectionPaidLockService();
    }

    /** TEST A — manual Free-only */
    public function test_a_manual_free_only_sets_reason_and_skips_paid(): void
    {
        $conn = $this->seedConnection();
        $this->assertFalse((bool) $conn->paid_locked);
        $this->assertSame([], $this->paidLock->reasonValues($conn));

        $updated = app(SetAiConnectionFreeOnly::class)->handle($conn, true);
        $this->assertTrue((bool) $updated->paid_locked);
        $this->assertSame([PaidLockReason::ManualFreeOnly->value], $this->paidLock->reasonValues($updated));

        $this->assertSame('connection_paid_locked', $this->health->skipReason(1, $this->paidCandidate($updated)));
        $this->assertNull($this->health->skipReason(1, $this->freeCandidate($updated)));
    }

    /** TEST B — disable manual Free-only */
    public function test_b_disable_manual_free_only_clears_lock(): void
    {
        $conn = $this->seedConnection();
        app(SetAiConnectionFreeOnly::class)->handle($conn, true);
        $updated = app(SetAiConnectionFreeOnly::class)->handle($conn->fresh(), false);

        $this->assertSame([], $this->paidLock->reasonValues($updated));
        $this->assertFalse((bool) $updated->paid_locked);
    }

    /** TEST C — manual + budget overlap */
    public function test_c_manual_plus_budget_overlap(): void
    {
        $conn = $this->seedConnection();
        app(SetAiConnectionFreeOnly::class)->handle($conn, true);
        $this->health->recordFailure(1, $this->paidCandidate($conn->fresh()), $this->budgetDecision());

        $fresh = $conn->fresh();
        $reasons = $this->paidLock->reasonValues($fresh);
        $this->assertContains(PaidLockReason::ManualFreeOnly->value, $reasons);
        $this->assertContains(PaidLockReason::BudgetLimited->value, $reasons);
        $this->assertTrue((bool) $fresh->paid_locked);
    }

    /** TEST D — turn Free-only OFF while budget locked */
    public function test_d_turn_free_only_off_keeps_budget_lock(): void
    {
        $conn = $this->seedConnection();
        app(SetAiConnectionFreeOnly::class)->handle($conn, true);
        $this->paidLock->addReason($conn->fresh(), PaidLockReason::BudgetLimited);

        $updated = app(SetAiConnectionFreeOnly::class)->handle($conn->fresh(), false);
        $this->assertSame([PaidLockReason::BudgetLimited->value], $this->paidLock->reasonValues($updated));
        $this->assertTrue((bool) $updated->paid_locked);
    }

    /** TEST E — manual budget unlock while Free-only remains */
    public function test_e_enable_paid_routes_keeps_manual_free_only(): void
    {
        $conn = $this->seedConnection();
        app(SetAiConnectionFreeOnly::class)->handle($conn, true);
        $this->health->recordFailure(1, $this->paidCandidate($conn->fresh()), $this->budgetDecision());

        $this->health->enablePaidRoutes(1, (int) $conn->id);
        $fresh = $conn->fresh();
        $this->assertSame([PaidLockReason::ManualFreeOnly->value], $this->paidLock->reasonValues($fresh));
        $this->assertTrue((bool) $fresh->paid_locked);
    }

    /** TEST F — free route ignores lock */
    public function test_f_free_route_ignores_paid_lock(): void
    {
        $conn = $this->seedConnection();
        $this->paidLock->addReason($conn, PaidLockReason::BudgetLimited);
        $fresh = $conn->fresh();

        $this->assertNull($this->health->skipReason(1, $this->freeCandidate($fresh)));
        $this->assertSame('connection_paid_locked', $this->health->skipReason(1, $this->paidCandidate($fresh)));
    }

    /** TEST G — free model 429 must not create budget_limited */
    public function test_g_free_model_429_does_not_paid_lock_connection(): void
    {
        $conn = $this->seedConnection();
        $decision = new AiFailureDecision(
            category: AiFailureClass::RateLimited,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            manualUnlockRequired: false,
            errorCode: '429',
            safeMessage: 'rate limited',
            httpStatus: 429,
            lockConnectionPaid: false,
            applyCooldown: true,
        );
        $this->health->recordFailure(1, $this->freeCandidate($conn), $decision);

        $fresh = $conn->fresh();
        $this->assertFalse((bool) $fresh->paid_locked);
        $this->assertNotContains(PaidLockReason::BudgetLimited->value, $this->paidLock->reasonValues($fresh));
    }

    /** TEST H — paid transient failure does not lock */
    public function test_h_paid_transient_does_not_lock_connection(): void
    {
        $conn = $this->seedConnection();
        $decision = new AiFailureDecision(
            category: AiFailureClass::TransientProvider,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            manualUnlockRequired: false,
            errorCode: '503',
            safeMessage: 'timeout',
            httpStatus: 503,
            lockConnectionPaid: false,
            applyCooldown: true,
        );
        $this->health->recordFailure(1, $this->paidCandidate($conn), $decision);

        $fresh = $conn->fresh();
        $this->assertFalse((bool) $fresh->paid_locked);
        $this->assertSame([], $this->paidLock->reasonValues($fresh));
    }

    /** TEST I — paid 402 adds budget_limited */
    public function test_i_paid_402_adds_budget_limited_to_connection(): void
    {
        $conn = $this->seedConnection();
        $this->health->recordFailure(1, $this->paidCandidate($conn), $this->budgetDecision());

        $fresh = $conn->fresh();
        $this->assertTrue((bool) $fresh->paid_locked);
        $this->assertSame([PaidLockReason::BudgetLimited->value], $this->paidLock->reasonValues($fresh));
        $this->assertSame('connection_paid_locked', $this->health->skipReason(1, $this->paidCandidate($fresh)));
    }

    /** TEST J — legacy health migration/reconcile */
    public function test_j_legacy_health_budget_lock_reconciles_to_connection(): void
    {
        $conn = $this->seedConnection(['paid_locked' => false, 'paid_lock_reasons' => []]);

        AiRuntimeHealthState::query()->create([
            'user_id' => 1,
            'subject_type' => AiRuntimeHealthState::SUBJECT_CONNECTION,
            'subject_id' => (int) $conn->id,
            'api_connection_id' => (int) $conn->id,
            'health_status' => AiRuntimeHealthStatus::BudgetLimited->value,
            'paid_locked' => true,
            'manual_unlock_required' => true,
        ]);

        // Simulate migration backfill step for this connection.
        $this->paidLock->addReason($conn, PaidLockReason::BudgetLimited);
        $fresh = $conn->fresh();
        $this->assertTrue((bool) $fresh->paid_locked);
        $this->assertContains(PaidLockReason::BudgetLimited->value, $this->paidLock->reasonValues($fresh));

        // Even if health mirror were cleared, connection SSOT still enforces.
        AiRuntimeHealthState::query()->where('subject_id', $conn->id)->update(['paid_locked' => false]);
        $this->assertSame('connection_paid_locked', $this->health->skipReason(1, $this->paidCandidate($fresh)));
    }

    /** TEST K — no duplicate authority */
    public function test_k_enforcement_follows_connection_not_legacy_health_boolean(): void
    {
        $conn = $this->seedConnection(['paid_locked' => false, 'paid_lock_reasons' => []]);

        AiRuntimeHealthState::query()->create([
            'user_id' => 1,
            'subject_type' => AiRuntimeHealthState::SUBJECT_CONNECTION,
            'subject_id' => (int) $conn->id,
            'api_connection_id' => (int) $conn->id,
            'health_status' => AiRuntimeHealthStatus::BudgetLimited->value,
            'paid_locked' => true,
            'manual_unlock_required' => true,
        ]);

        // Connection unlocked → paid candidate must NOT be skipped by health.paid_locked alone.
        $this->assertNull($this->health->skipReason(1, $this->paidCandidate($conn)));

        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Services/AiRuntimeHealthService.php'
        );
        $this->assertIsString($source);
        // Architecture guard: skipReason must not consult health.paid_locked for enforcement.
        $this->assertDoesNotMatchRegularExpression(
            '/function skipReason.*?connectionHealth->paid_locked/s',
            $source,
        );
        $this->assertStringContainsString('connectionPaidLaneLocked', $source);
        $this->assertStringContainsString('deprecated mirror; not used for route enforcement', $source);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedConnection(array $overrides = []): ApiConnection
    {
        $payload = array_merge([
            'user_id' => 1,
            'provider' => 'openrouter',
            'name' => 'OR',
            'api_key' => 'k',
            'is_global' => false,
            'status' => 'active',
            'paid_locked' => false,
            'paid_lock_reasons' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
        if (is_array($payload['paid_lock_reasons'] ?? null)) {
            $payload['paid_lock_reasons'] = json_encode(array_values($payload['paid_lock_reasons']));
        }

        $id = DB::table('api_connections')->insertGetId($payload);

        return ApiConnection::query()->findOrFail($id);
    }

    private function paidCandidate(ApiConnection $connection): RoutedAiCandidate
    {
        return new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: 'anthropic/claude',
            capabilities: [],
            priority: 1,
            isFree: false,
        );
    }

    private function freeCandidate(ApiConnection $connection): RoutedAiCandidate
    {
        return new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: 'google/gemma:free',
            capabilities: [],
            priority: 2,
            isFree: true,
        );
    }

    private function budgetDecision(): AiFailureDecision
    {
        return new AiFailureDecision(
            category: AiFailureClass::InsufficientBudgetForRequest,
            scope: AiFailureScope::ConnectionPaid,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::BudgetLimited,
            manualUnlockRequired: true,
            errorCode: '402',
            safeMessage: 'Insufficient budget',
            httpStatus: 402,
            lockConnectionPaid: true,
        );
    }
}
