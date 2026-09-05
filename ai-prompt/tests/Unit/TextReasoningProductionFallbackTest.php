<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
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
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterTextRoutingCatalog;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Production TextReasoning (Outline/Vocabulary) candidate pool + fallback contracts.
 */
final class TextReasoningProductionFallbackTest extends TestCase
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

    public function test_a_catalog_membership_and_reasoning_pool_not_collapsed_to_one(): void
    {
        $resolver = new PromptExecutionProfileResolver();
        $this->assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, 'article.outline.structure.generate'),
        );
        $this->assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, 'article.vocabulary.generate'),
        );

        $this->assertContains(
            AiModelArea::TextReasoning,
            OpenRouterTextRoutingCatalog::membershipAreasForRaw('openai/gpt-5.4'),
        );
        $this->assertContains(
            AiModelArea::TextReasoning,
            OpenRouterTextRoutingCatalog::membershipAreasForRaw('anthropic/claude-sonnet-4.6'),
        );
        $this->assertContains(
            AiModelArea::TextReasoning,
            OpenRouterTextRoutingCatalog::membershipAreasForRaw('google/gemini-3.5-flash'),
        );

        $connection = $this->openRouter(900);
        $ids = [];
        foreach (OpenRouterTextRoutingCatalog::PROFILE_MODELS[AiExecutionProfile::TextReasoning->value] as $raw) {
            $model = $this->orModel($connection, $raw);
            $this->grantText($connection, $model);
            foreach (OpenRouterTextRoutingCatalog::membershipAreasForRaw($raw) as $area) {
                if (! $this->priorities->isExplicitlyAreaEnabled($model, $area)) {
                    $this->priorities->appendToArea(900, $area, [(int) $model->id]);
                }
            }
            $ids[] = (int) $model->id;
        }

        // Enabling Reasoning must not yank models off Fast/Longform.
        $flash = SeoAiModel::query()->where('raw_model_name', 'google/gemini-3.5-flash')->firstOrFail();
        $this->assertTrue($this->priorities->isAreaEnabled($flash, AiModelArea::TextFast, $connection));
        $this->assertTrue($this->priorities->isAreaEnabled($flash, AiModelArea::TextReasoning, $connection));

        $outlineCtx = new AiRoutingContext(
            userId: 900,
            costPolicy: AiCostPolicy::Default,
            hookKey: 'article.outline.structure.generate',
        );
        $vocabCtx = new AiRoutingContext(
            userId: 900,
            costPolicy: AiCostPolicy::Default,
            hookKey: 'article.vocabulary.generate',
        );
        $outline = $this->targets->eligibleCandidates(900, AiExecutionProfile::TextReasoning, $outlineCtx);
        $vocab = $this->targets->eligibleCandidates(900, AiExecutionProfile::TextReasoning, $vocabCtx);
        $outlineModels = array_map(static fn ($c): string => $c->model, $outline);
        $vocabModels = array_map(static fn ($c): string => $c->model, $vocab);

        $this->assertSame($outlineModels, $vocabModels);
        $this->assertGreaterThanOrEqual(2, count($outlineModels));
        $this->assertNotContains('deepseek/deepseek-v3.2', $outlineModels);
        $this->assertContains('google/gemini-3.1-pro-preview', $outlineModels);
        $this->assertTrue(
            count(array_intersect($outlineModels, [
                'openai/gpt-5.4',
                'anthropic/claude-sonnet-4.6',
                'google/gemini-3.5-flash',
                'openai/gpt-5.4-mini',
                'google/gemini-3.1-pro-preview',
            ])) >= 2,
        );
        unset($ids);
    }

    public function test_a2_legacy_exclusive_disable_is_repaired_by_multi_area_enable(): void
    {
        $connection = $this->openRouter(901);
        $gpt = $this->orModel($connection, 'openai/gpt-5.4');
        $claude = $this->orModel($connection, 'anthropic/claude-sonnet-4.6');
        $pro = $this->orModel($connection, 'google/gemini-3.1-pro-preview');
        foreach ([$gpt, $claude, $pro] as $model) {
            $this->grantText($connection, $model);
        }

        // Simulate pre-fix catalog: exclusive primary (only Pro on Reasoning).
        $this->priorities->appendToArea(901, AiModelArea::TextLongform, [(int) $gpt->id, (int) $claude->id]);
        $this->forceExclusiveDisable($gpt, AiModelArea::TextLongform);
        $this->forceExclusiveDisable($claude, AiModelArea::TextLongform);
        $this->priorities->appendToArea(901, AiModelArea::TextReasoning, [(int) $pro->id]);

        $before = array_map(
            static fn ($c): string => $c->model,
            $this->targets->eligibleCandidates(
                901,
                AiExecutionProfile::TextReasoning,
                new AiRoutingContext(userId: 901, hookKey: 'article.outline.structure.generate'),
            ),
        );
        $this->assertSame(['google/gemini-3.1-pro-preview'], $before);

        // Repair path used by catalog apply: enable PROFILE membership without disabling others.
        foreach ([$gpt, $claude] as $model) {
            $model->refresh();
            if (! $this->priorities->isExplicitlyAreaEnabled($model, AiModelArea::TextReasoning)) {
                $this->priorities->appendToArea(901, AiModelArea::TextReasoning, [(int) $model->id]);
            }
        }
        $this->targets->forgetMemo();

        $after = array_map(
            static fn ($c): string => $c->model,
            $this->targets->eligibleCandidates(
                901,
                AiExecutionProfile::TextReasoning,
                new AiRoutingContext(userId: 901, hookKey: 'article.outline.structure.generate'),
            ),
        );
        $this->assertGreaterThanOrEqual(3, count($after));
        $this->assertContains('openai/gpt-5.4', $after);
        $this->assertContains('anthropic/claude-sonnet-4.6', $after);
        $this->assertContains('google/gemini-3.1-pro-preview', $after);
        $gpt->refresh();
        $this->assertTrue($this->priorities->isAreaEnabled($gpt, AiModelArea::TextLongform, $connection));
        $this->assertTrue($this->priorities->isAreaEnabled($gpt, AiModelArea::TextReasoning, $connection));
    }

    public function test_b_first_candidate_429_then_second_succeeds(): void
    {
        $this->seedReasoningRoute(910, ['model/a', 'model/b', 'model/c']);
        $tried = [];
        [$output, , $used, $fallbackCount] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 910, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;
                if ($candidate->model === 'model/a') {
                    throw new PromptRunException('429 rate limit', 429);
                }

                return ['outline-ok', null];
            },
        );
        $this->assertSame('outline-ok', $output);
        $this->assertSame(['model/a', 'model/b'], $tried);
        $this->assertSame('model/b', $used->model);
        $this->assertSame(1, $fallbackCount);
    }

    public function test_c_first_candidate_cooldown_then_second_succeeds(): void
    {
        $models = $this->seedReasoningRoute(911, ['model/a', 'model/b', 'model/c']);
        $a = $models[0];
        $candidateA = $this->targets->eligibleCandidates(
            911,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 911),
        )[0];
        $this->assertSame('model/a', $candidateA->model);
        $this->health->recordFailure(911, $candidateA, new AiFailureDecision(
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
        $this->assertSame('model_cooldown', $this->health->skipReason(911, $candidateA));

        $tried = [];
        [$output, , , , , $routingAttempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 911, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['model/b'], $tried);
        $this->assertSame('skipped', $routingAttempts[0]['result'] ?? null);
        $this->assertSame('model_cooldown', $routingAttempts[0]['skip_reason'] ?? null);
        $this->assertSame('success', $routingAttempts[1]['result'] ?? null);
        unset($a);
    }

    public function test_d_multiple_failed_candidates_then_success(): void
    {
        $this->seedReasoningRoute(912, ['model/a', 'model/b', 'model/c']);
        $tried = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 912),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;

                return match ($candidate->model) {
                    'model/a' => throw new PromptRunException('429 rate limit', 429),
                    'model/b' => throw new PromptRunException('503 unavailable', 503),
                    default => ['ok', null],
                };
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['model/a', 'model/b', 'model/c'], $tried);
    }

    public function test_e_actual_exhaust_reports_attempt_count_three(): void
    {
        $this->seedReasoningRoute(913, ['model/a', 'model/b', 'model/c']);
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 913),
                function ($candidate): array {
                    return match ($candidate->model) {
                        'model/a' => throw new PromptRunException('429', 429),
                        'model/b' => throw new PromptRunException('503', 503),
                        default => throw new PromptRunException('402 credits', 402),
                    };
                },
            );
            $this->fail('Expected AiRoutesExhaustedException');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(3, $exception->context['attempt_count'] ?? null);
            $this->assertSame(3, $exception->context['eligible_count'] ?? null);
            $this->assertStringContainsString('3 AI attempt(s) failed', $exception->getMessage());
            $this->assertNotSame(1, $exception->context['attempt_count'] ?? null);
        }
    }

    public function test_f_max_ai_attempts_limits_attempts(): void
    {
        (new AiResilienceSettingsService())->save(914, ['max_ai_attempts' => 2, 'max_free_attempts' => 2]);
        $this->seedReasoningRoute(914, ['model/a', 'model/b', 'model/c']);
        $tried = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 914),
                function ($candidate) use (&$tried): array {
                    $tried[] = $candidate->model;
                    throw new PromptRunException('503', 503);
                },
            );
            $this->fail('Expected exhaust');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(['model/a', 'model/b'], $tried);
            $this->assertSame(2, $exception->context['attempt_count'] ?? null);
            $this->assertSame(2, $exception->context['max_ai_attempts'] ?? null);
        }
    }

    public function test_g_health_skip_does_not_consume_max_attempt(): void
    {
        (new AiResilienceSettingsService())->save(915, ['max_ai_attempts' => 2, 'max_free_attempts' => 2]);
        $models = $this->seedReasoningRoute(915, ['model/a', 'model/b', 'model/c']);
        $candidates = $this->targets->eligibleCandidates(
            915,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 915),
        );
        $this->health->recordFailure(915, $candidates[0], new AiFailureDecision(
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

        $tried = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 915),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;
                if ($candidate->model === 'model/b') {
                    throw new PromptRunException('503', 503);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['model/b', 'model/c'], $tried);
        unset($models);
    }

    public function test_h_content_project_handlers_do_not_inject_free_only(): void
    {
        $handlers = [
            dirname(__DIR__, 3).'/content-projects/src/Services/ContentProject/Application/Handlers/GenerateProjectItemsHandler.php',
            dirname(__DIR__, 3).'/content-projects/src/Services/ContentProject/Application/Handlers/RerunProjectItemsHandler.php',
            dirname(__DIR__, 3).'/content-projects/src/Services/ContentProject/Application/Handlers/RerunProjectItemStepHandler.php',
        ];
        foreach ($handlers as $path) {
            $this->assertFileExists($path);
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString('AiCostPolicy::FreeOnly', $src, $path);
        }
    }

    public function test_i_outline_vocabulary_split_survives_first_candidate_429(): void
    {
        $this->seedReasoningRoute(916, ['outline/a', 'outline/b']);
        $outlineTried = [];
        [$outlineOut, , , $outlineFallback] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 916, hookKey: ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK),
            function ($candidate) use (&$outlineTried): array {
                $outlineTried[] = $candidate->model;
                if ($candidate->model === 'outline/a') {
                    throw new PromptRunException('429 rate limit', 429);
                }

                return ["## Outline body\n\n### Section one with enough detail for validation path.", null];
            },
        );
        $this->assertSame(['outline/a', 'outline/b'], $outlineTried);
        $this->assertSame(1, $outlineFallback);
        $this->assertStringContainsString('Outline body', $outlineOut);

        $vocabTried = [];
        [$vocabOut] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 916, hookKey: ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK),
            function ($candidate) use (&$vocabTried): array {
                $vocabTried[] = $candidate->model;

                return ["### Holonymy\n- bag: container\n", null];
            },
        );
        $this->assertNotSame([], $vocabTried);
        $this->assertStringContainsString('Holonymy', $vocabOut);
    }

    public function test_append_to_reasoning_does_not_disable_other_text_areas(): void
    {
        $connection = $this->openRouter(920);
        $model = $this->orModel($connection, 'openai/gpt-5.4');
        $this->grantText($connection, $model);
        $this->priorities->appendToArea(920, AiModelArea::TextFast, [(int) $model->id]);
        $this->priorities->appendToArea(920, AiModelArea::TextLongform, [(int) $model->id]);
        $this->priorities->appendToArea(920, AiModelArea::TextReasoning, [(int) $model->id]);
        $model->refresh();
        $this->assertTrue($this->priorities->isAreaEnabled($model, AiModelArea::TextFast, $connection));
        $this->assertTrue($this->priorities->isAreaEnabled($model, AiModelArea::TextLongform, $connection));
        $this->assertTrue($this->priorities->isAreaEnabled($model, AiModelArea::TextReasoning, $connection));
    }

    /**
     * @param  list<string>  $raws
     * @return list<SeoAiModel>
     */
    private function seedReasoningRoute(int $userId, array $raws): array
    {
        $connection = $this->openRouter($userId);
        $models = [];
        $ids = [];
        foreach ($raws as $raw) {
            $model = $this->orModel($connection, $raw);
            $this->grantText($connection, $model);
            $models[] = $model;
            $ids[] = (int) $model->id;
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, $ids);

        return $models;
    }

    private function openRouter(int $userId): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter '.$userId,
            'api_key' => 'test-key',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function orModel(ApiConnection $connection, string $raw): SeoAiModel
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
                    'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
                    'architecture' => ['modality' => 'text->text'],
                ],
            ],
        ]);
    }

    private function grantText(ApiConnection $connection, SeoAiModel $model): void
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

    /**
     * Reproduce pre-fix writeAreaState exclusive-disable side effect.
     */
    private function forceExclusiveDisable(SeoAiModel $model, AiModelArea $keep): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        foreach (AiModelArea::textPrimaryCases() as $area) {
            $bag = is_array($areas[$area->value] ?? null) ? $areas[$area->value] : [];
            $areas[$area->value] = [
                'enabled' => $area === $keep,
                'priority' => (int) ($bag['priority'] ?? 1),
                'source' => AiModelArea::SOURCE_MANUAL,
            ];
        }
        $caps[AiModelPriorityService::AREAS_KEY] = $areas;
        $caps[AiModelArea::PRIMARY_TYPE_KEY] = $keep->value;
        $caps[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] = AiModelArea::SOURCE_MANUAL;
        $model->capabilities = $caps;
        $model->save();
        $this->priorities->forgetMemo();
    }
}
