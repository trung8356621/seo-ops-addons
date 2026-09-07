<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use App\Models\WpOption;
use Tests\TestCase;

final class AiRuntimeHealthRecoveryAndExhaustionTest extends TestCase
{
    private AiRuntimeHealthService $health;

    private AiModelRouterService $router;

    private AiModelPriorityService $priorities;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections', 'wp_options'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('ai_runtime_health_states');
        Schema::connection('mysql')->dropIfExists('ai_runtime_health_states');
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
        Schema::create('ai_routing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('required_capabilities')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_routing_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->string('profile_key');
            $table->unsignedBigInteger('api_connection_id');
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->string('model_key');
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
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
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();

        $this->priorities = new AiModelPriorityService();
        $registry = new ModelCapabilityRegistry();
        $targets = new AiRoutingTargetService($registry, priorities: $this->priorities);
        $bootstrap = new AiRoutingBootstrapService($registry, $targets);
        $this->health = new AiRuntimeHealthService(notifications: null);
        $this->router = new AiModelRouterService($registry, $targets, $bootstrap);
        $this->app->instance(AiProviderFailureClassifier::class, new AiProviderFailureClassifier());
        $this->app->instance(AiRuntimeHealthService::class, $this->health);
        $this->app->instance(AiResilienceSettingsService::class, new AiResilienceSettingsService());
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
        $this->app->instance(AiRoutingTargetService::class, $targets);
    }

    public function test_a_rate_limit_never_becomes_permanent_unavailable(): void
    {
        $candidate = $this->seedCandidate(701, 'model/a');
        $decision = $this->rateLimitDecision();
        for ($i = 0; $i < 6; $i++) {
            $this->health->recordFailure(701, $candidate, $decision);
        }
        $row = \Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState::query()
            ->where('user_id', 701)
            ->where('subject_type', 'model')
            ->where('subject_id', $candidate->seoAiModelId)
            ->first();
        $this->assertNotNull($row);
        $this->assertNotSame(AiRuntimeHealthStatus::Unavailable->value, $row->health_status);
        $this->assertSame(AiRuntimeHealthStatus::Degraded->value, $row->health_status);
        $this->assertNotNull($row->cooldown_until);

        $row->cooldown_until = now()->subMinute();
        $row->save();
        $this->assertNull($this->health->skipReason(701, $candidate));
    }

    public function test_b_transient_503_stays_recoverable(): void
    {
        $candidate = $this->seedCandidate(702, 'model/b');
        $decision = new AiFailureDecision(
            category: AiFailureClass::TransientProvider,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            safeMessage: '503',
            httpStatus: 503,
            applyCooldown: true,
        );
        for ($i = 0; $i < 6; $i++) {
            $this->health->recordFailure(702, $candidate, $decision);
        }
        $row = \Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState::query()
            ->where('user_id', 702)
            ->where('subject_type', 'model')
            ->where('subject_id', $candidate->seoAiModelId)
            ->first();
        $this->assertSame(AiRuntimeHealthStatus::Degraded->value, $row?->health_status);
        $this->assertNotSame(AiRuntimeHealthStatus::Unavailable->value, $row?->health_status);
    }

    public function test_c_hard_404_unavailable(): void
    {
        $candidate = $this->seedCandidate(703, 'model/c');
        $decision = new AiFailureDecision(
            category: AiFailureClass::ModelNotFound,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Unavailable,
            safeMessage: '404',
            httpStatus: 404,
            markModelUnavailable: true,
        );
        $this->health->recordFailure(703, $candidate, $decision);
        $this->assertSame('model_unavailable', $this->health->skipReason(703, $candidate));
    }

    public function test_d_success_recovers_health(): void
    {
        $candidate = $this->seedCandidate(704, 'model/d');
        $this->health->recordFailure(704, $candidate, $this->rateLimitDecision());
        $this->health->recordSuccess(704, $candidate);
        $row = \Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState::query()
            ->where('user_id', 704)
            ->where('subject_type', 'model')
            ->where('subject_id', $candidate->seoAiModelId)
            ->first();
        $this->assertSame(AiRuntimeHealthStatus::Healthy->value, $row?->health_status);
        $this->assertSame(0, (int) $row?->consecutive_failures);
        $this->assertNull($row?->cooldown_until);
    }

    public function test_g_all_cooldown_is_retryable_temporary_health(): void
    {
        $this->seedOrdered(710, ['test/a', 'test/b', 'test/c']);
        $candidates = app(AiRoutingTargetService::class)->eligibleCandidates(
            710,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 710),
        );
        $this->assertNotEmpty($candidates);
        foreach ($candidates as $candidate) {
            $this->health->recordFailure(710, $candidate, $this->rateLimitDecision());
        }
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 710),
                fn () => ['x', null],
            );
            $this->fail('expected exhaust');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(0, $exception->context['attempt_count'] ?? null);
            $this->assertTrue((bool) ($exception->context['retryable'] ?? false));
            $this->assertSame(
                AiRoutesExhaustionClassifier::KIND_TEMPORARY_HEALTH,
                $exception->context['exhaustion_kind'] ?? null,
            );
            $this->assertGreaterThan(0, (int) ($exception->context['retry_after_seconds'] ?? 0));
        }
    }

    public function test_h_all_transient_failures_retryable(): void
    {
        $this->seedOrdered(711, ['test/a', 'test/b', 'test/c']);
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 711),
                function ($candidate): array {
                    return match ($candidate->model) {
                        'test/a' => throw new PromptRunException('429', 429),
                        'test/b' => throw new PromptRunException('503', 503),
                        default => throw new PromptRunException('timeout waiting for response', 408),
                    };
                },
            );
            $this->fail('expected exhaust');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(3, $exception->context['attempt_count'] ?? null);
            $this->assertTrue((bool) ($exception->context['retryable'] ?? false));
            $this->assertContains(
                $exception->context['exhaustion_kind'] ?? '',
                [
                    AiRoutesExhaustionClassifier::KIND_TRANSIENT_PROVIDER,
                    AiRoutesExhaustionClassifier::KIND_MIXED,
                ],
            );
        }
    }

    public function test_i_hard_exhaustion_not_retryable(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'model' => 'x',
                'failure_class' => AiFailureClass::ModelNotFound->value,
            ]],
        );
        $this->assertFalse($exception->isRetryable());
        $this->assertSame(AiRoutesExhaustionClassifier::KIND_HARD, $exception->exhaustionKind());
    }

    public function test_s_diagnostics_include_connection_count(): void
    {
        $this->seedOrdered(712, ['test/a', 'test/b', 'test/c']);
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 712),
                fn () => throw new PromptRunException('429', 429),
            );
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(3, $exception->context['eligible_count'] ?? null);
            $this->assertSame(1, $exception->context['eligible_connection_count'] ?? null);
        }
    }

    private function rateLimitDecision(): AiFailureDecision
    {
        return new AiFailureDecision(
            category: AiFailureClass::RateLimited,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            safeMessage: '429',
            httpStatus: 429,
            applyCooldown: true,
        );
    }

    private function seedCandidate(int $userId, string $raw): RoutedAiCandidate
    {
        $models = $this->seedOrdered($userId, [$raw]);

        return app(AiRoutingTargetService::class)->eligibleCandidates(
            $userId,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: $userId),
        )[0];
    }

    /**
     * @param  list<string>  $raws
     * @return list<SeoAiModel>
     */
    private function seedOrdered(int $userId, array $raws): array
    {
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OR '.$userId,
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $ids = [];
        $models = [];
        foreach ($raws as $raw) {
            $model = SeoAiModel::query()->create([
                'api_connection_id' => $connection->id,
                'raw_model_name' => $raw,
                'display_name' => $raw,
                'category' => AiModelCategory::GEMINI_FLASH,
                'priority' => 100,
                'status' => SeoAiModel::STATUS_ACTIVE,
                'capabilities' => [
                    'provider_metadata' => [
                        'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
                        'architecture' => ['modality' => 'text->text'],
                    ],
                ],
            ]);
            foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
                AiModelCapabilityRow::query()->create([
                    'api_connection_id' => $connection->id,
                    'seo_ai_model_id' => $model->id,
                    'model_key' => $raw,
                    'capability' => $cap,
                    'enabled' => true,
                ]);
            }
            $ids[] = (int) $model->id;
            $models[] = $model;
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, $ids);

        return $models;
    }
}
