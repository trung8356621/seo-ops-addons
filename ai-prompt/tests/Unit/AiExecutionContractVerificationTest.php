<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingContextResolver;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class AiExecutionContractVerificationTest extends TestCase
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

    public function test_preferred_model_allows_fallback_to_next_logical_model(): void
    {
        $userId = 301;
        $conn = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $preferred = $this->model($conn, 'deepseek/deepseek-chat', false);
        $fallback = $this->model($conn, 'google/gemini-2.0-flash', false);
        $this->grant($conn, $preferred);
        $this->grant($conn, $fallback);
        $this->priorities->appendToArea($userId, AiModelArea::TextLongform, [(int) $preferred->id, (int) $fallback->id]);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();

        $resolver = new AiRoutingContextResolver();
        $preferredCtx = new AiRoutingContext(
            userId: $userId,
            preferredModelId: (int) $preferred->id,
            requirePreferredModel: false,
            hookKey: 'article.content.generate',
        );
        $this->assertNotSame(
            AiExecutionRoutingMode::ExplicitModel,
            $resolver->resolveMode($preferredCtx),
            'preferred (non-required) must not use ExplicitModel hard-stop mode',
        );

        $requiredCtx = new AiRoutingContext(
            userId: $userId,
            preferredModelId: (int) $preferred->id,
            requirePreferredModel: true,
            hookKey: 'article.content.generate',
        );
        $this->assertSame(AiExecutionRoutingMode::ExplicitModel, $resolver->resolveMode($requiredCtx));

        $calls = [];
        [$output, , $winner, , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            $preferredCtx,
            function ($candidate) use (&$calls, $preferred): array {
                $calls[] = $candidate->model;
                if ((int) ($candidate->seoAiModelId ?? 0) === (int) $preferred->id) {
                    throw new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException('503 unavailable', 503);
                }

                return ['fallback-ok', null];
            },
        );

        $this->assertSame('fallback-ok', $output);
        $this->assertSame(['deepseek/deepseek-chat', 'google/gemini-2.0-flash'], $calls);
        $this->assertSame('google/gemini-2.0-flash', $winner->model);
        $this->assertSame('failed', $attempts[0]['result'] ?? null);
        $this->assertSame('success', $attempts[1]['result'] ?? null);
    }

    public function test_required_model_forbids_cross_logical_model_fallback(): void
    {
        $userId = 302;
        $conn = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $required = $this->model($conn, 'deepseek/deepseek-chat', false);
        $other = $this->model($conn, 'google/gemini-2.0-flash', false);
        $this->grant($conn, $required);
        $this->grant($conn, $other);
        $this->priorities->appendToArea($userId, AiModelArea::TextLongform, [(int) $required->id, (int) $other->id]);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(
                    userId: $userId,
                    preferredModelId: (int) $required->id,
                    requirePreferredModel: true,
                    hookKey: 'article.content.generate',
                ),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException('503 unavailable', 503);
                },
            );
            $this->fail('Expected exhaustion without fallback model');
        } catch (\Throwable) {
            $this->assertSame(['deepseek/deepseek-chat'], $calls);
            $this->assertNotContains('google/gemini-2.0-flash', $calls);
        }
    }

    public function test_min_word_validation_failure_continues_to_next_route_without_health_poison(): void
    {
        $userId = 303;
        $conn = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $modelA = $this->model($conn, 'openrouter/model-a', false);
        $modelB = $this->model($conn, 'openrouter/model-b', false);
        $this->grant($conn, $modelA);
        $this->grant($conn, $modelB);
        $this->priorities->appendToArea($userId, AiModelArea::TextLongform, [(int) $modelA->id, (int) $modelB->id]);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();
        (new AiRoutingBootstrapService(new ModelCapabilityRegistry(), $this->targets))->bootstrapForUser($userId);

        $candidates = $this->router->resolveAll(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: $userId, hookKey: 'article.content.generate'),
        );
        $this->assertGreaterThanOrEqual(2, count($candidates), 'seed must yield two longform candidates');

        $decision = (new AiProviderFailureClassifier())->classify(
            new OutputTruncated('Output shorter than minimum_length (434 words < 501 words).'),
        );
        $this->assertTrue($decision->shouldContinueRouting());
        $this->assertFalse($decision->affectsRuntimeHealth);
        $this->assertSame('validation', $decision->failureStage);

        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: $userId, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls, $modelA): array {
                $calls[] = $candidate->model;
                if ((int) ($candidate->seoAiModelId ?? 0) === (int) $modelA->id) {
                    throw new OutputTruncated('Output shorter than minimum_length (434 words < 501 words).');
                }

                return ['long-enough-ok', null];
            },
        );

        $this->assertSame('long-enough-ok', $output);
        $this->assertCount(2, $calls);
        $this->assertSame('failed', $attempts[0]['result'] ?? null);
        $this->assertSame('success', $attempts[1]['result'] ?? null);
        $connHealth = AiRuntimeHealthState::query()
            ->where('user_id', $userId)
            ->where('subject_type', AiRuntimeHealthState::SUBJECT_CONNECTION)
            ->where('subject_id', (int) $conn->id)
            ->first();
        $this->assertTrue(
            $connHealth === null
            || (int) ($connHealth->failure_count ?? 0) === 0,
            'Validation failure must not poison connection health',
        );
    }

    public function test_word_count_501_in_message_is_not_http_501(): void
    {
        $decision = (new AiProviderFailureClassifier())->classify(
            new OutputTruncated('Output shorter than minimum_length (434 words < 501 words).'),
        );
        $this->assertNotSame(AiFailureClass::SystemError, $decision->category);
        $this->assertTrue($decision->shouldContinueRouting());
        $this->assertNull($decision->httpStatus);
    }

    public function test_account_wide_429_suppresses_same_connection_in_request(): void
    {
        $userId = 304;
        $conn = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OR');
        $paidA = $this->model($conn, 'paid/a', false);
        $paidB = $this->model($conn, 'paid/b', false);
        $this->grant($conn, $paidA);
        $this->grant($conn, $paidB);
        $this->priorities->appendToArea($userId, AiModelArea::TextLongform, [(int) $paidA->id, (int) $paidB->id]);
        $this->writeAreaPriority($paidA, AiModelArea::TextLongform, 1);
        $this->writeAreaPriority($paidB, AiModelArea::TextLongform, 2);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();

        $decision = (new AiProviderFailureClassifier())->classify(
            new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException('organization quota exceeded', 429),
        );
        $this->assertSame(AiFailureScope::Connection, $decision->scope);
        $this->assertTrue($decision->lockConnection);
        $this->assertFalse($decision->applyCooldown);

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: $userId),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException('organization quota exceeded', 429);
                },
            );
            $this->fail('Expected routes exhausted');
        } catch (\Throwable) {
            $this->assertSame(['paid/a'], $calls, 'Sibling on same connection must be suppressed in-request');
        }
    }

    private function writeAreaPriority(SeoAiModel $model, AiModelArea $area, int $priority): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $areas = is_array($caps['omi_areas'] ?? null) ? $caps['omi_areas'] : [];
        $areas[$area->value] = [
            'enabled' => true,
            'priority' => $priority,
            'source' => 'manual',
        ];
        $caps['omi_areas'] = $areas;
        $model->capabilities = $caps;
        $model->save();
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'test-key-usable-long-enough',
            'status' => 'active',
            'is_global' => false,
            'paid_locked' => false,
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

    private function grant(ApiConnection $connection, SeoAiModel $model): void
    {
        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $capability) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => $model->raw_model_name,
                'capability' => $capability,
                'enabled' => true,
            ]);
        }
    }
}
