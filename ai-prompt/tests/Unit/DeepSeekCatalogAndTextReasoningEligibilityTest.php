<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Support\AiCapabilitySource;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\AiProductionRouteEligibility;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\BuiltInModelCapabilityCatalog;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * DeepSeek provider /models catalog authority + TextReasoning capability eligibility.
 */
final class DeepSeekCatalogAndTextReasoningEligibilityTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRoutingTargetService $targets;

    private ModelCapabilityRegistry $registry;

    private AiModelRouterService $router;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'ai_routing_targets',
            'ai_routing_profiles',
            'ai_model_capabilities',
            'seo_ai_models',
            'api_connections',
            'ai_runtime_health_states',
            'wp_options',
        ] as $table) {
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
        $this->registry = new ModelCapabilityRegistry();
        $this->targets = new AiRoutingTargetService($this->registry, priorities: $this->priorities);
        $bootstrap = new AiRoutingBootstrapService($this->registry, $this->targets);
        $this->router = new AiModelRouterService($this->registry, $this->targets, $bootstrap);

        $this->app->instance(AiProviderFailureClassifier::class, new AiProviderFailureClassifier());
        $this->app->instance(AiRuntimeHealthService::class, new AiRuntimeHealthService(notifications: null));
        $this->app->instance(AiResilienceSettingsService::class, new AiResilienceSettingsService());
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
        $this->app->instance(AiRoutingTargetService::class, $this->targets);
    }

    public function test_a_successful_provider_sync_excludes_retired_local_aliases(): void
    {
        $ds = $this->deepseek(50);
        $this->seedLocal($ds, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);
        $this->seedLocal($ds, 'deepseek-reasoner', AiModelCategory::DEEPSEEK_REASONER);
        $this->seedLocal($ds, 'deepseek-flash', AiModelCategory::DEEPSEEK_CHAT);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'deepseek-flash', 'object' => 'model', 'owned_by' => 'deepseek'],
                    ['id' => 'deepseek-v4-pro', 'object' => 'model', 'owned_by' => 'deepseek'],
                ],
            ], 200),
        ]);

        $this->assertTrue($this->router->syncDeepSeekModels((int) $ds->id));

        $byRaw = SeoAiModel::query()
            ->where('api_connection_id', $ds->id)
            ->get()
            ->keyBy('raw_model_name');

        $this->assertSame(SeoAiModel::STATUS_ACTIVE, (string) $byRaw['deepseek-flash']->status);
        $this->assertSame(SeoAiModel::STATUS_ACTIVE, (string) $byRaw['deepseek-v4-pro']->status);
        $this->assertSame(SeoAiModel::STATUS_INACTIVE, (string) $byRaw['deepseek-chat']->status);
        $this->assertSame(SeoAiModel::STATUS_INACTIVE, (string) $byRaw['deepseek-reasoner']->status);
        $this->assertTrue($byRaw->has('deepseek-chat'), 'historical row retained');
    }

    public function test_b_provider_sync_failure_keeps_last_known_good_without_legacy_seed(): void
    {
        $ds = $this->deepseek(51);
        $good = $this->seedLocal($ds, 'deepseek-flash', AiModelCategory::DEEPSEEK_CHAT);

        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'upstream'], 503),
        ]);

        $this->assertFalse($this->router->syncDeepSeekModels((int) $ds->id));

        $good->refresh();
        $this->assertSame(SeoAiModel::STATUS_ACTIVE, (string) $good->status);
        $this->assertNull(
            SeoAiModel::query()
                ->where('api_connection_id', $ds->id)
                ->where('raw_model_name', 'deepseek-chat')
                ->first(),
        );
        $this->assertNull(
            SeoAiModel::query()
                ->where('api_connection_id', $ds->id)
                ->where('raw_model_name', 'deepseek-reasoner')
                ->first(),
        );
    }

    public function test_c_current_deepseek_reasoning_capable_model_eligible_text_reasoning(): void
    {
        $ds = $this->deepseek(52);
        $flash = $this->seedLocal($ds, 'deepseek-flash', AiModelCategory::DEEPSEEK_CHAT);
        $this->priorities->appendToArea(52, AiModelArea::TextReasoning, [(int) $flash->id]);

        $this->assertTrue(
            $this->registry->supports($ds, 'deepseek-flash', AiModelCapability::TextReasoning->value),
        );

        $candidates = $this->targets->eligibleCandidates(
            52,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 52, hookKey: 'article.outline.structure.generate'),
        );
        $models = array_map(static fn ($c): string => $c->model, $candidates);
        $this->assertContains('deepseek-flash', $models);
        $this->assertTrue((new AiProductionRouteEligibility())->deepSeekAllowed(
            AiExecutionProfile::TextReasoning,
            'article.outline.structure.generate',
        ));
    }

    public function test_d_non_reasoning_deepseek_rejected_by_capability_not_provider(): void
    {
        $ds = $this->deepseek(53);
        $chat = $this->seedLocal($ds, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);
        $this->priorities->appendToArea(53, AiModelArea::TextReasoning, [(int) $chat->id]);

        $this->assertFalse(
            $this->registry->supports($ds, 'deepseek-chat', AiModelCapability::TextReasoning->value),
        );
        $this->assertSame(
            BuiltInModelCapabilityCatalog::deepseek()['deepseek-chat'],
            $this->registry->capabilitiesFor($ds, 'deepseek-chat'),
        );

        $candidates = $this->targets->eligibleCandidates(
            53,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 53, hookKey: 'article.outline.structure.generate'),
        );
        $this->assertNotContains('deepseek-chat', array_map(static fn ($c): string => $c->model, $candidates));

        $diag = $this->targets->lastEligibilityDiagnostics();
        $this->assertIsArray($diag);
    }

    public function test_e_deepseek_first_in_paid_reasoning_order_is_attempted_first(): void
    {
        $ds = $this->deepseek(54);
        $or = $this->openrouter(54);
        $flash = $this->seedLocal($ds, 'deepseek-flash', AiModelCategory::DEEPSEEK_CHAT);
        $gpt = $this->seedOr($or, 'openai/gpt-5.4');
        $this->grantReasoning($or, $gpt);
        $this->priorities->appendToArea(54, AiModelArea::TextReasoning, [(int) $flash->id, (int) $gpt->id]);

        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(
                userId: 54,
                itemGenerationMode: 'paid_preferred',
                hookKey: 'article.outline.structure.generate',
            ),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'deepseek-flash') {
                    return ['ok-deepseek', ['provider' => 'deepseek']];
                }

                return ['ok-gpt', ['provider' => 'openrouter']];
            },
        );

        $this->assertSame(['deepseek-flash'], $calls);
        $this->assertSame('deepseek-flash', $selected->model);
        $this->assertSame('ok-deepseek', $output);
    }

    public function test_f_deepseek_retryable_failure_falls_back_to_next_paid_reasoning(): void
    {
        $ds = $this->deepseek(55);
        $or = $this->openrouter(55);
        $flash = $this->seedLocal($ds, 'deepseek-flash', AiModelCategory::DEEPSEEK_CHAT);
        $gpt = $this->seedOr($or, 'openai/gpt-5.4');
        $this->grantReasoning($or, $gpt);
        $this->priorities->appendToArea(55, AiModelArea::TextReasoning, [(int) $flash->id, (int) $gpt->id]);

        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(
                userId: 55,
                itemGenerationMode: 'paid_preferred',
                hookKey: 'article.outline.structure.generate',
            ),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'deepseek-flash') {
                    throw new PromptRunException('DeepSeek timed out / transient failure', 503);
                }

                return ['ok-gpt', null];
            },
        );

        $this->assertSame(['deepseek-flash', 'openai/gpt-5.4'], $calls);
        $this->assertSame('openai/gpt-5.4', $selected->model);
        $this->assertSame('ok-gpt', $output);
    }

    public function test_g_no_candidates_diagnostics_expose_rejection_reasons(): void
    {
        $ds = $this->deepseek(56);
        $inactiveConn = $this->deepseek(56, 'DeepSeek B');
        $inactiveConn->status = 'inactive';
        $inactiveConn->save();

        $chat = $this->seedLocal($ds, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);
        $stale = $this->seedLocal($inactiveConn, 'deepseek-flash', AiModelCategory::DEEPSEEK_CHAT);
        $this->priorities->appendToArea(56, AiModelArea::TextReasoning, [(int) $chat->id, (int) $stale->id]);

        $candidates = $this->targets->eligibleCandidates(
            56,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 56, hookKey: 'article.outline.structure.generate'),
        );
        $this->assertSame([], $candidates);

        $rejected = [];
        foreach ([$chat->fresh(), $stale->fresh()] as $model) {
            $conn = ApiConnection::query()->find((int) $model->api_connection_id);
            $raw = (string) $model->raw_model_name;
            $reason = 'eligible';
            if ($conn === null || (string) $conn->status !== 'active') {
                $reason = 'connection_disabled';
            } elseif (! $this->registry->supports($conn, $raw, AiModelCapability::TextReasoning->value)) {
                $reason = 'unsupported_capability';
            }
            $rejected[] = [
                'model' => $raw,
                'connection_id' => (int) ($conn?->id ?? 0),
                'provider' => (string) ($conn?->provider ?? ''),
                'rejected_reason' => $reason,
            ];
        }

        $this->assertSame('unsupported_capability', $rejected[0]['rejected_reason']);
        $this->assertSame('connection_disabled', $rejected[1]['rejected_reason']);
        $this->assertNotSame('provider_brand_ban', $rejected[0]['rejected_reason']);
    }

    public function test_h_free_paid_isolation_unchanged_for_reasoning(): void
    {
        $or = $this->openrouter(57);
        $free = $this->seedOr($or, 'meta/llama:free', true);
        $paid = $this->seedOr($or, 'openai/gpt-5.4');
        $this->grantReasoning($or, $free);
        $this->grantReasoning($or, $paid);
        $this->priorities->appendToArea(57, AiModelArea::FreeModels, [(int) $free->id]);
        $this->priorities->appendToArea(57, AiModelArea::TextReasoning, [(int) $paid->id]);

        $freeOnly = $this->targets->eligibleCandidates(
            57,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 57, costPolicy: AiCostPolicy::FreeOnly, freeOnly: true),
        );
        $paidOnly = $this->targets->eligibleCandidates(
            57,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 57, itemGenerationMode: 'paid_preferred'),
        );

        $this->assertContains('meta/llama:free', array_map(static fn ($c): string => $c->model, $freeOnly));
        $this->assertNotContains('openai/gpt-5.4', array_map(static fn ($c): string => $c->model, $freeOnly));
        $this->assertContains('openai/gpt-5.4', array_map(static fn ($c): string => $c->model, $paidOnly));
        $this->assertNotContains('meta/llama:free', array_map(static fn ($c): string => $c->model, $paidOnly));
    }

    public function test_client_requires_explicit_model_no_legacy_default(): void
    {
        $ds = $this->deepseek(58);
        $client = new DeepSeekChatClient();
        $this->expectException(PromptRunException::class);
        $this->expectExceptionMessage('Thiếu model DeepSeek từ routing');
        $client->generate($ds, 'hello', '', []);
    }

    public function test_client_sends_the_provider_catalog_model_id(): void
    {
        $ds = $this->deepseek(59);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'ok'],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        [$text] = (new DeepSeekChatClient())->generate($ds, 'hello', 'deepseek-v4-pro', []);

        self::assertSame('ok', $text);
        Http::assertSent(static function ($request): bool {
            return ($request->data()['model'] ?? null) === 'deepseek-v4-pro';
        });
    }

    public function test_client_reports_reasoning_only_length_response_as_truncated(): void
    {
        $ds = $this->deepseek(60);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '', 'reasoning_content' => 'thinking'],
                    'finish_reason' => 'length',
                ]],
                'usage' => ['completion_tokens_details' => ['reasoning_tokens' => 2048]],
            ], 200),
        ]);

        try {
            (new DeepSeekChatClient())->generate($ds, 'hello', 'deepseek-v4-pro', []);
            self::fail('Expected OutputTruncated');
        } catch (OutputTruncated $exception) {
            self::assertSame('length', $exception->providerFinishReason);
            self::assertStringContainsString('reasoning_tokens=2048', $exception->getMessage());
        }
    }

    public function test_general_outline_reserve_keeps_other_routes_eligible(): void
    {
        $ds = $this->deepseek(61);
        $model = $this->seedLocal($ds, 'deepseek-v4-pro', AiModelCategory::DEEPSEEK_REASONER);
        $capability = (new ModelContextCapabilityResolver())->resolveFor(
            $ds,
            'deepseek-v4-pro',
            (int) $model->id,
        );
        $reserve = (new PromptSplitStrategyRegistry())
            ->forHook('article.outline.structure.generate')
            ->estimateOutputReserve([], $capability);
        $vocabularyReserve = (new PromptSplitStrategyRegistry())
            ->forHook('article.vocabulary.generate')
            ->estimateOutputReserve([], $capability);

        self::assertGreaterThanOrEqual(8192, $capability->maxOutputTokens);
        self::assertSame(8192, $reserve);
        self::assertSame(8192, $vocabularyReserve);
        self::assertLessThanOrEqual($capability->maxOutputTokens, $reserve);

        $openrouterCapability = new \Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability(
            contextWindow: 128_000,
            maxOutputTokens: 8192,
            capabilitySource: 'test',
            estimatorFamily: \Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator::FAMILY_DEFAULT,
            isReasoningModel: true,
            safetyMarginTokens: 800,
        );
        $plan = (new PromptBudgetPreflightService())->planWithCapability(
            $openrouterCapability,
            (new PromptSplitStrategyRegistry())->forHook('article.outline.structure.generate'),
            'Write a short outline.',
            ['desired_output_tokens' => 8192],
        );
        self::assertTrue($plan->requestFits);
        self::assertGreaterThan(2048, $plan->requestedMaxOutputTokens);
        self::assertSame(8192, $plan->requestedMaxOutputTokens);
        unset($openrouter); // keep other routes eligible — deepseek still resolved above
    }

    public function test_split_article_hooks_disable_thinking_unless_explicitly_enabled(): void
    {
        $ds = $this->deepseek(62);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            ], 200),
        ]);

        $client = new DeepSeekChatClient();
        $client->generate($ds, 'outline', 'deepseek-v4-pro', [
            'hook_key' => 'article.outline.structure.generate',
        ]);
        $client->generate($ds, 'vocabulary', 'deepseek-v4-pro', [
            'hook_key' => 'article.vocabulary.generate',
        ]);
        $client->generate($ds, 'outline', 'deepseek-v4-pro', [
            'hook_key' => 'article.outline.structure.generate',
            'thinking' => 'enabled',
        ]);

        $requests = Http::recorded()->map(static fn (array $pair): array => $pair[0]->data())->all();
        self::assertSame(['type' => 'disabled'], $requests[0]['thinking'] ?? null);
        self::assertSame(['type' => 'disabled'], $requests[1]['thinking'] ?? null);
        self::assertSame(['type' => 'enabled'], $requests[2]['thinking'] ?? null);
    }

    private function deepseek(int $userId, string $name = 'DeepSeek'): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => $name,
            'api_key' => 'sk-deepseek-test-key-long-enough',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function openrouter(int $userId): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'sk-or-test-key-long-enough',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function seedLocal(ApiConnection $connection, string $raw, string $category): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => $category,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'resolved' => $this->registry->capabilitiesFor($connection, $raw),
            ],
        ]);
    }

    private function seedOr(ApiConnection $connection, string $raw, bool $free = false): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'provider_metadata' => [
                    'pricing' => $free
                        ? ['prompt' => '0', 'completion' => '0']
                        : ['prompt' => '0.000001', 'completion' => '0.000002'],
                ],
                'resolved' => [
                    AiModelCapability::TextGenerate->value,
                    AiModelCapability::TextReasoning->value,
                ],
            ],
        ]);
    }

    private function grantReasoning(ApiConnection $connection, SeoAiModel $model): void
    {
        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => (string) $model->raw_model_name,
                'capability' => $cap,
                'source' => AiCapabilitySource::Manual->value,
                'enabled' => true,
            ]);
        }
    }
}
