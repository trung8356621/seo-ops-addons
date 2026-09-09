<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
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
use App\Models\ApiConnection;
use Tests\TestCase;

final class AiRuntimeFallbackTest extends TestCase
{
    private AiModelRouterService $router;

    private AiRuntimeHealthService $health;

    private AiRoutingTargetService $targets;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('ai_runtime_health_states');
        Schema::connection('mysql')->dropIfExists('ai_runtime_health_states');
        Schema::dropIfExists('wp_options');
        Schema::create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->boolean('paid_locked')->default(false);
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

        $registry = new ModelCapabilityRegistry();
        $priorities = new AiModelPriorityService();
        $this->targets = new AiRoutingTargetService($registry, priorities: $priorities);
        $bootstrap = new AiRoutingBootstrapService($registry, $this->targets);
        $this->health = new AiRuntimeHealthService(notifications: null);
        $this->router = new AiModelRouterService($registry, $this->targets, $bootstrap);

        $this->app->instance(AiProviderFailureClassifier::class, new AiProviderFailureClassifier());
        $this->app->instance(AiRuntimeHealthService::class, $this->health);
        $this->app->instance(AiResilienceSettingsService::class, new AiResilienceSettingsService());
    }

    public function test_402_then_fallback_success(): void
    {
        $this->seedTwoModelRoute(50, 'paid/claude', 'free/gemma:free', false, true);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 50),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'paid/claude') {
                    throw new PromptRunException('Provider API error (402): requires more credits', 402);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['paid/claude', 'free/gemma:free'], $calls);
    }

    public function test_second_call_skips_paid_locked_openrouter(): void
    {
        $this->seedTwoModelRoute(51, 'paid/claude', 'free/gemma:free', false, true);
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 51),
                fn ($candidate) => throw new PromptRunException('402', 402),
            );
        } catch (AiRoutesExhaustedException) {
        }

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 51),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok2', null];
            },
        );
        $this->assertSame('ok2', $output);
        $this->assertSame(['free/gemma:free'], $calls);
    }

    public function test_connection_paid_locked_preference_skips_paid_before_provider_call(): void
    {
        $this->seedTwoModelRoute(71, 'anthropic/claude-sonnet-4.6', 'google/gemma:free', false, true);
        $conn = ApiConnection::query()->where('user_id', 71)->firstOrFail();
        $conn->paid_locked = true;
        $conn->save();

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 71),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok-free-only', null];
            },
        );

        $this->assertSame('ok-free-only', $output);
        $this->assertSame(['google/gemma:free'], $calls);
        $this->assertNotContains('anthropic/claude-sonnet-4.6', $calls);
    }

    public function test_connection_paid_locked_does_not_fallback_to_paid_when_free_fails(): void
    {
        $this->seedTwoModelRoute(72, 'anthropic/claude-sonnet-4.6', 'google/gemma:free', false, true);
        $conn = ApiConnection::query()->where('user_id', 72)->firstOrFail();
        $conn->paid_locked = true;
        $conn->save();

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 72),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('free failed', 503);
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED without paid fallback');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(['google/gemma:free'], $calls);
            $this->assertNotContains('anthropic/claude-sonnet-4.6', $calls);
            $attempts = $exception->context['routing_attempts'] ?? [];
            $this->assertTrue(
                collect($attempts)->contains(
                    static fn (mixed $row): bool => is_array($row)
                        && ($row['model'] ?? '') === 'anthropic/claude-sonnet-4.6'
                        && ($row['skip_reason'] ?? '') === 'connection_paid_locked',
                ),
            );
        }
    }

    public function test_system_error_stops_without_next_candidate(): void
    {
        $conn = $this->connection(52, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $a = $this->model($conn, 'anthropic/claude-a', false);
        $b = $this->model($conn, 'google/gemini-b', false);
        $this->grantText($conn, $a);
        $this->grantText($conn, $b);
        app(AiModelPriorityService::class)->appendToArea(52, AiModelArea::TextLongform, [(int) $a->id, (int) $b->id]);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 52),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('invalid prompt hook definition');
                },
            );
            $this->fail('Expected system error to stop routing');
        } catch (PromptRunException $exception) {
            $this->assertSame(['anthropic/claude-a'], $calls);
            $this->assertStringContainsString('invalid prompt hook', strtolower($exception->getMessage()));
        }
    }

    public function test_max_ai_attempts_stops_before_success(): void
    {
        (new AiResilienceSettingsService())->save(53, ['max_ai_attempts' => 3, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(53, [
            ['a', 'anthropic/claude-sonnet-4.6', ApiConnectionProviders::OPENROUTER, false],
            ['b', 'google/gemini-2.5-flash', ApiConnectionProviders::OPENROUTER, false],
            ['c', 'openai/gpt-5.4', ApiConnectionProviders::OPENROUTER, false],
            ['d', 'deepseek/deepseek-chat', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 53),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('503 unavailable', 503);
                },
            );
            $this->fail('Expected routes exhausted');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame([
                'anthropic/claude-sonnet-4.6',
                'google/gemini-2.5-flash',
                'openai/gpt-5.4',
            ], $calls);
            $this->assertStringContainsString('AI_ROUTES_EXHAUSTED', $exception->getMessage());
        }
    }

    public function test_health_skip_does_not_consume_attempt_budget(): void
    {
        (new AiResilienceSettingsService())->save(54, ['max_ai_attempts' => 2, 'max_free_attempts' => 2]);
        $conn = $this->connection(54, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $paid = $this->model($conn, 'anthropic/claude-paid', false);
        $freeOk = $this->model($conn, 'google/gemma-3-12b-it:free', true);
        $this->grantText($conn, $paid);
        $this->grantText($conn, $freeOk);
        app(AiModelPriorityService::class)->appendToArea(54, AiModelArea::TextLongform, [(int) $paid->id, (int) $freeOk->id]);

        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 54),
                fn () => throw new PromptRunException('402', 402),
            );
        } catch (AiRoutesExhaustedException) {
        }

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 54),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['win', null];
            },
        );
        $this->assertSame('win', $output);
        $this->assertSame(['google/gemma-3-12b-it:free'], $calls);
    }

    public function test_503_then_fallback_success(): void
    {
        $this->seedTwoModelRoute(55, 'paid/claude', 'free/gemma:free', false, true);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 55),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'paid/claude') {
                    throw new PromptRunException('503 service unavailable', 503);
                }

                return ['recovered', null];
            },
        );
        $this->assertSame('recovered', $output);
        $this->assertSame(['paid/claude', 'free/gemma:free'], $calls);
    }

    public function test_schema_parse_error_falls_back_to_second_route(): void
    {
        $this->seedTwoModelRoute(56, 'paid/claude', 'free/gemma:free', false, true);
        $calls = [];
        [$output, , , , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 56),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'paid/claude') {
                    throw new PromptRunException('Planner structured output invalid after repair (schema validation failed)');
                }

                return ["# Valid markdown\n\nBody.", null];
            },
        );
        $this->assertSame("# Valid markdown\n\nBody.", $output);
        $this->assertSame(['paid/claude', 'free/gemma:free'], $calls);
        $this->assertSame('failed', $routingAttempts[0]['result'] ?? null);
        $this->assertSame('success', $routingAttempts[1]['result'] ?? null);
    }

    public function test_provider_empty_content_falls_back_to_next_candidate(): void
    {
        $this->seedTwoModelRoute(59, 'model/a', 'model/b', false, false);
        $calls = [];
        [$output, , , , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 59),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'model/a') {
                    throw new PromptRunException('Provider returned empty content.');
                }

                return ["# Outline from B\n\n## Section", null];
            },
        );

        $this->assertSame(['model/a', 'model/b'], $calls);
        $this->assertSame("# Outline from B\n\n## Section", $output);
        $this->assertSame('failed', $routingAttempts[0]['result'] ?? null);
        $this->assertSame('provider_empty_output', $routingAttempts[0]['failure_class'] ?? null);
        $this->assertSame('success', $routingAttempts[1]['result'] ?? null);
    }

    public function test_empty_refusal_invalid_then_success_records_routing_attempts(): void
    {
        (new AiResilienceSettingsService())->save(60, ['max_ai_attempts' => 4, 'max_free_attempts' => 4]);
        $this->seedOrderedLongform(60, [
            ['a', 'model/a', ApiConnectionProviders::OPENROUTER, false],
            ['b', 'model/b', ApiConnectionProviders::OPENROUTER, false],
            ['c', 'model/c', ApiConnectionProviders::OPENROUTER, false],
            ['d', 'model/d', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output, , , , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 60),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                return match ($candidate->model) {
                    'model/a' => throw new PromptRunException('Provider returned empty content.'),
                    'model/b' => throw new PromptRunException('Model refused due to safety content policy'),
                    'model/c' => throw new PromptRunException('Provider output invalid: malformed JSON'),
                    default => ["# Success from D\n\nOK", null],
                };
            },
        );

        $this->assertSame(['model/a', 'model/b', 'model/c', 'model/d'], $calls);
        $this->assertSame("# Success from D\n\nOK", $output);
        $this->assertCount(4, $routingAttempts);
        $this->assertSame('failed', $routingAttempts[0]['result']);
        $this->assertSame('failed', $routingAttempts[1]['result']);
        $this->assertSame('failed', $routingAttempts[2]['result']);
        $this->assertSame('success', $routingAttempts[3]['result']);
    }

    public function test_provider_output_failures_exhaust_routes(): void
    {
        (new AiResilienceSettingsService())->save(61, ['max_ai_attempts' => 3, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(61, [
            ['a', 'model/a', ApiConnectionProviders::OPENROUTER, false],
            ['b', 'model/b', ApiConnectionProviders::OPENROUTER, false],
            ['c', 'model/c', ApiConnectionProviders::OPENROUTER, false],
            ['d', 'model/d', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 61),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    return match ($candidate->model) {
                        'model/a' => throw new PromptRunException('Provider returned empty content.'),
                        'model/b' => throw new PromptRunException('Model refused due to safety'),
                        default => throw new PromptRunException('Provider output invalid: truncated response'),
                    };
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(['model/a', 'model/b', 'model/c'], $calls);
            $this->assertSame(3, $exception->context['attempt_count'] ?? null);
            $attempts = $exception->context['routing_attempts'] ?? [];
            $this->assertCount(3, $attempts);
            $this->assertStringContainsString('AI_ROUTES_EXHAUSTED', $exception->getMessage());
        }
    }

    public function test_outline_then_vocabulary_empty_fallback_acceptance(): void
    {
        // Acceptance: Outline A empty→B ok; Vocabulary A empty→B ok (two independent route cycles).
        $this->seedTwoModelRoute(62, 'outline/a', 'outline/b', false, false);
        $outlineCalls = [];
        [$outlineOut, , , , , $outlineAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 62),
            function ($candidate) use (&$outlineCalls): array {
                $outlineCalls[] = $candidate->model;
                if ($candidate->model === 'outline/a') {
                    throw new PromptRunException('Provider returned empty content.');
                }

                return ["# Outline markdown\n\n## Intro", null];
            },
        );
        $this->assertSame(['outline/a', 'outline/b'], $outlineCalls);
        $this->assertStringContainsString('# Outline markdown', $outlineOut);
        $this->assertSame('failed', $outlineAttempts[0]['result']);
        $this->assertSame('success', $outlineAttempts[1]['result']);

        $this->seedTwoModelRoute(63, 'vocab/a', 'vocab/b', false, false);
        $vocabCalls = [];
        [$vocabOut, , , , , $vocabAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 63),
            function ($candidate) use (&$vocabCalls): array {
                $vocabCalls[] = $candidate->model;
                if ($candidate->model === 'vocab/a') {
                    throw new PromptRunException('Provider returned empty content.');
                }

                return ["### Holonymy\n- bag\n", null];
            },
        );
        $this->assertSame(['vocab/a', 'vocab/b'], $vocabCalls);
        $this->assertStringContainsString('Holonymy', $vocabOut);
        $this->assertSame('failed', $vocabAttempts[0]['result']);
        $this->assertSame('success', $vocabAttempts[1]['result']);
        $this->assertStringNotContainsString('Provider returned empty content', $vocabOut);
    }

    public function test_context_length_stops_without_exhausting_routes(): void
    {
        $this->seedTwoModelRoute(57, 'paid/claude', 'free/gemma:free', false, true);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 57),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('This model\'s maximum context length was exceeded', 400);
                },
            );
            $this->fail('Expected context limit stop');
        } catch (PromptRunException $exception) {
            $this->assertSame(['paid/claude'], $calls);
            $this->assertStringContainsString('context length', strtolower($exception->getMessage()));
        }
    }

    public function test_business_false_style_error_does_not_call_second_route(): void
    {
        // Global regression: article-quality / business false must not burn the AI chain.
        $this->seedTwoModelRoute(58, 'paid/claude', 'free/gemma:free', false, true);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 58),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException(
                        'Content quality rejected: unexpected_script',
                        0,
                        null,
                        [
                            'classification' => \Omnichannel\Addons\AiPrompt\Support\AiFailureClass::OutputQuality->value,
                            'retryable' => false,
                        ],
                    );
                },
            );
            $this->fail('Expected output quality stop');
        } catch (PromptRunException $exception) {
            $this->assertSame(['paid/claude'], $calls);
            $decision = (new AiProviderFailureClassifier())->classify($exception);
            $this->assertFalse($decision->fallbackAllowed());
        }
    }

    public function test_mixed_free_paid_allows_configured_max_free_then_paid(): void
    {
        (new AiResilienceSettingsService())->save(80, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(80, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'free/c:free', ApiConnectionProviders::OPENROUTER, true],
            ['d', 'paid/gpt', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output, , , , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 80),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if (str_starts_with($candidate->model, 'free/')) {
                    throw new PromptRunException('503 unavailable', 503);
                }

                return ['paid-ok', null];
            },
        );
        $this->assertSame('paid-ok', $output);
        $this->assertSame(['free/a:free', 'free/b:free', 'free/c:free', 'paid/gpt'], $calls);
        $success = collect($routingAttempts)->firstWhere('result', 'success');
        $this->assertSame(3, $success['free_attempts'] ?? null);
        $this->assertSame(4, $success['actual_attempts'] ?? null);
        $this->assertSame(1, $success['reserved_paid_slots'] ?? null);
        $this->assertNotContains('free_oneshot_paid_available', array_column(
            array_filter($routingAttempts, static fn (array $r): bool => ($r['result'] ?? '') === 'skipped'),
            'skip_reason',
        ));
    }

    public function test_mixed_free_paid_stops_after_first_free_success(): void
    {
        (new AiResilienceSettingsService())->save(81, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(81, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'paid/gpt', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 81),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['free-ok', null];
            },
        );
        $this->assertSame('free-ok', $output);
        $this->assertSame(['free/a:free'], $calls);
    }

    public function test_mixed_free_paid_second_free_success_skips_paid(): void
    {
        (new AiResilienceSettingsService())->save(82, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(82, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'paid/gpt', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 82),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'free/a:free') {
                    throw new PromptRunException('503 unavailable', 503);
                }

                return ['free-b-ok', null];
            },
        );
        $this->assertSame('free-b-ok', $output);
        $this->assertSame(['free/a:free', 'free/b:free'], $calls);
        $this->assertNotContains('paid/gpt', $calls);
    }

    public function test_mixed_free_paid_health_skip_does_not_consume_free_retry(): void
    {
        (new AiResilienceSettingsService())->save(83, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(83, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'free/c:free', ApiConnectionProviders::OPENROUTER, true],
            ['d', 'paid/gpt', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $freeA = $this->targets->eligibleCandidates(
            83,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 83),
        )[0];
        $this->assertSame('free/a:free', $freeA->model);
        $this->health->recordFailure(83, $freeA, new AiFailureDecision(
            category: AiFailureClass::RateLimited,
            scope: AiFailureScope::Model,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            safeMessage: '429',
            httpStatus: 429,
            applyCooldown: true,
            affectsRuntimeHealth: true,
            failureStage: 'provider',
        ));
        $this->assertSame('model_cooldown', $this->health->skipReason(83, $freeA));

        $calls = [];
        [$output, , , , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 83),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if (str_starts_with($candidate->model, 'free/')) {
                    throw new PromptRunException('503 unavailable', 503);
                }

                return ['paid-ok', null];
            },
        );
        $this->assertSame('paid-ok', $output);
        $this->assertSame(['free/b:free', 'free/c:free', 'paid/gpt'], $calls);
        $this->assertSame('model_cooldown', $routingAttempts[0]['skip_reason'] ?? null);
    }

    public function test_mixed_free_paid_honors_max_free_attempts_one(): void
    {
        (new AiResilienceSettingsService())->save(84, ['max_ai_attempts' => 6, 'max_free_attempts' => 1]);
        $this->seedOrderedLongform(84, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'paid/gpt', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 84),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if (str_starts_with($candidate->model, 'free/')) {
                    throw new PromptRunException('503 unavailable', 503);
                }

                return ['paid-ok', null];
            },
        );
        $this->assertSame('paid-ok', $output);
        $this->assertSame(['free/a:free', 'paid/gpt'], $calls);
        $this->assertNotContains('free/b:free', $calls);
    }

    public function test_free_only_path_keeps_full_max_free_attempts(): void
    {
        (new AiResilienceSettingsService())->save(85, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(85, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'free/c:free', ApiConnectionProviders::OPENROUTER, true],
            ['d', 'free/d:free', ApiConnectionProviders::OPENROUTER, true],
        ]);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 85),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('503 unavailable', 503);
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException) {
            $this->assertSame(['free/a:free', 'free/b:free', 'free/c:free'], $calls);
            $this->assertNotContains('free/d:free', $calls);
        }
    }

    /** TEST A / G — free exhausted (max_free=3) then paid success; no hardcap-2 / oneshot */
    public function test_a_free_retry_exhaustion_falls_back_to_deepseek_success(): void
    {
        (new AiResilienceSettingsService())->save(90, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(90, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'free/c:free', ApiConnectionProviders::OPENROUTER, true],
            ['d', 'deepseek/deepseek-chat', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output, , $selected, , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 90),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw new PromptRunException('429 rate limit exceeded: free-models-per-min', 429);
                }

                return ['deepseek-ok', ['resolved_model' => $candidate->model]];
            },
        );
        $this->assertSame('deepseek-ok', $output);
        $this->assertSame('deepseek/deepseek-chat', $selected->model);
        $this->assertSame(['free/a:free', 'free/b:free', 'free/c:free', 'deepseek/deepseek-chat'], $calls);
        $success = collect($routingAttempts)->firstWhere('result', 'success');
        $this->assertSame(3, $success['free_attempts'] ?? null);
        $this->assertSame(4, $success['actual_attempts'] ?? null);
        $this->assertSame(1, $success['paid_attempts'] ?? null);
        $skipReasons = array_column(
            array_filter($routingAttempts, static fn (array $r): bool => ($r['result'] ?? '') === 'skipped'),
            'skip_reason',
        );
        $this->assertNotContains('free_oneshot_paid_available', $skipReasons);
    }

    /** TEST B — 429 free does not block DeepSeek on same connection */
    public function test_b_429_free_does_not_connection_suppress_deepseek(): void
    {
        (new AiResilienceSettingsService())->save(91, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $conn = $this->connection(91, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $free = $this->model($conn, 'meta/llama:free', true);
        $deepseek = $this->model($conn, 'deepseek/deepseek-chat', false);
        $this->grantText($conn, $free);
        $this->grantText($conn, $deepseek);
        app(AiModelPriorityService::class)->appendToArea(91, AiModelArea::TextLongform, [(int) $free->id, (int) $deepseek->id]);

        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 91),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw new PromptRunException('Rate limit exceeded: free-models-per-day. quota exceeded', 429);
                }

                return ['ok', null];
            },
        );
        $this->assertSame(['meta/llama:free', 'deepseek/deepseek-chat'], $calls);
        $this->assertSame('deepseek/deepseek-chat', $selected->model);
        $this->assertSame('ok', $output);
        // Free 429 must not leave connection cooldown that blocks paid.
        $paidCandidate = new \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $conn,
            provider: ApiConnectionProviders::OPENROUTER,
            model: 'deepseek/deepseek-chat',
            capabilities: [],
            priority: 1,
            options: [],
            seoAiModelId: (int) $deepseek->id,
            legacyFallback: false,
            isFree: false,
        );
        $this->assertNull($this->health->skipReason(91, $paidCandidate));
    }

    /** TEST C — 402 locks paid lane only; free/other connection still attemptable */
    public function test_c_402_paid_lock_does_not_block_other_connection(): void
    {
        (new AiResilienceSettingsService())->save(92, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $connA = $this->connection(92, ApiConnectionProviders::OPENROUTER, 'OR-A');
        $connB = $this->connection(92, 'deepseek', 'DeepSeek');
        $paidA = $this->model($connA, 'openrouter/paid-a', false);
        $paidB = $this->model($connB, 'deepseek/deepseek-chat', false);
        $this->grantText($connA, $paidA);
        $this->grantText($connB, $paidB);
        app(AiModelPriorityService::class)->appendToArea(92, AiModelArea::TextLongform, [(int) $paidA->id, (int) $paidB->id]);

        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 92),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'openrouter/paid-a') {
                    throw new PromptRunException('Provider API error (402): requires more credits', 402);
                }

                return ['b-ok', null];
            },
        );
        $this->assertSame(['openrouter/paid-a', 'deepseek/deepseek-chat'], $calls);
        $this->assertSame('deepseek/deepseek-chat', $selected->model);
        $this->assertSame('b-ok', $output);
    }

    /** TEST D — model order respected */
    public function test_d_candidate_order_respects_ai_center_priority(): void
    {
        (new AiResilienceSettingsService())->save(93, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(93, [
            ['d', 'deepseek/deepseek-chat', ApiConnectionProviders::OPENROUTER, false],
            ['f', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['g', 'google/gemini', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 93),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['first-wins', null];
            },
        );
        $this->assertSame(['deepseek/deepseek-chat'], $calls);
        $this->assertSame('first-wins', $output);
    }

    /** TEST E — Outline split is route_cost_auto; PromptBudget ≠ Outline Split */
    public function test_e_outline_split_prompt_off_does_not_use_writing_multiple_pass(): void
    {
        $runner = file_get_contents(
            (string) (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner::class))->getFileName(),
        ) ?: '';
        $executor = file_get_contents(
            (string) (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor::class))->getFileName(),
        ) ?: '';
        $this->assertStringContainsString('outlineSplitExecutor->execute', $runner);
        $this->assertStringContainsString('isOutlineSplitEnabled', $runner);
        $this->assertStringContainsString('GenerationShapeResolver', $runner);
        $this->assertStringContainsString('ensureRouteCostGenerationShapeSnapshot', $runner);
        $this->assertStringNotContainsString('WritingMultiplePassStepPlanner', $executor);
        $this->assertStringNotContainsString('writing_split_enabled', $executor);
        $this->assertStringNotContainsString('SectionedFreeHookOrchestrator', $executor);
        // PromptBudget article.outline stays DirectFit (no LongForm split) — unrelated to Outline Split feature.
        $registry = new \Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry();
        $this->assertFalse($registry->forHook('article.outline.structure.generate')->supportsSplit());
        $settingsSrc = file_get_contents(
            (string) (new \ReflectionClass(\Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService::class))->getFileName(),
        ) ?: '';
        $this->assertStringContainsString("KEY_OUTLINE_SPLIT_ENABLED = 'outline_split_enabled'", $settingsSrc);
        $this->assertStringContainsString('function isOutlineSplitEnabled', $settingsSrc);
    }

    /** TEST F — one candidate retry exhaustion != route exhaustion */
    public function test_f_one_candidate_failure_continues_to_next_success(): void
    {
        (new AiResilienceSettingsService())->save(94, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(94, [
            ['a', 'paid/model-a', ApiConnectionProviders::OPENROUTER, false],
            ['b', 'paid/model-b', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 94),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'paid/model-a') {
                    throw new PromptRunException('503 unavailable', 503);
                }

                return ['b-ok', null];
            },
        );
        $this->assertSame(['paid/model-a', 'paid/model-b'], $calls);
        $this->assertSame('paid/model-b', $selected->model);
        $this->assertSame('b-ok', $output);
    }

    /** TEST G — all candidates exhausted with clear skip/fail trace */
    public function test_g_all_candidates_exhausted_keeps_trace(): void
    {
        (new AiResilienceSettingsService())->save(95, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(95, [
            ['a', 'paid/model-a', ApiConnectionProviders::OPENROUTER, false],
            ['b', 'paid/model-b', ApiConnectionProviders::OPENROUTER, false],
            ['c', 'paid/model-c', ApiConnectionProviders::OPENROUTER, false],
        ]);
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 95),
                function ($candidate): array {
                    throw new PromptRunException('503 unavailable', 503);
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException $e) {
            $attempts = $e->context['routing_attempts'] ?? [];
            $this->assertCount(3, $attempts);
            foreach ($attempts as $row) {
                $this->assertSame('failed', $row['result'] ?? null);
                $this->assertArrayHasKey('actual_attempts', $row);
                $this->assertArrayHasKey('is_free', $row);
            }
            $this->assertSame(3, $e->context['attempt_count'] ?? null);
        }
    }

    /** Free attempts must not consume entire max_ai before paid when budget is tight */
    public function test_free_budget_reserves_slot_for_paid_when_max_ai_is_tight(): void
    {
        (new AiResilienceSettingsService())->save(96, ['max_ai_attempts' => 2, 'max_free_attempts' => 2]);
        $this->seedOrderedLongform(96, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'deepseek/deepseek-chat', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 96),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw new PromptRunException('503', 503);
                }

                return ['paid-ok', null];
            },
        );
        $this->assertSame('paid-ok', $output);
        $this->assertSame(['free/a:free', 'deepseek/deepseek-chat'], $calls);
    }

    /** TEST H — paid exists but paid_locked: do not reserve useless paid slot */
    public function test_h_paid_locked_does_not_reserve_free_budget_slot(): void
    {
        (new AiResilienceSettingsService())->save(97, ['max_ai_attempts' => 3, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(97, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'free/c:free', ApiConnectionProviders::OPENROUTER, true],
            ['d', 'paid/gpt', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $paid = $this->targets->eligibleCandidates(
            97,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 97),
        );
        $paidCandidate = collect($paid)->first(static fn ($c) => ! $c->isFree);
        $this->assertNotNull($paidCandidate);
        $this->health->recordFailure(97, $paidCandidate, new AiFailureDecision(
            category: AiFailureClass::InsufficientBudgetForRequest,
            scope: AiFailureScope::ConnectionPaid,
            recoverable: false,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::BudgetLimited,
            safeMessage: '402 payment required',
            httpStatus: 402,
            applyCooldown: false,
            affectsRuntimeHealth: true,
            lockConnectionPaid: true,
            failureStage: 'provider',
        ));
        $this->assertSame('connection_paid_locked', $this->health->skipReason(97, $paidCandidate));

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 97),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('503 unavailable', 503);
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException $e) {
            $this->assertSame(['free/a:free', 'free/b:free', 'free/c:free'], $calls);
            $this->assertSame(3, $e->context['free_attempts'] ?? null);
            $this->assertSame(0, $e->context['reserved_paid_slots'] ?? null);
        }
    }

    /** TEST I — reclaim reserved paid slot when paid becomes unattemptable mid-run */
    public function test_i_reclaims_reserved_paid_slot_when_paid_health_skipped(): void
    {
        (new AiResilienceSettingsService())->save(98, ['max_ai_attempts' => 4, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(98, [
            ['a', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['p', 'paid/gpt', ApiConnectionProviders::OPENROUTER, false],
            ['b', 'free/b:free', ApiConnectionProviders::OPENROUTER, true],
            ['c', 'free/c:free', ApiConnectionProviders::OPENROUTER, true],
        ]);
        $paid = collect($this->targets->eligibleCandidates(
            98,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 98),
        ))->first(static fn ($c) => ! $c->isFree);
        $this->assertNotNull($paid);

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 98),
                function ($candidate) use (&$calls, $paid): array {
                    $calls[] = $candidate->model;
                    if ($candidate->model === 'free/a:free') {
                        // After first free fail, lock paid so remaining free reclaim the reserved slot.
                        $this->health->recordFailure(98, $paid, new AiFailureDecision(
                            category: AiFailureClass::InsufficientBudgetForRequest,
                            scope: AiFailureScope::ConnectionPaid,
                            recoverable: false,
                            runtimeAction: AiFailureRuntimeAction::Continue,
                            healthStatus: AiRuntimeHealthStatus::BudgetLimited,
                            safeMessage: '402',
                            httpStatus: 402,
                            applyCooldown: false,
                            affectsRuntimeHealth: true,
                            lockConnectionPaid: true,
                            failureStage: 'provider',
                        ));
                        throw new PromptRunException('503', 503);
                    }
                    if ($candidate->isFree) {
                        throw new PromptRunException('503', 503);
                    }

                    return ['should-not-reach-paid', null];
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED after reclaiming free budget');
        } catch (AiRoutesExhaustedException $e) {
            $routingAttempts = $e->context['routing_attempts'] ?? [];
            $skipReasons = array_column(
                array_filter($routingAttempts, static fn (array $r): bool => ($r['result'] ?? '') === 'skipped'),
                'skip_reason',
            );
            $this->assertContains('connection_paid_locked', $skipReasons);
            $this->assertContains('free/b:free', $calls);
            $this->assertContains('free/c:free', $calls);
            $this->assertNotContains('paid/gpt', $calls);
            $this->assertGreaterThanOrEqual(3, count(array_filter($calls, static fn (string $m): bool => str_starts_with($m, 'free/'))));
            $this->assertSame(0, $e->context['reserved_paid_slots'] ?? null);
        }
    }

    /** TEST J — legacy RateLimited connection cooldown must not block DeepSeek */
    public function test_j_legacy_rate_limited_connection_cooldown_does_not_block_deepseek(): void
    {
        (new AiResilienceSettingsService())->save(99, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $conn = $this->connection(99, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $free = $this->model($conn, 'meta/llama:free', true);
        $deepseek = $this->model($conn, 'deepseek/deepseek-chat', false);
        $this->grantText($conn, $free);
        $this->grantText($conn, $deepseek);
        app(AiModelPriorityService::class)->appendToArea(99, AiModelArea::TextLongform, [(int) $free->id, (int) $deepseek->id]);

        // Persist legacy poison: connection cooldown attributed to RateLimited.
        \Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState::query()->create([
            'user_id' => 99,
            'subject_type' => \Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState::SUBJECT_CONNECTION,
            'subject_id' => (int) $conn->id,
            'api_connection_id' => (int) $conn->id,
            'health_status' => AiRuntimeHealthStatus::Degraded->value,
            'cooldown_until' => now()->addMinutes(10),
            'last_failure_class' => AiFailureClass::RateLimited->value,
            'last_failure_message' => 'legacy 429 connection cooldown',
            'failure_counts' => [
                'last_scope' => AiFailureScope::Connection->value,
                'last_category' => AiFailureClass::RateLimited->value,
            ],
            'total_attempts' => 1,
            'failure_count' => 1,
            'consecutive_failures' => 1,
            'paid_locked' => false,
            'manual_unlock_required' => false,
        ]);

        $paidCandidate = new \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $conn,
            provider: ApiConnectionProviders::OPENROUTER,
            model: 'deepseek/deepseek-chat',
            capabilities: [],
            priority: 1,
            options: [],
            seoAiModelId: (int) $deepseek->id,
            legacyFallback: false,
            isFree: false,
        );
        $this->assertNull($this->health->skipReason(99, $paidCandidate));

        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 99),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame(['meta/llama:free'], $calls);
        $this->assertSame('ok', $output);
        $this->assertSame('meta/llama:free', $selected->model);
    }

    /** TEST N — FREE-first order is not Free Only; paid still fallbacks */
    public function test_n_free_first_order_still_allows_paid_fallback(): void
    {
        (new AiResilienceSettingsService())->save(100, ['max_ai_attempts' => 6, 'max_free_attempts' => 1]);
        $this->seedOrderedLongform(100, [
            ['f', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['d', 'deepseek/deepseek-chat', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 100, freeOnly: false),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw new PromptRunException('503', 503);
                }

                return ['paid-ok', null];
            },
        );
        $this->assertSame(['free/a:free', 'deepseek/deepseek-chat'], $calls);
        $this->assertSame('deepseek/deepseek-chat', $selected->model);
        $this->assertSame('paid-ok', $output);
    }

    /** TEST N2 — explicit freeOnly blocks paid */
    public function test_n_explicit_free_only_blocks_paid(): void
    {
        (new AiResilienceSettingsService())->save(101, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(101, [
            ['f', 'free/a:free', ApiConnectionProviders::OPENROUTER, true],
            ['d', 'deepseek/deepseek-chat', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 101, freeOnly: true),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('503', 503);
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException) {
            $this->assertSame(['free/a:free'], $calls);
            $this->assertNotContains('deepseek/deepseek-chat', $calls);
        }
    }

    /** TEST O — 0 actual attempts must not say "N AI attempts failed" */
    public function test_o_zero_actual_attempts_message_is_honest(): void
    {
        (new AiResilienceSettingsService())->save(102, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedOrderedLongform(102, [
            ['a', 'paid/model-a', ApiConnectionProviders::OPENROUTER, false],
            ['b', 'paid/model-b', ApiConnectionProviders::OPENROUTER, false],
        ]);
        $candidates = $this->targets->eligibleCandidates(
            102,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 102),
        );
        foreach ($candidates as $candidate) {
            $this->health->recordFailure(102, $candidate, new AiFailureDecision(
                category: AiFailureClass::ModelNotFound,
                scope: AiFailureScope::Model,
                recoverable: false,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::Unavailable,
                safeMessage: '404',
                httpStatus: 404,
                applyCooldown: false,
                affectsRuntimeHealth: true,
                markModelUnavailable: true,
                failureStage: 'provider',
            ));
        }

        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 102),
                function (): array {
                    $this->fail('Provider must not be called');
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException $e) {
            $this->assertSame(0, $e->context['attempt_count'] ?? null);
            $this->assertStringNotContainsString('AI attempt(s) failed', $e->getMessage());
            $this->assertTrue(
                str_contains($e->getMessage(), 'No attemptable AI routes')
                || str_contains($e->getMessage(), 'All eligible routes marked unavailable'),
            );
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: bool}>  $rows
     */
    private function seedOrderedLongform(int $userId, array $rows): void
    {
        $ids = [];
        foreach ($rows as [$name, $raw, $provider, $free]) {
            $connection = $this->connection($userId, $provider, $name);
            $model = $this->model($connection, $raw, $free);
            $this->grantText($connection, $model);
            $ids[] = (int) $model->id;
        }
        app(AiModelPriorityService::class)->appendToArea($userId, AiModelArea::TextLongform, $ids);
    }

    private function seedTwoModelRoute(int $userId, string $paid, string $free, bool $paidIsFree, bool $freeIsFree): void
    {
        $conn = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $paidModel = $this->model($conn, $paid, $paidIsFree);
        $freeModel = $this->model($conn, $free, $freeIsFree);
        $this->grantText($conn, $paidModel);
        $this->grantText($conn, $freeModel);
        app(AiModelPriorityService::class)->appendToArea($userId, AiModelArea::TextLongform, [(int) $paidModel->id, (int) $freeModel->id]);
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'test-key',
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
                        : ['prompt' => '0.000001', 'completion' => '0.000002'],
                    'architecture' => ['modality' => 'text->text'],
                ],
            ],
        ]);
    }

    private function grantText(ApiConnection $connection, SeoAiModel $model): void
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
