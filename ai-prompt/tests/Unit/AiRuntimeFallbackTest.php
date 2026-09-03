<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use App\Models\ApiConnection;
use Tests\TestCase;

final class AiRuntimeFallbackTest extends TestCase
{
    private AiModelRouterService $router;

    private AiRuntimeHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections'] as $table) {
            Schema::dropIfExists($table);
        }
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
        $targets = new AiRoutingTargetService($registry, priorities: $priorities);
        $bootstrap = new AiRoutingBootstrapService($registry, $targets);
        $this->health = new AiRuntimeHealthService(notifications: null);
        $this->router = new AiModelRouterService($registry, $targets, $bootstrap);

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

    public function test_schema_parse_error_stops_without_second_route(): void
    {
        $this->seedTwoModelRoute(56, 'paid/claude', 'free/gemma:free', false, true);
        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 56),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('Planner structured output invalid after repair (schema validation failed)');
                },
            );
            $this->fail('Expected parse/schema stop');
        } catch (PromptRunException $exception) {
            $this->assertSame(['paid/claude'], $calls);
            $this->assertStringContainsString('schema validation', strtolower($exception->getMessage()));
            $this->assertStringNotContainsString('AI_ROUTES_EXHAUSTED', $exception->getMessage());
        }
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
