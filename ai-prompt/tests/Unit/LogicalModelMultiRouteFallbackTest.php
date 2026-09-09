<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
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
 * Physical-route identity under one logical model (DeepSeek Chat = OR + Direct).
 */
final class LogicalModelMultiRouteFallbackTest extends TestCase
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

    /** TEST 1 — OR 402 then DeepSeek direct success (article content). */
    public function test_1_or_402_then_deepseek_direct_succeeds(): void
    {
        $seed = $this->seedDeepSeekChatRoutes(201, orPriority: 1, dsPriority: 2);
        (new AiResilienceSettingsService())->save(201, ['max_ai_attempts' => 4, 'max_free_attempts' => 2]);

        $calls = [];
        [$output, , $selected, , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 201, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->provider.'|'.$candidate->model;
                if ($candidate->provider === ApiConnectionProviders::OPENROUTER) {
                    throw new PromptRunException('Provider API error (402): requires more credits', 402);
                }

                return ['ds-ok', ['resolved_model' => $candidate->model]];
            },
        );

        $this->assertSame('ds-ok', $output);
        $this->assertSame(ApiConnectionProviders::DEEPSEEK, $selected->provider);
        $this->assertSame(['openrouter|deepseek/deepseek-chat', 'deepseek|deepseek-chat'], $calls);
        $this->assertTrue((bool) AiRuntimeHealthState::query()
            ->where('user_id', 201)
            ->where('subject_type', 'connection')
            ->where('subject_id', (int) $seed['or']->id)
            ->value('paid_locked'));
        $failed = collect($attempts)->firstWhere('result', 'failed');
        $this->assertNotNull($failed);
        $this->assertSame(402, (int) ($failed['http_status'] ?? 0));
        $this->assertTrue((bool) ($failed['sibling_routes_remain_eligible'] ?? false));
        $this->assertFalse((bool) ($failed['logical_model_exhausted'] ?? true));
        $this->assertSame('connection_paid_locked', $failed['health_mutation'] ?? null);
        $this->assertSame('deepseek.chat', $failed['logical_model'] ?? null);
    }

    /** TEST 2 — routing_attempts contains both physical routes. */
    public function test_2_routing_attempts_contain_both_physical_routes(): void
    {
        $this->seedDeepSeekChatRoutes(202, orPriority: 1, dsPriority: 2);
        (new AiResilienceSettingsService())->save(202, ['max_ai_attempts' => 4, 'max_free_attempts' => 2]);

        [, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 202, hookKey: 'article.content.generate'),
            function ($candidate): array {
                if ($candidate->provider === ApiConnectionProviders::OPENROUTER) {
                    throw new PromptRunException('402 credits', 402);
                }

                return ['ok', null];
            },
        );

        $physical = array_values(array_map(
            static fn (array $row): string => (string) ($row['physical_route'] ?? ''),
            $attempts,
        ));
        $this->assertCount(2, $physical);
        $this->assertStringContainsString('openrouter|deepseek/deepseek-chat', $physical[0]);
        $this->assertStringContainsString('deepseek|deepseek-chat', $physical[1]);
        $this->assertNotSame($physical[0], $physical[1]);
        $this->assertSame('failed', $attempts[0]['result']);
        $this->assertSame('success', $attempts[1]['result']);
    }

    /** TEST 3 — OR 402 locks OR paid lane; DeepSeek direct still attempted; other OR model skipped. */
    public function test_3_or_paid_lock_skips_other_or_but_not_deepseek_direct(): void
    {
        $seed = $this->seedDeepSeekChatRoutes(203, orPriority: 1, dsPriority: 3);
        $other = $this->model($seed['or'], 'anthropic/claude-sonnet-4.6', false);
        $this->grant($seed['or'], $other);
        $this->priorities->appendToArea(203, AiModelArea::TextLongform, [(int) $other->id]);
        // Force order: OR deepseek (1), OR claude (2), DS (3)
        $this->writeAreaPriority($seed['orModel'], AiModelArea::TextLongform, 1);
        $this->writeAreaPriority($other, AiModelArea::TextLongform, 2);
        $this->writeAreaPriority($seed['dsModel'], AiModelArea::TextLongform, 3);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();
        (new AiResilienceSettingsService())->save(203, ['max_ai_attempts' => 6, 'max_free_attempts' => 2]);

        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 203, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'deepseek/deepseek-chat') {
                    throw new PromptRunException('402 payment required', 402);
                }

                return ['ok', null];
            },
        );

        $this->assertSame('ok', $output);
        $this->assertSame(['deepseek/deepseek-chat', 'deepseek-chat'], $calls);
        $this->assertNotContains('anthropic/claude-sonnet-4.6', $calls);
        // Logical-model grouping keeps DS sibling contiguous after OR; Claude is never reached on success.
        // Persistence: OR paid lane is locked so a later request would skip other OR paid models.
        $this->assertTrue((bool) AiRuntimeHealthState::query()
            ->where('user_id', 203)
            ->where('subject_type', 'connection')
            ->where('subject_id', (int) $seed['or']->id)
            ->value('paid_locked'));
        $orFail = collect($attempts)->firstWhere('result', 'failed');
        $this->assertTrue((bool) ($orFail['paid_lane_suppressed'] ?? false));
        $this->assertTrue((bool) ($orFail['sibling_routes_remain_eligible'] ?? false));
    }

    /** TEST 4 — both DeepSeek Chat routes fail before next logical model. */
    public function test_4_logical_model_exhausted_only_after_all_physical_routes_fail(): void
    {
        $seed = $this->seedDeepSeekChatRoutes(204, orPriority: 1, dsPriority: 2);
        $gemini = $this->connection(204, ApiConnectionProviders::GEMINI, 'Gemini');
        $flash = $this->model($gemini, 'gemini-3-flash-preview', false);
        $this->grant($gemini, $flash);
        $this->priorities->appendToArea(204, AiModelArea::TextLongform, [(int) $flash->id]);
        $this->writeAreaPriority($seed['orModel'], AiModelArea::TextLongform, 1);
        $this->writeAreaPriority($seed['dsModel'], AiModelArea::TextLongform, 2);
        $this->writeAreaPriority($flash, AiModelArea::TextLongform, 3);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();
        (new AiResilienceSettingsService())->save(204, ['max_ai_attempts' => 6, 'max_free_attempts' => 2]);

        $calls = [];
        [$output, , $selected, , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 204, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if (str_contains($candidate->model, 'deepseek')) {
                    throw new PromptRunException('Provider API error (503): unavailable', 503);
                }

                return ['gemini-ok', null];
            },
        );

        $this->assertSame(['deepseek/deepseek-chat', 'deepseek-chat', 'gemini-3-flash-preview'], $calls);
        $this->assertSame('gemini-ok', $output);
        $this->assertSame('gemini-3-flash-preview', $selected->model);
        $orFail = collect($attempts)->first(
            static fn (array $r): bool => ($r['result'] ?? '') === 'failed'
                && ($r['model'] ?? '') === 'deepseek/deepseek-chat',
        );
        $this->assertTrue((bool) ($orFail['sibling_routes_remain_eligible'] ?? false));
        $dsFail = collect($attempts)->first(
            static fn (array $r): bool => ($r['result'] ?? '') === 'failed'
                && ($r['model'] ?? '') === 'deepseek-chat',
        );
        $this->assertFalse((bool) ($dsFail['sibling_routes_remain_eligible'] ?? true));
        $this->assertTrue((bool) ($dsFail['logical_model_exhausted'] ?? false));
    }

    /** TEST 5 — prior OR 402 must not skip DeepSeek direct on the next request. */
    public function test_5_previous_or_402_does_not_skip_deepseek_direct_next_request(): void
    {
        $seed = $this->seedDeepSeekChatRoutes(205, orPriority: 1, dsPriority: 2);
        (new AiResilienceSettingsService())->save(205, ['max_ai_attempts' => 4, 'max_free_attempts' => 2]);

        // First request: OR 402 → DS success (locks OR paid lane).
        $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 205, hookKey: 'article.content.generate'),
            function ($candidate): array {
                if ($candidate->provider === ApiConnectionProviders::OPENROUTER) {
                    throw new PromptRunException('402 credits', 402);
                }

                return ['ok1', null];
            },
        );

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 205, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->provider.'|'.$candidate->model;

                return ['ok2', null];
            },
        );

        $this->assertSame('ok2', $output);
        $this->assertContains('deepseek|deepseek-chat', $calls);
        $this->assertNotContains('openrouter|deepseek/deepseek-chat', $calls);
        $dsCandidate = collect($this->targets->eligibleCandidates(
            205,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 205, hookKey: 'article.content.generate'),
        ))->first(static fn ($c) => $c->provider === ApiConnectionProviders::DEEPSEEK);
        $this->assertNotNull($dsCandidate);
        $this->assertNull($this->health->skipReason(205, $dsCandidate));
        unset($seed);
    }

    /** TEST 6 — tight MAX_AI_ATTEMPTS still attempts sibling Direct after OR 402. */
    public function test_6_tight_max_ai_attempts_still_tries_sibling_direct(): void
    {
        $this->seedDeepSeekChatRoutes(206, orPriority: 1, dsPriority: 2);
        $or = ApiConnection::query()->where('user_id', 206)->where('provider', ApiConnectionProviders::OPENROUTER)->firstOrFail();
        $free = $this->model($or, 'meta/llama:free', true);
        $this->grant($or, $free);
        $this->priorities->appendToArea(206, AiModelArea::TextLongform, [(int) $free->id]);
        $orModel = SeoAiModel::query()->where('api_connection_id', $or->id)->where('raw_model_name', 'deepseek/deepseek-chat')->firstOrFail();
        $dsModel = SeoAiModel::query()->where('raw_model_name', 'deepseek-chat')->firstOrFail();
        $this->writeAreaPriority($free, AiModelArea::TextLongform, 1);
        $this->writeAreaPriority($orModel, AiModelArea::TextLongform, 2);
        $this->writeAreaPriority($dsModel, AiModelArea::TextLongform, 3);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();

        // max=3: free + OR + DS each need a slot; reservation must not collapse paid siblings to 1.
        (new AiResilienceSettingsService())->save(206, ['max_ai_attempts' => 3, 'max_free_attempts' => 1]);

        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 206, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree || $candidate->provider === ApiConnectionProviders::OPENROUTER) {
                    throw new PromptRunException(
                        $candidate->isFree ? '503' : '402 credits',
                        $candidate->isFree ? 503 : 402,
                    );
                }

                return ['ds-ok', null];
            },
        );

        $this->assertSame('ds-ok', $output);
        $this->assertSame(['meta/llama:free', 'deepseek/deepseek-chat', 'deepseek-chat'], $calls);
        $this->assertGreaterThanOrEqual(2, (int) ($attempts[0]['reserved_paid_slots'] ?? 0));
    }

    /**
     * @return array{or: ApiConnection, ds: ApiConnection, orModel: SeoAiModel, dsModel: SeoAiModel}
     */
    private function seedDeepSeekChatRoutes(int $userId, int $orPriority, int $dsPriority): array
    {
        $or = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $ds = $this->connection($userId, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $orModel = $this->model($or, 'deepseek/deepseek-chat', false);
        $dsModel = $this->model($ds, 'deepseek-chat', false);
        $this->grant($or, $orModel);
        $this->grant($ds, $dsModel);
        $this->priorities->appendToArea($userId, AiModelArea::TextLongform, [(int) $orModel->id, (int) $dsModel->id]);
        $this->writeAreaPriority($orModel, AiModelArea::TextLongform, $orPriority);
        $this->writeAreaPriority($dsModel, AiModelArea::TextLongform, $dsPriority);
        $this->priorities->forgetMemo();
        $this->targets->forgetMemo();

        return ['or' => $or, 'ds' => $ds, 'orModel' => $orModel, 'dsModel' => $dsModel];
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
            'category' => AiModelCategory::DEEPSEEK_CHAT,
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
