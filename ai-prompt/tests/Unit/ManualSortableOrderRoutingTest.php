<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Manual AI Center sortable order is the runtime execution order.
 * Cost policy constrains eligibility/budgets — it must not reorder candidates.
 */
final class ManualSortableOrderRoutingTest extends TestCase
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

    /** TEST A — DeepSeek #1 healthy succeeds; Free Pool never called */
    public function test_a_deepseek_first_succeeds_without_calling_free(): void
    {
        (new AiResilienceSettingsService())->save(201, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedDeepseekThenFree(201);
        $calls = [];
        [$output, $usage, $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 201, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok-'.$candidate->model, ['resolved_model' => $candidate->model]];
            },
        );
        $this->assertSame(['deepseek-chat'], $calls);
        $this->assertSame('deepseek-chat', $selected->model);
        $this->assertSame('ok-deepseek-chat', $output);
        $plan = $usage['_routing_plan'] ?? [];
        $ordered = $plan['ordered_routes'] ?? $plan['execution_order'] ?? [];
        $this->assertSame('deepseek-chat', $ordered[0]['provider_model'] ?? null);
        $this->assertSame('paid', $ordered[0]['cost_class'] ?? null);
    }

    /** TEST B — DeepSeek #1 fails → Free Pool next */
    public function test_b_deepseek_fail_then_free_pool(): void
    {
        (new AiResilienceSettingsService())->save(202, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedDeepseekThenFree(202);
        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 202, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'deepseek-chat') {
                    throw new PromptRunException('503 upstream', 503);
                }

                return ['free-ok', null];
            },
        );
        $this->assertSame(['deepseek-chat', 'meta/llama:free'], $calls);
        $this->assertSame('meta/llama:free', $selected->model);
        $this->assertSame('free-ok', $output);
    }

    /** TEST C — DeepSeek #1 model_cooldown SKIPPED (0 API) → Free Pool API #1 */
    public function test_c_deepseek_health_skip_then_free_pool(): void
    {
        (new AiResilienceSettingsService())->save(203, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        [$deepseekId] = $this->seedDeepseekThenFree(203);
        $this->putModelCooldown(203, $deepseekId);

        $calls = [];
        [$output, , $selected, , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 203, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['free-ok', null];
            },
        );

        $this->assertSame(['meta/llama:free'], $calls);
        $this->assertSame('meta/llama:free', $selected->model);
        $this->assertSame('free-ok', $output);
        $this->assertSame('skipped', $routingAttempts[0]['result'] ?? null);
        $this->assertSame('model_cooldown', $routingAttempts[0]['skip_reason'] ?? null);
        $this->assertFalse((bool) ($routingAttempts[0]['attempted'] ?? true));
        $this->assertSame(0, (int) ($routingAttempts[0]['actual_attempts'] ?? -1));
        $this->assertSame('deepseek-chat', $routingAttempts[0]['model'] ?? null);
        $this->assertSame('success', $routingAttempts[1]['result'] ?? null);
        $this->assertSame('meta/llama:free', $routingAttempts[1]['model'] ?? null);
        $this->assertSame(1, (int) ($routingAttempts[1]['actual_attempts'] ?? 0));
    }

    /** TEST D — FREE_ONLY excludes DeepSeek; Free Pool is first API */
    public function test_d_free_only_excludes_paid_deepseek(): void
    {
        (new AiResilienceSettingsService())->save(204, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedDeepseekThenFree(204);
        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(
                userId: 204,
                hookKey: 'article.content.generate',
                costPolicy: AiCostPolicy::FreeOnly,
            ),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['free-ok', null];
            },
        );
        $this->assertSame(['meta/llama:free'], $calls);
        $this->assertSame('meta/llama:free', $selected->model);
        $this->assertSame('free-ok', $output);
    }

    /** TEST E — Free Pool #1, DeepSeek #2 → Free first */
    public function test_e_free_pool_first_when_manually_sorted_first(): void
    {
        (new AiResilienceSettingsService())->save(205, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedFreeThenDeepseek(205);
        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 205, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame(['meta/llama:free'], $calls);
        $this->assertSame('meta/llama:free', $selected->model);
        $this->assertSame('ok', $output);
    }

    /** TEST F — paid before free; MAX_FREE does not float free ahead of paid */
    public function test_f_max_free_does_not_reorder_paid_before_free(): void
    {
        (new AiResilienceSettingsService())->save(206, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $this->seedDeepseekThenFree(206);
        $candidates = $this->targets->eligibleCandidates(
            206,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 206),
        );
        [$plan, $ordered] = (new AiCandidatePlanner())->plan(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 206, hookKey: 'article.content.generate'),
            $candidates,
            6,
            3,
            static fn (): ?string => null,
        );
        $this->assertFalse($ordered[0]->isFree);
        $this->assertTrue($ordered[1]->isFree);
        $this->assertSame('paid', $plan->executionOrder[0]->costClass);
        $this->assertSame('free', $plan->executionOrder[1]->costClass);
        $this->assertSame('deepseek-chat', $plan->executionOrder[0]->providerModel);
        $this->assertSame('meta/llama:free', $plan->executionOrder[1]->providerModel);
        // Diagnostic free_phase must not become the start of execution_order.
        $this->assertNotSame(
            $plan->freePhase[0]->providerModel ?? null,
            $plan->executionOrder[0]->providerModel,
        );
    }

    /** Health visibility — DS first SKIPPED, then Free API, attempt_count excludes skip */
    public function test_health_skip_visibility_in_exhausted_trace(): void
    {
        (new AiResilienceSettingsService())->save(207, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        [$deepseekId] = $this->seedDeepseekThenFree(207);
        $this->putModelCooldown(207, $deepseekId);

        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 207, hookKey: 'article.content.generate'),
                function ($candidate): array {
                    throw new PromptRunException('503', 503);
                },
            );
            $this->fail('Expected exhaustion');
        } catch (AiRoutesExhaustedException $e) {
            $attempts = $e->context['routing_attempts'] ?? [];
            $this->assertNotEmpty($attempts);
            $first = $attempts[0];
            $this->assertSame('skipped', $first['result'] ?? null);
            $this->assertSame('model_cooldown', $first['skip_reason'] ?? null);
            $this->assertFalse((bool) ($first['attempted'] ?? true));
            $this->assertSame(0, (int) ($first['actual_attempts'] ?? -1));
            $this->assertSame('deepseek-chat', $first['model'] ?? null);

            $apiCalls = array_values(array_filter(
                $attempts,
                static fn (array $row): bool => in_array($row['result'] ?? '', ['failed', 'success'], true),
            ));
            $this->assertNotEmpty($apiCalls);
            $this->assertSame('meta/llama:free', $apiCalls[0]['model'] ?? null);
            $this->assertSame(1, (int) ($e->context['attempt_count'] ?? 0));
        }
    }

    private function putModelCooldown(int $userId, int $modelId): void
    {
        AiRuntimeHealthState::query()->create([
            'user_id' => $userId,
            'subject_type' => AiRuntimeHealthState::SUBJECT_MODEL,
            'subject_id' => $modelId,
            'api_connection_id' => null,
            'health_status' => AiRuntimeHealthStatus::Degraded->value,
            'cooldown_until' => now()->addMinutes(10),
            'paid_locked' => false,
            'manual_unlock_required' => false,
            'total_attempts' => 1,
            'failure_count' => 1,
            'consecutive_failures' => 1,
        ]);
    }

    /**
     * @return array{0: int, 1: int} deepseek model id, free model id
     */
    private function seedDeepseekThenFree(int $userId): array
    {
        $dsConn = $this->connection($userId, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $orConn = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $deepseek = $this->model($dsConn, 'deepseek-chat', false);
        $free = $this->model($orConn, 'meta/llama:free', true);
        $this->grantText($dsConn, $deepseek);
        $this->grantText($orConn, $free);
        app(AiModelPriorityService::class)->appendToArea(
            $userId,
            AiModelArea::TextLongform,
            [(int) $deepseek->id, (int) $free->id],
        );

        return [(int) $deepseek->id, (int) $free->id];
    }

    private function seedFreeThenDeepseek(int $userId): void
    {
        $orConn = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $dsConn = $this->connection($userId, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $free = $this->model($orConn, 'meta/llama:free', true);
        $deepseek = $this->model($dsConn, 'deepseek-chat', false);
        $this->grantText($orConn, $free);
        $this->grantText($dsConn, $deepseek);
        app(AiModelPriorityService::class)->appendToArea(
            $userId,
            AiModelArea::TextLongform,
            [(int) $free->id, (int) $deepseek->id],
        );
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
