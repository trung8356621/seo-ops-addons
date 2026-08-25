<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingOwnerResolver;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Production-path health instrumentation: router → health DB (no auth required).
 */
final class AiRuntimeHealthProductionPathTest extends TestCase
{
    private AiModelRouterService $router;

    private AiRuntimeHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections', 'wp_options'] as $table) {
            Schema::dropIfExists($table);
        }
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
        $this->app->instance(AiRoutingOwnerResolver::class, new AiRoutingOwnerResolver());
    }

    public function test_success_writes_connection_and_model_health_without_auth(): void
    {
        Auth::logout();
        $owner = 77;
        [$conn, $model] = $this->seedSingle($owner, 'gemini-3-flash-preview', ApiConnectionProviders::GEMINI, false);

        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: $owner),
            fn (): array => ['ok', null],
        );
        $this->assertSame('ok', $output);

        $connHealth = AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'connection')
            ->where('subject_id', $conn->id)
            ->first();
        $modelHealth = AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'model')
            ->where('subject_id', $model->id)
            ->first();

        $this->assertNotNull($connHealth);
        $this->assertSame(1, (int) $connHealth->success_count);
        $this->assertSame(1, (int) $connHealth->total_attempts);
        $this->assertNotNull($connHealth->last_success_at);
        $this->assertSame('healthy', $connHealth->health_status);

        $this->assertNotNull($modelHealth);
        $this->assertSame(1, (int) $modelHealth->success_count);
        $this->assertSame(1, (int) $modelHealth->total_attempts);
        $this->assertNotNull($modelHealth->last_success_at);
    }

    public function test_no_double_count_on_single_success(): void
    {
        Auth::logout();
        $owner = 78;
        $this->seedSingle($owner, 'gemini-3-flash-preview', ApiConnectionProviders::GEMINI, false);
        $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: $owner),
            fn (): array => ['ok', null],
        );

        $this->assertSame(1, (int) AiRuntimeHealthState::query()->where('user_id', $owner)->where('subject_type', 'connection')->value('total_attempts'));
        $this->assertSame(1, (int) AiRuntimeHealthState::query()->where('user_id', $owner)->where('subject_type', 'model')->value('total_attempts'));
    }

    public function test_fallback_402_then_success_updates_both_scopes(): void
    {
        Auth::logout();
        $owner = 79;
        $or = ApiConnection::query()->create([
            'user_id' => $owner,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $gemini = ApiConnection::query()->create([
            'user_id' => $owner,
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'Gemini',
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $claude = $this->model($or, 'anthropic/claude-sonnet-4.6', false);
        $flash = $this->model($gemini, 'gemini-3-flash-preview', false);
        $this->grantText($or, $claude);
        $this->grantText($gemini, $flash);
        app(AiModelPriorityService::class)->appendToArea($owner, AiModelArea::TextLongform, [(int) $claude->id, (int) $flash->id]);

        $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: $owner),
            function ($candidate): array {
                if ($candidate->model === 'anthropic/claude-sonnet-4.6') {
                    throw new PromptRunException('Provider API error (402): requires more credits', 402);
                }

                return ['ok', null];
            },
        );

        $orHealth = AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'connection')
            ->where('subject_id', $or->id)
            ->first();
        $geminiHealth = AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'connection')
            ->where('subject_id', $gemini->id)
            ->first();

        $this->assertTrue((bool) $orHealth?->paid_locked);
        $this->assertSame('budget_limited', $orHealth?->health_status);
        $this->assertSame(1, (int) $orHealth?->failure_count);
        $this->assertSame(1, (int) $geminiHealth?->success_count);
        $this->assertSame('healthy', $geminiHealth?->health_status);
        $this->assertSame(1, (int) AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'model')
            ->where('subject_id', $claude->id)
            ->value('failure_count'));
        $this->assertSame(1, (int) AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'model')
            ->where('subject_id', $flash->id)
            ->value('success_count'));
    }

    public function test_owner_isolation(): void
    {
        Auth::logout();
        $this->seedSingle(10, 'gemini-3-flash-preview', ApiConnectionProviders::GEMINI, false);
        $this->seedSingle(20, 'deepseek-chat', ApiConnectionProviders::DEEPSEEK, false);
        $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 10),
            fn (): array => ['a', null],
        );
        $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 20),
            fn (): array => ['b', null],
        );

        $rowsA = $this->health->connectionHealthRows(10);
        $rowsB = $this->health->connectionHealthRows(20);
        $this->assertTrue(collect($rowsA)->contains(fn (array $r): bool => (int) $r['success_count'] >= 1));
        $this->assertFalse(collect($rowsA)->contains(fn (array $r): bool => $r['provider'] === ApiConnectionProviders::DEEPSEEK && (int) $r['success_count'] >= 1));
        $this->assertTrue(collect($rowsB)->contains(fn (array $r): bool => (int) $r['success_count'] >= 1));
    }

    public function test_health_skip_does_not_increment_failure(): void
    {
        Auth::logout();
        $owner = 81;
        $or = ApiConnection::query()->create([
            'user_id' => $owner,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $gemini = ApiConnection::query()->create([
            'user_id' => $owner,
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'Gemini',
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $claude = $this->model($or, 'anthropic/claude-sonnet-4.6', false);
        $flash = $this->model($gemini, 'gemini-3-flash-preview', false);
        $this->grantText($or, $claude);
        $this->grantText($gemini, $flash);
        app(AiModelPriorityService::class)->appendToArea($owner, AiModelArea::TextLongform, [(int) $claude->id, (int) $flash->id]);

        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: $owner),
                function ($candidate): array {
                    if ($candidate->model === 'anthropic/claude-sonnet-4.6') {
                        throw new PromptRunException('402', 402);
                    }

                    return ['seed-ok', null];
                },
            );
        } catch (\Throwable) {
        }

        $failuresBefore = (int) AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'connection')
            ->where('subject_id', $or->id)
            ->value('failure_count');

        $calls = [];
        $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: $owner),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;

                return ['ok', null];
            },
        );

        $this->assertSame(['gemini-3-flash-preview'], $calls);
        $this->assertSame($failuresBefore, (int) AiRuntimeHealthState::query()
            ->where('user_id', $owner)
            ->where('subject_type', 'connection')
            ->where('subject_id', $or->id)
            ->value('failure_count'));
    }

    /**
     * @return array{0: ApiConnection, 1: SeoAiModel}
     */
    private function seedSingle(int $userId, string $raw, string $provider, bool $free): array
    {
        $conn = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $provider,
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $model = $this->model($conn, $raw, $free);
        $this->grantText($conn, $model);
        app(AiModelPriorityService::class)->appendToArea($userId, AiModelArea::TextLongform, [(int) $model->id]);

        return [$conn, $model];
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
