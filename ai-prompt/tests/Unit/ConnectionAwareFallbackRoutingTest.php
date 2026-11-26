<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingProfile;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingTarget;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
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
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Router cases: MODEL vs CONNECTION failure scope + suppressedConnections attempt budget.
 */
final class ConnectionAwareFallbackRoutingTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRoutingTargetService $targets;

    private AiModelRouterService $router;

    private AiRuntimeHealthService $health;

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
        $this->targets = new AiRoutingTargetService($registry, priorities: $this->priorities);
        $bootstrap = new AiRoutingBootstrapService($registry, $this->targets);
        $this->health = new AiRuntimeHealthService(notifications: null);
        $this->router = new AiModelRouterService($registry, $this->targets, $bootstrap);
        $this->app->instance(AiProviderFailureClassifier::class, new AiProviderFailureClassifier());
        $this->app->instance(AiRuntimeHealthService::class, $this->health);
        $this->app->instance(AiResilienceSettingsService::class, new AiResilienceSettingsService());
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
        $this->app->instance(AiRoutingTargetService::class, $this->targets);
    }

    public function test_case1_model_404_allows_same_connection_sibling(): void
    {
        $this->seedOrThenGemini(60, ['or/a', 'or/b'], ['gem/c']);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 60, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'or/a') {
                    throw new PromptRunException('model not found', 404);
                }

                return ['ok-'.$candidate->model, null];
            },
        );
        $this->assertSame('ok-or/b', $output);
        $this->assertSame(['or/a', 'or/b'], $calls);
    }

    public function test_case2_401_suppresses_same_connection_models_then_failover(): void
    {
        $this->seedTwoOpenRouterConnections(61, ['or/a', 'or/b', 'or/c'], ['or2/d']);
        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 61, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if (str_starts_with($candidate->model, 'or/')) {
                    throw new PromptRunException('invalid api key', 401);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['or/a', 'or2/d'], $calls);
        $skipped = array_values(array_filter(
            $attempts,
            static fn (array $row): bool => ($row['result'] ?? '') === 'skipped' && ($row['skip_reason'] ?? '') === 'connection_suppressed',
        ));
        $this->assertCount(2, $skipped);
        $failedProviderAttempts = array_values(array_filter(
            $attempts,
            static fn (array $row): bool => ($row['result'] ?? '') === 'failed',
        ));
        $this->assertCount(1, $failedProviderAttempts);
    }

    public function test_case3_402_credits_connection_failover(): void
    {
        $this->seedTwoOpenRouterConnections(62, ['or/a', 'or/b'], ['or2/c']);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 62, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'or/a') {
                    throw new PromptRunException('This request requires more credits', 402);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['or/a', 'or2/c'], $calls);
    }

    public function test_case4_model_429_allows_sibling_on_same_connection(): void
    {
        $this->seedOrThenGemini(63, ['or/a', 'or/b'], []);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 63, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'or/a') {
                    throw new PromptRunException('429 rate limit exceeded for model', 429);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['or/a', 'or/b'], $calls);
    }

    public function test_case5_account_quota_429_suppresses_connection(): void
    {
        $this->seedTwoOpenRouterConnections(64, ['or/a', 'or/b'], ['or2/c']);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 64, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'or/a') {
                    throw new PromptRunException('organization quota exceeded', 429);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['or/a', 'or2/c'], $calls);
    }

    public function test_case6_prior_connection_lock_skips_without_provider_call(): void
    {
        $this->seedTwoOpenRouterConnections(65, ['or/a', 'or/b'], ['or2/c']);
        $cands = $this->targets->eligibleCandidates(
            65,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 65),
        );
        $firstConnId = (int) $cands[0]->connection->id;
        $orCand = null;
        foreach ($cands as $cand) {
            if ((int) $cand->connection->id === $firstConnId) {
                $orCand = $cand;
                break;
            }
        }
        $this->assertNotNull($orCand);
        $this->health->recordFailure(65, $orCand, new AiFailureDecision(
            category: AiFailureClass::CredentialInvalid,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::ConnectionLocked,
            safeMessage: 'Invalid API credentials.',
            httpStatus: 401,
            lockConnection: true,
            manualUnlockRequired: true,
            affectsRuntimeHealth: true,
            failureStage: 'provider_http',
        ));

        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 65, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['or2/c'], $calls);
        $this->assertSame(1, (int) collect($attempts)->where('result', 'success')->count());
    }

    public function test_case7_unlock_after_credential_fix_clears_lock(): void
    {
        $this->seedOrThenGemini(66, ['or/a'], []);
        $cands = $this->targets->eligibleCandidates(66, AiExecutionProfile::TextReasoning, new AiRoutingContext(userId: 66));
        $this->health->recordFailure(66, $cands[0], new AiFailureDecision(
            category: AiFailureClass::CredentialInvalid,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::ConnectionLocked,
            safeMessage: 'Invalid API credentials.',
            httpStatus: 401,
            lockConnection: true,
            manualUnlockRequired: true,
            affectsRuntimeHealth: true,
            failureStage: 'provider_http',
        ));
        $this->assertSame('connection_locked', $this->health->skipReason(66, $cands[0]));

        $cleared = $this->health->unlockConnectionForApiConnection((int) $cands[0]->connection->id);
        $this->assertGreaterThan(0, $cleared);
        $this->assertNull($this->health->skipReason(66, $cands[0]));

        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 66, hookKey: 'article.outline.structure.generate'),
            static fn (): array => ['ok', null],
        );
        $this->assertSame('ok', $output);
    }

    public function test_case8_all_unavailable_user_facing_message_keeps_technical(): void
    {
        $this->seedOrThenGemini(67, ['or/a'], []);
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 67, hookKey: 'article.outline.structure.generate'),
                static function (): array {
                    throw new PromptRunException('invalid api key', 401);
                },
            );
            $this->fail('Expected AiRoutesExhaustedException');
        } catch (AiRoutesExhaustedException $e) {
            $this->assertStringContainsString('AI_ROUTES_EXHAUSTED', $e->getMessage());
            $user = $e->userMessage();
            $this->assertStringNotContainsString('AI_ROUTES_EXHAUSTED', $user);
            $this->assertStringNotContainsString('connection lock', strtolower($user));
            $this->assertStringContainsString('API Connections', $user);
        }
    }

    public function test_models_resolve_core_connection_name(): void
    {
        $expected = (string) config('database.core_connection', 'mysql');
        $this->assertSame($expected, (new ApiConnection)->getConnectionName());
        $this->assertSame($expected, (new SeoAiModel)->getConnectionName());
        $this->assertSame($expected, (new AiRoutingProfile)->getConnectionName());
        $this->assertSame($expected, (new AiRoutingTarget)->getConnectionName());
        $this->assertSame($expected, (new AiModelCapabilityRow)->getConnectionName());
        $this->assertSame($expected, (new AiProviderTemplate)->getConnectionName());
        $this->assertSame($expected, (new AiRuntimeHealthState)->getConnectionName());
    }

    public function test_openrouter_relay_skips_sibling_models_and_fails_over(): void
    {
        $this->seedPriorityProviders(80, [
            [ApiConnectionProviders::OPENROUTER, 'OpenRouter', ['or/a', 'or/b', 'or/c']],
            [ApiConnectionProviders::NVIDIA, 'NVIDIA NIM', ['nim/x']],
            [ApiConnectionProviders::GEMINI, 'Google Gemini', ['gem/y']],
        ]);
        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 80, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if (str_starts_with($candidate->model, 'or/')) {
                    throw new PromptRunException('Provider API error (400): Request path cannot be relayed', 400);
                }

                return ['ok-'.$candidate->model, null];
            },
        );
        $this->assertSame('ok-nim/x', $output);
        $this->assertSame(['or/a', 'nim/x'], $calls);
        $failed = array_values(array_filter(
            $attempts,
            static fn (array $row): bool => ($row['result'] ?? '') === 'failed',
        ));
        $this->assertCount(1, $failed);
        $this->assertSame('connection_cooldown', $failed[0]['health_mutation'] ?? null);
        $this->assertSame(AiFailureScope::Connection->value, $failed[0]['failure_scope'] ?? null);
        $skipped = array_values(array_filter(
            $attempts,
            static fn (array $row): bool => ($row['result'] ?? '') === 'skipped'
                && ($row['skip_reason'] ?? '') === 'connection_suppressed',
        ));
        $this->assertCount(2, $skipped);
    }

    public function test_request_invalid_does_not_lock_connection_or_cooldown(): void
    {
        $this->seedPriorityProviders(81, [
            [ApiConnectionProviders::OPENROUTER, 'OpenRouter', ['or/a', 'or/b']],
        ]);
        $cands = $this->targets->eligibleCandidates(
            81,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 81),
        );
        $this->assertNotEmpty($cands);

        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 81, hookKey: 'article.outline.structure.generate'),
                static function (): array {
                    throw new PromptRunException('Provider API error (400): unsupported parameter temperature', 400);
                },
            );
            $this->fail('Expected PromptRunException');
        } catch (PromptRunException $e) {
            $this->assertStringContainsString('unsupported parameter', $e->getMessage());
        }

        $this->assertNull($this->health->skipReason(81, $cands[0]));
        foreach ($cands as $cand) {
            $this->assertNull($this->health->skipReason(81, $cand));
        }
    }

    public function test_transient_503_does_not_hard_disable_connection(): void
    {
        $this->seedPriorityProviders(82, [
            [ApiConnectionProviders::OPENROUTER, 'OpenRouter', ['or/a', 'or/b']],
        ]);
        $cands = $this->targets->eligibleCandidates(
            82,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 82),
        );
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 82, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'or/a') {
                    throw new PromptRunException('Provider API error (503): temporarily unavailable', 503);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['or/a', 'or/b'], $calls);
        $sibling = null;
        foreach ($cands as $cand) {
            if ($cand->model === 'or/b') {
                $sibling = $cand;
                break;
            }
        }
        $this->assertNotNull($sibling);
        $this->assertNull($this->health->skipReason(82, $sibling));
        $connRow = AiRuntimeHealthState::query()
            ->where('user_id', 82)
            ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
            ->where('subject_id', (int) $cands[0]->connection->id)
            ->first();
        $this->assertNotSame(AiRuntimeHealthStatus::ConnectionLocked->value, $connRow?->health_status);
        $this->assertTrue(
            $connRow === null
            || $connRow->cooldown_until === null
            || ! $connRow->cooldown_until->isFuture()
        );
    }

    public function test_parallel_jobs_share_connection_cooldown(): void
    {
        $this->seedPriorityProviders(83, [
            [ApiConnectionProviders::OPENROUTER, 'OpenRouter', ['or/a', 'or/b']],
            [ApiConnectionProviders::NVIDIA, 'NVIDIA NIM', ['nim/x']],
        ]);
        $job1Calls = [];
        [$out1] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 83, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$job1Calls): array {
                $job1Calls[] = $candidate->model;
                if (str_starts_with($candidate->model, 'or/')) {
                    throw new PromptRunException('Provider API error (400): Request path cannot be relayed', 400);
                }

                return ['ok-job1', null];
            },
        );
        $this->assertSame('ok-job1', $out1);
        $this->assertSame(['or/a', 'nim/x'], $job1Calls);

        $job2Calls = [];
        [, , , , , $attempts2] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 83, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$job2Calls): array {
                $job2Calls[] = $candidate->model;

                return ['ok-job2', null];
            },
        );
        $this->assertSame(['nim/x'], $job2Calls);
        $cooldownSkips = array_values(array_filter(
            $attempts2,
            static fn (array $row): bool => ($row['result'] ?? '') === 'skipped'
                && in_array($row['skip_reason'] ?? '', ['connection_cooldown', 'connection_suppressed'], true),
        ));
        $this->assertGreaterThanOrEqual(2, count($cooldownSkips));
    }

    public function test_soft_connection_failures_do_not_escalate_to_hard_lock(): void
    {
        $this->seedPriorityProviders(84, [
            [ApiConnectionProviders::OPENROUTER, 'OpenRouter', ['or/a']],
        ]);
        $cands = $this->targets->eligibleCandidates(
            84,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 84),
        );
        $decision = new AiFailureDecision(
            category: AiFailureClass::TransientProvider,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            safeMessage: 'Provider relay path unavailable.',
            httpStatus: 400,
            applyCooldown: true,
            lockConnection: false,
            affectsRuntimeHealth: true,
            failureStage: 'provider_http',
        );
        for ($i = 0; $i < 5; $i++) {
            $this->health->recordFailure(84, $cands[0], $decision);
        }
        $this->assertSame('connection_cooldown', $this->health->skipReason(84, $cands[0]));
        $row = AiRuntimeHealthState::query()
            ->where('user_id', 84)
            ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
            ->where('subject_id', (int) $cands[0]->connection->id)
            ->first();
        $this->assertNotSame(AiRuntimeHealthStatus::ConnectionLocked->value, $row?->health_status);
        $this->assertFalse((bool) $row?->manual_unlock_required);
    }

    public function test_cooldown_expiry_allows_bounded_probe_then_suppresses_siblings(): void
    {
        $this->seedPriorityProviders(85, [
            [ApiConnectionProviders::OPENROUTER, 'OpenRouter', ['or/a', 'or/b']],
            [ApiConnectionProviders::NVIDIA, 'NVIDIA NIM', ['nim/x']],
        ]);
        $cands = $this->targets->eligibleCandidates(
            85,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 85),
        );
        $orCand = null;
        foreach ($cands as $cand) {
            if (str_starts_with($cand->model, 'or/')) {
                $orCand = $cand;
                break;
            }
        }
        $this->assertNotNull($orCand);
        $this->health->recordFailure(85, $orCand, new AiFailureDecision(
            category: AiFailureClass::TransientProvider,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            safeMessage: 'Provider relay path unavailable.',
            httpStatus: 400,
            applyCooldown: true,
            affectsRuntimeHealth: true,
            failureStage: 'provider_http',
        ));
        $row = AiRuntimeHealthState::query()
            ->where('user_id', 85)
            ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
            ->where('subject_id', (int) $orCand->connection->id)
            ->first();
        $this->assertNotNull($row);
        $row->cooldown_until = now()->subSecond();
        $row->save();

        $first = $this->health->skipReason(85, $orCand);
        $this->assertNull($first);
        $sibling = null;
        foreach ($cands as $cand) {
            if ((int) $cand->connection->id === (int) $orCand->connection->id
                && $cand->model !== $orCand->model) {
                $sibling = $cand;
                break;
            }
        }
        $this->assertNotNull($sibling);
        $this->assertSame('connection_cooldown', $this->health->skipReason(85, $sibling));
    }

    public function test_inactive_connection_is_not_selected(): void
    {
        $ids = [];
        $active = ApiConnection::query()->create([
            'user_id' => 86,
            'provider' => ApiConnectionProviders::NVIDIA,
            'name' => 'NVIDIA NIM',
            'api_key' => 'nim-key-86',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $inactive = ApiConnection::query()->create([
            'user_id' => 86,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter inactive',
            'api_key' => 'or-key-86',
            'status' => 'inactive',
            'is_global' => false,
            'metadata' => [],
        ]);
        foreach ([['or/a', $inactive], ['nim/x', $active]] as [$raw, $conn]) {
            $model = $this->model($conn, $raw);
            $this->grant($conn, $model);
            $ids[] = (int) $model->id;
        }
        $this->priorities->appendToArea(86, AiModelArea::TextReasoning, $ids);

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 86, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['nim/x'], $calls);
    }

    public function test_connection_cooldown_does_not_change_user_priority_order(): void
    {
        $this->seedPriorityProviders(87, [
            [ApiConnectionProviders::OPENROUTER, 'OpenRouter', ['or/a']],
            [ApiConnectionProviders::NVIDIA, 'NVIDIA NIM', ['nim/x']],
        ]);
        $before = array_map(
            static fn ($c) => $c->model,
            $this->targets->eligibleCandidates(
                87,
                AiExecutionProfile::TextReasoning,
                new AiRoutingContext(userId: 87),
            ),
        );
        $this->assertSame(['or/a', 'nim/x'], $before);
        $beforeIds = array_map(
            static fn (SeoAiModel $m): int => (int) $m->id,
            $this->priorities->effectiveAreaModels(87, AiModelArea::TextReasoning),
        );
        $cands = $this->targets->eligibleCandidates(
            87,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 87),
        );
        $this->health->recordFailure(87, $cands[0], new AiFailureDecision(
            category: AiFailureClass::TransientProvider,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            safeMessage: 'Provider relay path unavailable.',
            httpStatus: 400,
            applyCooldown: true,
            affectsRuntimeHealth: true,
            failureStage: 'provider_http',
        ));
        $afterIds = array_map(
            static fn (SeoAiModel $m): int => (int) $m->id,
            $this->priorities->effectiveAreaModels(87, AiModelArea::TextReasoning),
        );
        $this->assertSame($beforeIds, $afterIds);
        $this->assertSame('active', (string) $cands[0]->connection->fresh()->status);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: list<string>}>  $providers
     */
    private function seedPriorityProviders(int $userId, array $providers): void
    {
        $ids = [];
        foreach ($providers as [$provider, $name, $models]) {
            if ($models === []) {
                continue;
            }
            $conn = ApiConnection::query()->create([
                'user_id' => $userId,
                'provider' => $provider,
                'name' => $name,
                'api_key' => 'test-key-'.$userId.'-'.$provider,
                'status' => 'active',
                'is_global' => false,
                'metadata' => [],
            ]);
            foreach ($models as $raw) {
                $model = $this->model($conn, $raw);
                $this->grant($conn, $model);
                $ids[] = (int) $model->id;
            }
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, $ids);
    }

    /**
     * Two distinct OpenRouter API connections — validates connection-id suppress (not model-only).
     *
     * @param  list<string>  $primaryModels
     * @param  list<string>  $fallbackModels
     */
    private function seedTwoOpenRouterConnections(int $userId, array $primaryModels, array $fallbackModels): void
    {
        $ids = [];
        foreach ([['OpenRouter A', $primaryModels], ['OpenRouter B', $fallbackModels]] as [$name, $models]) {
            if ($models === []) {
                continue;
            }
            $conn = ApiConnection::query()->create([
                'user_id' => $userId,
                'provider' => ApiConnectionProviders::OPENROUTER,
                'name' => $name,
                'api_key' => 'test-openrouter-key-'.$userId.'-'.$name,
                'status' => 'active',
                'is_global' => false,
                'metadata' => [],
            ]);
            foreach ($models as $raw) {
                $model = $this->model($conn, $raw);
                $this->grant($conn, $model);
                $ids[] = (int) $model->id;
            }
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, $ids);
    }

    /**
     * @param  list<string>  $orModels
     * @param  list<string>  $geminiModels
     */
    private function seedOrThenGemini(int $userId, array $orModels, array $geminiModels): void
    {
        $this->seedTwoOpenRouterConnections($userId, $orModels, $geminiModels === [] ? [] : array_map(
            static fn (string $m): string => str_starts_with($m, 'or') ? $m : 'fallback/'.$m,
            $geminiModels,
        ));
    }

    private function model(ApiConnection $connection, string $raw): SeoAiModel
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
                    'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
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
            'capability' => AiModelCapability::TextReasoning->value,
            'enabled' => true,
        ]);
        AiModelCapabilityRow::query()->create([
            'api_connection_id' => $connection->id,
            'seo_ai_model_id' => $model->id,
            'model_key' => $model->raw_model_name,
            'capability' => AiModelCapability::TextGenerate->value,
            'enabled' => true,
        ]);
    }
}
