<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use App\Models\ApiConnection;
use App\Models\WpOption;
use Tests\TestCase;

final class AiRuntimeHealthStateTest extends TestCase
{
    private AiRuntimeHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::connection('mysql')->dropIfExists('ai_runtime_health_states');
        Schema::connection('mysql')->create('ai_runtime_health_states', function (Blueprint $table): void {
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
    }

    private function connection(int $id, string $provider): ApiConnection
    {
        $connection = new ApiConnection();
        $connection->forceFill([
            'id' => $id,
            'provider' => $provider,
            'status' => 'active',
            'name' => 'Test '.$provider,
        ]);

        return $connection;
    }

    public function test_402_sets_paid_lock_and_skip_paid_candidate(): void
    {
        $connection = $this->connection(12, 'openrouter');
        $candidate = new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: 'anthropic/claude-sonnet',
            capabilities: [],
            priority: 1,
            isFree: false,
        );
        $decision = new AiFailureDecision(
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
        $this->health->recordFailure(7, $candidate, $decision);
        $this->assertSame('connection_paid_locked', $this->health->skipReason(7, $candidate));

        $freeCandidate = new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: 'google/gemma:free',
            capabilities: [],
            priority: 2,
            isFree: true,
        );
        $this->assertNull($this->health->skipReason(7, $freeCandidate));
    }

    public function test_model_scoped_transient_cooldown_does_not_block_sibling_on_same_connection(): void
    {
        $connection = $this->connection(21, 'openrouter');
        $failed = new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: 'paid/claude',
            capabilities: [],
            priority: 1,
            isFree: false,
        );
        $sibling = new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: 'free/gemma:free',
            capabilities: [],
            priority: 2,
            isFree: true,
        );
        $decision = (new AiProviderFailureClassifier())->classify(
            new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException('503 service unavailable', 503),
        );
        $this->assertTrue($decision->fallbackAllowed());
        $this->assertTrue($decision->applyCooldown);
        $this->assertSame(AiFailureScope::Model, $decision->scope);

        $this->health->recordFailure(21, $failed, $decision);
        // Connection must stay usable so sibling models on the same key can still run.
        $this->assertNull($this->health->skipReason(21, $failed));
        $this->assertNull($this->health->skipReason(21, $sibling));
    }

    public function test_manual_unlock_clears_connection_lock(): void
    {
        $connection = $this->connection(15, 'gemini');
        $candidate = new RoutedAiCandidate(
            profile: 'text.fast',
            connection: $connection,
            provider: 'gemini',
            model: 'gemini-flash',
            capabilities: [],
            priority: 1,
        );
        $decision = new AiFailureDecision(
            category: AiFailureClass::CredentialInvalid,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::ConnectionLocked,
            manualUnlockRequired: true,
            errorCode: '401',
            safeMessage: 'Invalid key',
            httpStatus: 401,
            lockConnection: true,
        );
        $this->health->recordFailure(3, $candidate, $decision);
        $this->assertSame('connection_locked', $this->health->skipReason(3, $candidate));
        $this->health->unlockConnection(3, 15);
        $this->assertNull($this->health->skipReason(3, $candidate));

        $row = AiRuntimeHealthState::query()->where('subject_id', 15)->first();
        $this->assertSame(1, (int) $row?->failure_count);
    }
}
