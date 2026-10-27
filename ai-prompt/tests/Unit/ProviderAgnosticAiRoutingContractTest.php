<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingContextResolver;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\PaidLockReason;
use Tests\TestCase;

final class ProviderAgnosticAiRoutingContractTest extends TestCase
{
    private AiModelPriorityService $priorities;
    private AiRoutingTargetService $targets;
    private AiModelRouterService $router;
    private AiRuntimeHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        AiRuntimeHealthService::clearSuppressedFreeLanes();

        foreach ([
            'ai_routing_targets',
            'ai_routing_profiles',
            'ai_model_capabilities',
            'seo_ai_models',
            'api_connections',
            'ai_runtime_health_states',
            'wp_options',
            'prompts',
            'prompt_versions',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        ModelCapabilityRegistry::flushRequestMemo();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Admin');
            $table->string('email')->default('admin@example.com');
            $table->string('password')->default('secret');
            $table->timestamps();
        });
        \App\Models\User::query()->create([
            'id' => 1,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

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
            $table->unsignedInteger('priority')->default(100);
            $table->string('status')->default('active');
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id')->nullable();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->string('model_key');
            $table->string('capability');
            $table->boolean('enabled')->default(true);
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

        Schema::create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_prompt_version_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->unsignedBigInteger('ai_connection_id')->nullable();
            $table->string('routing_mode', 16)->default('auto');
            $table->string('name', 255)->nullable();
            $table->string('title', 255)->default('Test Prompt');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->longText('markdown_content')->nullable();
            $table->json('settings')->nullable();
            $table->json('variables')->nullable();
            $table->json('hook_settings')->nullable();
            $table->string('hook_key', 128)->nullable();
            $table->string('hook_version', 32)->nullable();
            $table->string('tools', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->char('portable_uuid', 36)->nullable();
        });

        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->index();
            $table->string('version_label', 32)->default('1.0.0');
            $table->unsignedInteger('sequence')->default(1);
            $table->longText('markdown_content')->nullable();
            $table->string('hook_key', 255)->nullable();
            $table->string('hook_version', 255)->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('settings')->nullable();
            $table->string('tools', 32)->nullable();
            $table->json('variables')->nullable();
            $table->string('content_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

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
        $this->app->instance(AiModelRouterService::class, $this->router);
    }

    private function createConnection(string $provider, string $name, bool $paidLocked = false, string $status = 'active'): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'test-key-for-'.$provider.'-'.uniqid(),
            'status' => $status,
            'paid_locked' => $paidLocked,
            'paid_lock_reasons' => $paidLocked ? [PaidLockReason::ManualFreeOnly->value] : null,
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function createModel(ApiConnection $connection, string $rawName, string $displayName, bool $isFree, int $priority = 100): SeoAiModel
    {
        $pricing = $isFree ? ['prompt' => '0', 'completion' => '0'] : ['prompt' => '1.5', 'completion' => '5.0'];
        $model = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => $rawName,
            'display_name' => $displayName,
            'priority' => $priority,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'provider_metadata' => [
                    'pricing' => $pricing,
                    'architecture' => ['modality' => 'text->text'],
                ],
            ],
        ]);

        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => $rawName,
                'capability' => $cap,
                'enabled' => true,
            ]);
        }

        return $model;
    }

    /**
     * Test A: OpenRouter Connection A: Free Only = ON -> Free allowed, Paid blocked.
     */
    public function test_test_a_openrouter_free_only_on_allows_free_blocks_paid(): void
    {
        $conn = $this->createConnection(ApiConnectionProviders::OPENROUTER, 'OpenRouter A', paidLocked: true);
        $freeModel = $this->createModel($conn, 'google/gemma-2-9b-it:free', 'Gemma Free', isFree: true);
        $paidModel = $this->createModel($conn, 'anthropic/claude-3.5-sonnet', 'Claude Sonnet', isFree: false);

        $this->priorities->appendToArea(1, AiModelArea::FreeModels, [(int) $freeModel->id]);
        $this->priorities->appendToArea(1, AiModelArea::TextReasoning, [(int) $paidModel->id]);

        $freeCtx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::FreeOnly, freeOnly: true);
        $freeCandidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextReasoning, $freeCtx);
        self::assertNotEmpty($freeCandidates);
        self::assertTrue($freeCandidates[0]->isFree);

        $paidCtx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::Default, itemGenerationMode: 'paid_preferred');
        $paidCandidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextReasoning, $paidCtx);
        self::assertEmpty($paidCandidates, 'Paid candidates on Free-Only connection must be blocked');
    }

    /**
     * Test B: OpenRouter Connection B: Free Only = OFF -> Free/Paid allowed by policy.
     */
    public function test_test_b_openrouter_free_only_off_allows_both_free_and_paid(): void
    {
        $conn = $this->createConnection(ApiConnectionProviders::OPENROUTER, 'OpenRouter B', paidLocked: false);
        $freeModel = $this->createModel($conn, 'google/gemma-2-9b-it:free', 'Gemma Free', isFree: true);
        $paidModel = $this->createModel($conn, 'anthropic/claude-3.5-sonnet', 'Claude Sonnet', isFree: false);

        $this->priorities->appendToArea(1, AiModelArea::FreeModels, [(int) $freeModel->id]);
        $this->priorities->appendToArea(1, AiModelArea::TextReasoning, [(int) $paidModel->id]);

        $freeCtx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::FreeOnly, freeOnly: true);
        $freeCandidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextReasoning, $freeCtx);
        self::assertNotEmpty($freeCandidates);
        self::assertTrue($freeCandidates[0]->isFree);

        $paidCtx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::Default, itemGenerationMode: 'paid_preferred');
        $paidCandidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextReasoning, $paidCtx);
        self::assertNotEmpty($paidCandidates, 'Paid candidates allowed when connection Free-Only is OFF');
        self::assertFalse($paidCandidates[0]->isFree);
    }

    /**
     * Test C: Fake Gemini with Free/Paid models applies same contract.
     */
    public function test_test_c_gemini_with_free_and_paid_models_applies_contract(): void
    {
        $conn = $this->createConnection(ApiConnectionProviders::GEMINI, 'Gemini Main', paidLocked: false);
        $freeModel = $this->createModel($conn, 'gemini-3-flash-preview', 'Gemini Flash Free', isFree: true);
        $paidModel = $this->createModel($conn, 'gemini-3.1-pro-preview', 'Gemini Pro Paid', isFree: false);

        $this->priorities->appendToArea(1, AiModelArea::FreeModels, [(int) $freeModel->id]);
        $this->priorities->appendToArea(1, AiModelArea::TextLongform, [(int) $paidModel->id]);

        $paidCtx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::Default, itemGenerationMode: 'paid_preferred');
        $paidCandidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextLongform, $paidCtx);
        self::assertNotEmpty($paidCandidates);
        self::assertSame('gemini-3.1-pro-preview', $paidCandidates[0]->model);

        $freeCtx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::FreeOnly, freeOnly: true);
        $freeCandidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextLongform, $freeCtx);
        self::assertNotEmpty($freeCandidates);
        self::assertSame('gemini-3-flash-preview', $freeCandidates[0]->model);
    }

    /**
     * Test D: Fake DeepSeek: Paid models allowed unless connection locked.
     */
    public function test_test_d_deepseek_paid_models_allowed_unless_connection_locked(): void
    {
        $conn = $this->createConnection(ApiConnectionProviders::DEEPSEEK, 'DeepSeek Direct', paidLocked: false);
        $paidModel = $this->createModel($conn, 'deepseek-reasoner', 'DeepSeek Reasoner', isFree: false);

        $this->priorities->appendToArea(1, AiModelArea::TextReasoning, [(int) $paidModel->id]);

        $paidCtx = new AiRoutingContext(userId: 1, costPolicy: AiCostPolicy::Default, itemGenerationMode: 'paid_preferred');
        $paidCandidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextReasoning, $paidCtx);
        self::assertNotEmpty($paidCandidates);
        self::assertSame('deepseek-reasoner', $paidCandidates[0]->model);

        // Lock connection -> paid candidate blocked
        $conn->update(['paid_locked' => true, 'paid_lock_reasons' => [PaidLockReason::ManualFreeOnly->value]]);
        $this->targets->forgetMemo();
        $paidCandidatesLocked = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextReasoning, $paidCtx);
        self::assertEmpty($paidCandidatesLocked);
    }

    /**
     * Test E: Legacy Prompt with deleted connection (id=999), routing_mode=auto resolves via AI Switch.
     */
    public function test_test_e_legacy_prompt_with_deleted_connection_resolves_via_ai_switch(): void
    {
        $validConn = $this->createConnection(ApiConnectionProviders::OPENROUTER, 'Active Valid Connection', paidLocked: false);
        $model = $this->createModel($validConn, 'openai/gpt-4o', 'GPT-4o', isFree: false);
        $this->priorities->appendToArea(1, AiModelArea::TextReasoning, [(int) $model->id]);

        $legacyPrompt = SeoPrompt::query()->create([
            'id' => 501,
            'user_id' => 1,
            'ai_connection_id' => 999, // Non-existent deleted connection
            'routing_mode' => 'auto',
            'title' => 'Legacy Prompt Auto Routing',
            'status' => 'draft',
            'markdown_content' => 'Hello prompt',
            'tools' => 'default',
        ]);

        $runner = $this->app->make(PromptRunnerService::class);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('routingContextForPrompt');
        $method->setAccessible(true);

        // Connection is null (deleted), routing_mode is auto -> does not throw, builds valid routing context
        /** @var AiRoutingContext $ctx */
        $ctx = $method->invoke($runner, $legacyPrompt, null, false, []);
        self::assertInstanceOf(AiRoutingContext::class, $ctx);
        self::assertNull($ctx->legacyConnection);
        self::assertNull($ctx->explicitConnectionId);

        // AI Switch resolves candidate from active valid connection
        $candidate = $this->router->resolve(AiExecutionProfile::TextReasoning->value, $ctx);
        self::assertSame((int) $validConn->id, (int) $candidate->connection->id);
    }

    /**
     * Test F: Explicit Prompt with user choosing missing connection throws configuration error.
     */
    public function test_test_f_explicit_prompt_with_missing_connection_throws_clear_configuration_error(): void
    {
        $prompt = SeoPrompt::query()->create([
            'id' => 502,
            'ai_connection_id' => 888, // Missing
            'routing_mode' => 'override',
            'title' => 'Explicit Prompt Missing Connection',
            'status' => 'draft',
            'markdown_content' => 'Hello explicit',
            'tools' => 'default',
        ]);

        $runner = $this->app->make(PromptRunnerService::class);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('routingContextForPrompt');
        $method->setAccessible(true);

        $this->expectException(PromptRunException::class);
        $this->expectExceptionMessage('NO_AI_CONNECTION: Explicit connection [888] not found or inactive.');

        $method->invoke($runner, $prompt, null, false, []);
    }

    /**
     * Test G: Content Project Paid: Task variables resolve to paid_preferred and SinglePass shape.
     */
    public function test_test_g_content_project_paid_selects_paid_models_single_pass_shape(): void
    {
        $conn = $this->createConnection(ApiConnectionProviders::OPENROUTER, 'Active Connection', paidLocked: false);
        $freeModel = $this->createModel($conn, 'google/gemma-2-9b-it:free', 'Gemma Free', isFree: true, priority: 1);
        $paidModel = $this->createModel($conn, 'anthropic/claude-3.5-sonnet', 'Claude Sonnet', isFree: false, priority: 2);

        $this->priorities->appendToArea(1, AiModelArea::FreeModels, [(int) $freeModel->id]);
        $this->priorities->appendToArea(1, AiModelArea::TextLongform, [(int) $paidModel->id]);

        $prompt = SeoPrompt::query()->create([
            'id' => 503,
            'user_id' => 1,
            'ai_connection_id' => null,
            'routing_mode' => 'auto',
            'hook_key' => 'article.content.generate',
            'title' => 'Writing Prompt',
            'status' => 'active',
            'markdown_content' => 'Writing article',
            'tools' => 'default',
        ]);

        $runner = $this->app->make(PromptRunnerService::class);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('routingContextForPrompt');
        $method->setAccessible(true);

        /** @var AiRoutingContext $ctx */
        $ctx = $method->invoke($runner, $prompt, null, false, [
            'project_task_id' => '9298',
            'ai_generation_mode' => 'normal',
        ]);

        self::assertSame('paid_preferred', $ctx->itemGenerationMode);

        [$primary, $snapshot] = $this->app->make(ArticleGenerationExecutionPlanner::class)->plan(
            AiExecutionProfile::TextLongform->value,
            $ctx,
            ['project_task_id' => '9298', 'ai_generation_mode' => 'normal'],
        );

        self::assertFalse($primary->isFree, 'Content Project Paid must select Paid candidate');
        self::assertSame(ArticleGenerationShape::SinglePass, $snapshot->generationShape, 'Paid shape must be SinglePass');
    }

    /**
     * Test H: Content Project Free-Split: Task variables resolve to free_only and Sectioned shape.
     */
    public function test_test_h_content_project_free_split_selects_free_models_sectioned_shape(): void
    {
        $conn = $this->createConnection(ApiConnectionProviders::OPENROUTER, 'Active Connection', paidLocked: false);
        $freeModel = $this->createModel($conn, 'google/gemma-2-9b-it:free', 'Gemma Free', isFree: true, priority: 1);
        $paidModel = $this->createModel($conn, 'anthropic/claude-3.5-sonnet', 'Claude Sonnet', isFree: false, priority: 2);

        $this->priorities->appendToArea(1, AiModelArea::FreeModels, [(int) $freeModel->id]);
        $this->priorities->appendToArea(1, AiModelArea::TextLongform, [(int) $paidModel->id]);

        $prompt = SeoPrompt::query()->create([
            'id' => 504,
            'user_id' => 1,
            'ai_connection_id' => null,
            'routing_mode' => 'auto',
            'hook_key' => 'article.content.generate',
            'title' => 'Writing Prompt',
            'status' => 'active',
            'markdown_content' => 'Writing article',
            'tools' => 'default',
        ]);

        $runner = $this->app->make(PromptRunnerService::class);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('routingContextForPrompt');
        $method->setAccessible(true);

        /** @var AiRoutingContext $ctx */
        $ctx = $method->invoke($runner, $prompt, null, false, [
            'project_task_id' => '9297',
            'ai_cost_policy' => 'free_only',
        ]);

        self::assertTrue($ctx->isFreeOnly());

        [$primary, $snapshot] = $this->app->make(ArticleGenerationExecutionPlanner::class)->plan(
            AiExecutionProfile::TextLongform->value,
            $ctx,
            ['project_task_id' => '9297', 'ai_cost_policy' => 'free_only'],
        );

        self::assertTrue($primary->isFree, 'Content Project Free-Split must select Free candidate');
        self::assertSame(ArticleGenerationShape::Sectioned, $snapshot->generationShape, 'Free shape must be Sectioned');
    }

    /**
     * Test I: Global normal keeps default FreeFirstWithPaidFallback semantics.
     */
    public function test_test_i_global_normal_keeps_default_semantics(): void
    {
        $global = new AiRoutingContext(
            userId: 1,
            costPolicy: AiCostPolicy::Default,
            itemGenerationMode: 'normal',
        );

        self::assertSame(
            AiExecutionRoutingMode::FreeFirstWithPaidFallback,
            (new AiRoutingContextResolver)->resolveMode($global),
        );
    }

    /**
     * Test J: Missing Paid candidate in Paid mode throws routing exhaustion without falling back to Free.
     */
    public function test_test_j_missing_paid_candidate_in_paid_mode_fails_closed_no_free_fallback(): void
    {
        $conn = $this->createConnection(ApiConnectionProviders::OPENROUTER, 'Active Connection', paidLocked: false);
        $freeModel = $this->createModel($conn, 'google/gemma-2-9b-it:free', 'Gemma Free', isFree: true, priority: 1);

        $this->priorities->appendToArea(1, AiModelArea::FreeModels, [(int) $freeModel->id]);
        // Do not add any Paid model to TextLongform area

        $ctx = (new AiRoutingContextResolver)->enrich(new AiRoutingContext(
            userId: 1,
            costPolicy: AiCostPolicy::Default,
            itemGenerationMode: 'paid_preferred',
        ));

        $candidates = $this->targets->eligibleCandidates(1, AiExecutionProfile::TextLongform, $ctx);
        self::assertEmpty($candidates, 'No paid candidates available in PaidPreferred mode');

        [$plan, $survivors] = (new AiCandidatePlanner)->plan(
            profile: AiExecutionProfile::TextLongform->value,
            context: $ctx,
            candidates: $candidates,
            maxAiAttempts: 6,
            maxFreeAttempts: 3,
            healthSkipReason: fn () => null,
        );

        self::assertSame(AiExecutionRoutingMode::PaidPreferred, $plan->mode);
        self::assertEmpty($survivors, 'Survivors must be empty, no fallback to free models');
    }
}
