<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiAttemptBudgetPolicy;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use ReflectionClass;
use Tests\TestCase;

final class OpenRouterFreeOnlyRoutingRegressionTest extends TestCase
{
    private AiModelPriorityService $priorities;
    private AiRoutingTargetService $targets;
    private AiModelRouterService $router;
    private AiRuntimeHealthService $health;

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

    /**
     * Case A:
     * only OpenRouter FREE enabled + FreeOnly=true => SUCCESS / route plan exists.
     */
    public function test_case_a_only_openrouter_free_enabled_with_free_only_creates_valid_plan(): void
    {
        $userId = 2001;
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter Free Connection',
            'api_key' => 'sk-or-v1-valid-free',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);

        $freeModel = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'google/gemma-2-9b-it:free',
            'display_name' => 'Gemma 2 9B Free',
            'priority' => 1,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'provider_metadata' => [
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                    'architecture' => ['modality' => 'text->text'],
                ],
            ],
        ]);

        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $freeModel->id,
                'model_key' => $freeModel->raw_model_name,
                'capability' => $cap,
                'enabled' => true,
            ]);
        }

        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, [(int) $freeModel->id]);

        $context = new AiRoutingContext(
            userId: $userId,
            freeOnly: true,
            costPolicy: AiCostPolicy::FreeOnly,
            hookKey: 'article.outline.structure.generate',
        );

        $candidates = $this->targets->eligibleCandidates($userId, AiExecutionProfile::TextReasoning, $context);
        self::assertNotEmpty($candidates, 'OpenRouter FREE model must be eligible');
        self::assertTrue($candidates[0]->isFree);

        [$plan, $ordered] = (new AiCandidatePlanner())->plan(
            profile: AiExecutionProfile::TextReasoning->value,
            context: $context,
            candidates: $candidates,
            maxAiAttempts: 6,
            maxFreeAttempts: 3,
            healthSkipReason: fn () => null,
        );

        self::assertSame(AiExecutionRoutingMode::FreeOnly, $plan->mode);
        self::assertNotEmpty($plan->executionOrder);
        self::assertCount(1, $plan->executionOrder);
        self::assertSame('google/gemma-2-9b-it:free', $plan->executionOrder[0]->providerModel);
        self::assertSame('free', $plan->executionOrder[0]->costClass);
        self::assertSame(0, $plan->budget['reserved_paid_slots']);
        self::assertGreaterThanOrEqual(1, $plan->budget['free_budget']);
    }

    /**
     * Case B:
     * paid disabled + OpenRouter FREE enabled + article.outline => route không bị filtered.
     */
    public function test_case_b_paid_disabled_openrouter_free_outline_not_filtered(): void
    {
        $userId = 2002;
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter Mixed',
            'api_key' => 'sk-or-v1-test',
            'status' => 'active',
            'is_global' => false,
        ]);

        // Paid model: DISABLED
        SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'openai/gpt-4o',
            'display_name' => 'GPT-4o Paid',
            'priority' => 10,
            'status' => SeoAiModel::STATUS_INACTIVE,
            'capabilities' => ['provider_metadata' => ['pricing' => ['prompt' => '0.005']]],
        ]);

        // Free model: ENABLED
        $freeModel = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'meta-llama/llama-3.3-70b-instruct:free',
            'display_name' => 'Llama 3.3 70B Free',
            'priority' => 20,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'provider_metadata' => [
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                    'architecture' => ['modality' => 'text->text'],
                ],
            ],
        ]);

        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $freeModel->id,
                'model_key' => $freeModel->raw_model_name,
                'capability' => $cap,
                'enabled' => true,
            ]);
        }

        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, [(int) $freeModel->id]);

        $context = new AiRoutingContext(
            userId: $userId,
            freeOnly: true,
            costPolicy: AiCostPolicy::FreeOnly,
            hookKey: 'article.outline.structure.generate',
        );

        $candidates = $this->targets->eligibleCandidates($userId, AiExecutionProfile::TextReasoning, $context);
        self::assertCount(1, $candidates);
        self::assertSame('meta-llama/llama-3.3-70b-instruct:free', $candidates[0]->model);
        self::assertTrue($candidates[0]->isFree);
    }

    /**
     * Case C:
     * first usable route FREE => SPLIT.
     */
    public function test_case_c_first_usable_route_free_yields_split(): void
    {
        $userId = 2003;
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter Free',
            'api_key' => 'sk-or-v1-test',
            'status' => 'active',
            'is_global' => false,
        ]);

        $freeModel = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'google/gemma-2-9b-it:free',
            'display_name' => 'Gemma 2 9B Free',
            'priority' => 1,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => ['provider_metadata' => ['pricing' => ['prompt' => '0', 'completion' => '0']]],
        ]);

        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $freeModel->id,
                'model_key' => $freeModel->raw_model_name,
                'capability' => $cap,
                'enabled' => true,
            ]);
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, [(int) $freeModel->id]);

        $context = new AiRoutingContext(
            userId: $userId,
            freeOnly: true,
            costPolicy: AiCostPolicy::FreeOnly,
            hookKey: 'article.outline.structure.generate',
        );

        $shapeResolver = new GenerationShapeResolver($this->router);
        $decision = $shapeResolver->resolveDecision(AiExecutionProfile::TextReasoning->value, $context);

        self::assertSame(ArticleGenerationShape::Sectioned, $decision->shape, 'First usable route FREE must resolve to Sectioned (SPLIT)');
        self::assertSame(ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO, $decision->source);
        self::assertTrue($decision->isFree);
    }

    /**
     * Case D:
     * FreeOnly=true => zero paid attempts.
     */
    public function test_case_d_free_only_mode_zero_paid_attempts(): void
    {
        $userId = 2004;
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter Mixed',
            'api_key' => 'sk-or-v1-test',
            'status' => 'active',
            'is_global' => false,
        ]);

        // Paid model: ENABLED
        $paidModel = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'openai/gpt-4o',
            'display_name' => 'GPT-4o Paid',
            'priority' => 1,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => ['provider_metadata' => ['pricing' => ['prompt' => '0.005', 'completion' => '0.015']]],
        ]);

        // Free model: ENABLED
        $freeModel = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'google/gemma-2-9b-it:free',
            'display_name' => 'Gemma 2 9B Free',
            'priority' => 2,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => ['provider_metadata' => ['pricing' => ['prompt' => '0', 'completion' => '0']]],
        ]);

        foreach ([$paidModel, $freeModel] as $m) {
            foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
                AiModelCapabilityRow::query()->create([
                    'api_connection_id' => $connection->id,
                    'seo_ai_model_id' => $m->id,
                    'model_key' => $m->raw_model_name,
                    'capability' => $cap,
                    'enabled' => true,
                ]);
            }
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, [(int) $paidModel->id, (int) $freeModel->id]);

        $context = new AiRoutingContext(
            userId: $userId,
            freeOnly: true,
            costPolicy: AiCostPolicy::FreeOnly,
            hookKey: 'article.outline.structure.generate',
        );

        $attemptedModels = [];
        [$output, , $candidate, , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            $context,
            function ($c) use (&$attemptedModels): array {
                $attemptedModels[] = $c->model;
                return ['Generated content', null];
            }
        );

        self::assertSame('google/gemma-2-9b-it:free', $candidate->model);
        self::assertContains('google/gemma-2-9b-it:free', $attemptedModels);
        self::assertNotContains('openai/gpt-4o', $attemptedModels, 'Paid model must never be attempted when FreeOnly=true');

        foreach ($routingAttempts as $attempt) {
            if (isset($attempt['model'])) {
                self::assertNotSame('openai/gpt-4o', $attempt['model']);
            }
        }
    }

    /**
     * Case E:
     * bulk Content Project job giữ nguyên routing context sau queue dispatch.
     */
    public function test_case_e_bulk_content_project_job_preserves_routing_context(): void
    {
        $genCommand = new GenerateProjectItemsCommand(
            projectRef: 123,
            itemRefs: [1, 2, 3],
            settings: [
                AiCostPolicy::SETTING_KEY => AiCostPolicy::FreeOnly->value,
                'ai_generation_mode' => 'free_only',
                'generate_post_images' => true,
            ],
        );

        self::assertSame('free_only', $genCommand->settings[AiCostPolicy::SETTING_KEY]);

        $resumeCommand = new ResumeProjectItemFromFailedStepCommand(
            projectRef: 123,
            itemRefs: [1, 2],
            mode: 'full',
            settings: $genCommand->settings,
        );

        self::assertSame('free_only', $resumeCommand->settings[AiCostPolicy::SETTING_KEY]);

        $rerunCommand = new RerunProjectItemStepCommand(
            projectRef: 123,
            itemRefs: [1],
            fromStep: \Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep::Outline,
            settings: $resumeCommand->settings,
        );

        self::assertSame('free_only', $rerunCommand->settings[AiCostPolicy::SETTING_KEY]);

        $resolvedPolicy = ArticleGenerationModePreference::resolveForRun($rerunCommand->settings, 999);
        self::assertSame(AiCostPolicy::FreeOnly, $resolvedPolicy);

        $scopePolicy = AiCostPolicyScope::run($resolvedPolicy, function () {
            return AiCostPolicyScope::current();
        });
        self::assertSame(AiCostPolicy::FreeOnly, $scopePolicy);
    }

    /**
     * Case F:
     * không có eligible FREE route thật sự => fail đúng taxonomy/reason, không tạo paid attempt.
     */
    public function test_case_f_no_eligible_free_route_fails_closed_zero_paid_attempts(): void
    {
        $userId = 2005;
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter Paid Only',
            'api_key' => 'sk-or-v1-test',
            'status' => 'active',
            'is_global' => false,
        ]);

        // Paid model: ENABLED
        $paidModel = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'anthropic/claude-3.5-sonnet',
            'display_name' => 'Claude 3.5 Sonnet',
            'priority' => 1,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => ['provider_metadata' => ['pricing' => ['prompt' => '0.003', 'completion' => '0.015']]],
        ]);

        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $paidModel->id,
                'model_key' => $paidModel->raw_model_name,
                'capability' => $cap,
                'enabled' => true,
            ]);
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, [(int) $paidModel->id]);

        $context = new AiRoutingContext(
            userId: $userId,
            freeOnly: true,
            costPolicy: AiCostPolicy::FreeOnly,
            hookKey: 'article.outline.structure.generate',
        );

        $paidAttempted = false;
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                $context,
                function ($c) use (&$paidAttempted): array {
                    $paidAttempted = true;
                    return ['Never reached', null];
                }
            );
            self::fail('Expected AiRoutingException was not thrown');
        } catch (AiRoutingException $e) {
            self::assertFalse($paidAttempted, 'Zero paid attempts allowed in FreeOnly mode');
            self::assertSame('NO_VALID_FREE_CONNECTION', $e->context['failure_code'] ?? null);
        }
    }

    /**
     * Case G:
     * manual route ordering không bị thay đổi.
     */
    public function test_case_g_manual_route_ordering_is_preserved_authoritative(): void
    {
        $userId = 2006;
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter Multi-Free',
            'api_key' => 'sk-or-v1-test',
            'status' => 'active',
            'is_global' => false,
        ]);

        $freeModelA = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'google/gemma-2-9b-it:free',
            'display_name' => 'Gemma 2 9B (Priority 1)',
            'priority' => 1,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => ['provider_metadata' => ['pricing' => ['prompt' => '0', 'completion' => '0']]],
        ]);

        $freeModelB = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'meta-llama/llama-3.3-70b-instruct:free',
            'display_name' => 'Llama 3.3 70B (Priority 2)',
            'priority' => 2,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => ['provider_metadata' => ['pricing' => ['prompt' => '0', 'completion' => '0']]],
        ]);

        foreach ([$freeModelA, $freeModelB] as $m) {
            foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $cap) {
                AiModelCapabilityRow::query()->create([
                    'api_connection_id' => $connection->id,
                    'seo_ai_model_id' => $m->id,
                    'model_key' => $m->raw_model_name,
                    'capability' => $cap,
                    'enabled' => true,
                ]);
            }
        }

        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, [(int) $freeModelA->id, (int) $freeModelB->id]);

        $context = new AiRoutingContext(
            userId: $userId,
            freeOnly: true,
            costPolicy: AiCostPolicy::FreeOnly,
            hookKey: 'article.outline.structure.generate',
        );

        $candidates = $this->targets->eligibleCandidates($userId, AiExecutionProfile::TextReasoning, $context);
        self::assertCount(2, $candidates);
        self::assertSame('google/gemma-2-9b-it:free', $candidates[0]->model);
        self::assertSame('meta-llama/llama-3.3-70b-instruct:free', $candidates[1]->model);

        [$plan] = (new AiCandidatePlanner())->plan(
            profile: AiExecutionProfile::TextReasoning->value,
            context: $context,
            candidates: $candidates,
            maxAiAttempts: 6,
            maxFreeAttempts: 3,
            healthSkipReason: fn () => null,
        );

        self::assertCount(2, $plan->executionOrder);
        self::assertSame('google/gemma-2-9b-it:free', $plan->executionOrder[0]->providerModel);
        self::assertSame('meta-llama/llama-3.3-70b-instruct:free', $plan->executionOrder[1]->providerModel);
    }

    /**
     * Case H:
     * Content Project UI không còn "Generate working items".
     */
    public function test_case_h_content_project_ui_no_longer_has_generate_working_items_button(): void
    {
        $viewFile = (string) (new ReflectionClass(ViewSeoProject::class))->getFileName();
        $viewSource = (string) file_get_contents($viewFile);

        self::assertStringNotContainsString(
            'SeoProjectResource::makeGeneratePendingItemsAction',
            $viewSource,
            'Header must not contain the duplicate legacy makeGeneratePendingItemsAction button'
        );

        self::assertStringContainsString('makeCreateWithAiAction', $viewSource);
        self::assertStringContainsString('makeCreateWithAiModeGroup', $viewSource);
    }
}
