<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
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
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * OpenRouter free-models-per-day → DailyFreeQuotaExhausted (connection FREE lane).
 *
 * Cases A–F from the free daily quota classification contract.
 */
final class OpenRouterDailyFreeQuotaClassificationTest extends TestCase
{
    private AiModelRouterService $router;

    private AiRuntimeHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        AiRuntimeHealthService::clearSuppressedFreeLanes();

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

    protected function tearDown(): void
    {
        AiRuntimeHealthService::clearSuppressedFreeLanes();
        parent::tearDown();
    }

    /** A: FREE A1 → free-models-per-day; FREE A2 same connection must NOT be attempted. */
    public function test_a_same_connection_sibling_free_not_attempted(): void
    {
        (new AiResilienceSettingsService())->save(201, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $conn = $this->connection(201, 'or-a');
        $a1 = $this->model($conn, 'free/a1:free', true);
        $a2 = $this->model($conn, 'free/a2:free', true);
        $this->grantText($conn, $a1);
        $this->grantText($conn, $a2);
        app(AiModelPriorityService::class)->appendToArea(201, AiModelArea::TextLongform, [(int) $a1->id, (int) $a2->id]);

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 201, freeOnly: true),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw $this->dailyFreeQuotaException();
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException $e) {
            $this->assertSame(['free/a1:free'], $calls);
            $this->assertNotContains('free/a2:free', $calls);
            $attempts = $e->context['routing_attempts'] ?? [];
            $this->assertSame(AiFailureClass::DailyFreeQuotaExhausted->value, $attempts[0]['failure_class'] ?? null);
            $this->assertTrue((bool) ($attempts[0]['free_lane_suppressed'] ?? false));
            $skipped = array_values(array_filter(
                $attempts,
                static fn (array $row): bool => ($row['result'] ?? '') === 'skipped'
                    && ($row['skip_reason'] ?? '') === 'free_lane_suppressed',
            ));
            $this->assertNotEmpty($skipped);
            $this->assertStringContainsString('hết hạn mức gọi model miễn phí trong ngày', $e->userMessage());
        }
    }

    /** B: Connection A daily exhausted; Connection B free usable; FreeOnly → switch to B. */
    public function test_b_free_only_switches_to_other_connection_free(): void
    {
        (new AiResilienceSettingsService())->save(202, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $connA = $this->connection(202, 'or-a');
        $connB = $this->connection(202, 'or-b');
        $a1 = $this->model($connA, 'free/a1:free', true);
        $b1 = $this->model($connB, 'free/b1:free', true);
        $this->grantText($connA, $a1);
        $this->grantText($connB, $b1);
        app(AiModelPriorityService::class)->appendToArea(202, AiModelArea::TextLongform, [(int) $a1->id, (int) $b1->id]);

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 202, freeOnly: true),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'free/a1:free') {
                    throw $this->dailyFreeQuotaException();
                }

                return ['ok-b', null];
            },
        );

        $this->assertSame(['free/a1:free', 'free/b1:free'], $calls);
        $this->assertSame('ok-b', $output);
    }

    /** C: Connection A daily exhausted + PAID usable; FreeOnly → zero paid attempts, fail closed. */
    public function test_c_free_only_does_not_attempt_paid_after_daily_quota(): void
    {
        (new AiResilienceSettingsService())->save(203, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $conn = $this->connection(203, 'or-a');
        $free = $this->model($conn, 'free/a1:free', true);
        $paid = $this->model($conn, 'paid/p1', false);
        $this->grantText($conn, $free);
        $this->grantText($conn, $paid);
        app(AiModelPriorityService::class)->appendToArea(203, AiModelArea::TextLongform, [(int) $free->id, (int) $paid->id]);

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 203, freeOnly: true),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw $this->dailyFreeQuotaException();
                },
            );
            $this->fail('Expected AI_ROUTES_EXHAUSTED');
        } catch (AiRoutesExhaustedException $e) {
            $this->assertSame(['free/a1:free'], $calls);
            $this->assertNotContains('paid/p1', $calls);
            $this->assertSame(0, (int) ($e->context['paid_attempts'] ?? -1));
        }
    }

    /** D: Connection A daily exhausted + PAID usable; Normal → paid still attemptable. */
    public function test_d_normal_mode_still_attempts_paid_after_free_lane_suppress(): void
    {
        (new AiResilienceSettingsService())->save(204, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $conn = $this->connection(204, 'or-a');
        $free = $this->model($conn, 'free/a1:free', true);
        $paid = $this->model($conn, 'paid/p1', false);
        $this->grantText($conn, $free);
        $this->grantText($conn, $paid);
        app(AiModelPriorityService::class)->appendToArea(204, AiModelArea::TextLongform, [(int) $free->id, (int) $paid->id]);

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 204, freeOnly: false),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw $this->dailyFreeQuotaException();
                }

                return ['paid-ok', null];
            },
        );

        $this->assertSame(['free/a1:free', 'paid/p1'], $calls);
        $this->assertSame('paid-ok', $output);
    }

    /** E: Generic HTTP 429 (not free-models-per-day) → model cooldown, sibling free still attempted. */
    public function test_e_generic_429_still_model_scoped(): void
    {
        (new AiResilienceSettingsService())->save(205, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $conn = $this->connection(205, 'or-a');
        $a1 = $this->model($conn, 'free/a1:free', true);
        $a2 = $this->model($conn, 'free/a2:free', true);
        $this->grantText($conn, $a1);
        $this->grantText($conn, $a2);
        app(AiModelPriorityService::class)->appendToArea(205, AiModelArea::TextLongform, [(int) $a1->id, (int) $a2->id]);

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 205, freeOnly: true),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'free/a1:free') {
                    throw new PromptRunException('Provider API error (429): rate limit exceeded', 429);
                }

                return ['ok-a2', null];
            },
        );

        $this->assertSame(['free/a1:free', 'free/a2:free'], $calls);
        $this->assertSame('ok-a2', $output);
        $this->assertFalse($this->health->isConnectionFreeLaneSuppressed($conn->fresh()));
    }

    /** F: X-RateLimit-Reset → free-lane suppression expires at reset time. */
    public function test_f_free_lane_suppression_expires_at_reset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12T10:00:00Z'));
        $conn = $this->connection(206, 'or-a');
        $resetAt = Carbon::parse('2026-09-12T12:00:00Z');

        $decision = (new AiProviderFailureClassifier())->classify($this->dailyFreeQuotaException(
            limit: '50',
            remaining: '0',
            resetMs: (string) ($resetAt->getTimestamp() * 1000),
        ));
        $this->assertSame(AiFailureClass::DailyFreeQuotaExhausted, $decision->category);
        $this->assertNotNull($decision->freeDailyResetAt);

        $this->health->suppressConnectionFreeLane($conn, $decision);
        $this->assertTrue($this->health->isConnectionFreeLaneSuppressed($conn->fresh()));

        Carbon::setTestNow($resetAt->copy()->subSecond());
        $this->assertTrue($this->health->isConnectionFreeLaneSuppressed($conn->fresh()));

        Carbon::setTestNow($resetAt->copy()->addSecond());
        $this->assertFalse($this->health->isConnectionFreeLaneSuppressed($conn->fresh()));

        Carbon::setTestNow();
    }

    private function dailyFreeQuotaException(
        string $limit = '50',
        string $remaining = '0',
        string $resetMs = '1789257600000',
    ): PromptRunException {
        $body = [
            'error' => [
                'message' => 'Rate limit exceeded: free-models-per-day. Add 5 credits to unlock 1000 free model requests per day',
                'code' => 429,
                'metadata' => [
                    'limit_source' => 'openrouter_free_tier_daily',
                    'headers' => [
                        'X-RateLimit-Limit' => $limit,
                        'X-RateLimit-Remaining' => $remaining,
                        'X-RateLimit-Reset' => $resetMs,
                    ],
                ],
            ],
        ];

        return new PromptRunException(
            'Provider API error (429): '.json_encode($body),
            429,
            null,
            [
                'http_status' => 429,
                'response_body' => $body,
            ],
        );
    }

    private function connection(int $userId, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => $name,
            'api_key' => 'test-key-'.$name,
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
