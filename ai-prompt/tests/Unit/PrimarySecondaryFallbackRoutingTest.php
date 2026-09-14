<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingPlan;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Services\AiFallbackAreaResolver;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner;
use Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * PRIMARY / SECONDARY fallback lanes — independent of SPLIT/SINGLE shape.
 */
final class PrimarySecondaryFallbackRoutingTest extends TestCase
{
    private AiModelRouterService $router;

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
        $health = new AiRuntimeHealthService(notifications: null);
        $this->router = new AiModelRouterService($registry, $targets, $bootstrap);

        $this->app->instance(AiProviderFailureClassifier::class, new AiProviderFailureClassifier());
        $this->app->instance(AiRuntimeHealthService::class, $health);
        $this->app->instance(AiResilienceSettingsService::class, new AiResilienceSettingsService());
    }

    public function test_resolver_ssot_mapping(): void
    {
        $resolver = new AiFallbackAreaResolver();
        $this->assertSame(AiModelArea::TextFast, $resolver->secondaryAreaFor(AiModelArea::TextLongform));
        $this->assertSame(AiModelArea::TextFast, $resolver->secondaryAreaFor(AiModelArea::TextReasoning));
        $this->assertSame(AiModelArea::TextFast, $resolver->secondaryAreaFor(AiModelArea::TextFast));
        $this->assertNull($resolver->secondaryAreaFor(AiModelArea::Image));
        $this->assertNull($resolver->secondaryAreaFor(AiModelArea::Video));
    }

    /** A — PAID-FIRST stays on PRIMARY sortable; secondary never opens */
    public function test_a_paid_first_primary_only_ignores_secondary(): void
    {
        $deepseek = $this->candidate('deepseek-long', false, 1, 'long_form_text');
        $claude = $this->candidate('claude-long', false, 2, 'long_form_text');
        $nano = $this->candidate('gpt-nano', false, 1, 'fast_text');

        [$plan, $ordered] = (new AiCandidatePlanner())->plan(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 1),
            [$deepseek, $claude],
            6,
            3,
            static fn (): ?string => null,
            modelArea: AiModelArea::TextLongform->value,
            secondaryCandidates: [$nano],
            primaryArea: AiModelArea::TextLongform->value,
            secondaryArea: AiModelArea::TextFast->value,
        );

        $this->assertSame(AiRoutingPlan::PATH_PRIMARY_ONLY, $plan->routingPath);
        $this->assertSame('paid', $plan->initialRouteCost);
        $this->assertSame([], $plan->secondaryPaidPhase);
        $this->assertSame(['deepseek-long', 'claude-long'], array_map(static fn ($c) => $c->model, $ordered));
        $this->assertNotContains('gpt-nano', array_map(static fn ($c) => $c->model, $ordered));
    }

    /** B — FREE-FIRST uses SECONDARY paid; PRIMARY paid siblings excluded */
    public function test_b_free_first_secondary_paid_excludes_primary_paid_siblings(): void
    {
        (new AiResilienceSettingsService())->save(301, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $or = $this->connection(301, ApiConnectionProviders::OPENROUTER, 'OR');
        $free = $this->model($or, 'openrouter/free-a:free', true);
        $longDs = $this->model($or, 'deepseek/deepseek-reasoner', false);
        $longClaude = $this->model($or, 'anthropic/claude-sonnet-4.6', false);
        $chat = $this->model($or, 'deepseek/deepseek-chat', false);
        $nano = $this->model($or, 'openai/gpt-5.4-nano', false);
        foreach ([$free, $longDs, $longClaude, $chat, $nano] as $m) {
            $this->grantText($or, $m);
        }
        app(AiModelPriorityService::class)->appendToArea(301, AiModelArea::TextLongform, [
            (int) $free->id,
            (int) $longDs->id,
            (int) $longClaude->id,
        ]);
        app(AiModelPriorityService::class)->appendToArea(301, AiModelArea::TextFast, [
            (int) $chat->id,
            (int) $nano->id,
        ]);

        $calls = [];
        [$output, $usage, $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 301, hookKey: 'article.content.generate'),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw new PromptRunException('503 upstream', 503);
                }

                return ['paid-ok', null];
            },
        );

        $this->assertSame(['openrouter/free-a:free', 'deepseek/deepseek-chat'], $calls);
        $this->assertSame('deepseek/deepseek-chat', $selected->model);
        $this->assertSame('paid-ok', $output);
        $this->assertNotContains('deepseek/deepseek-reasoner', $calls);
        $this->assertNotContains('anthropic/claude-sonnet-4.6', $calls);
        $plan = $usage['_routing_plan'] ?? [];
        $this->assertSame(AiRoutingPlan::PATH_FREE_PRIMARY_THEN_SECONDARY_PAID, $plan['routing_path'] ?? null);
        $this->assertSame(AiModelArea::TextLongform->value, $plan['primary_area'] ?? null);
        $this->assertSame(AiModelArea::TextFast->value, $plan['secondary_area'] ?? null);
    }

    /** C — FreeOnly: FREE exhausted ⇒ fail closed, zero paid attempts */
    public function test_c_free_only_never_opens_secondary_paid(): void
    {
        (new AiResilienceSettingsService())->save(302, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $or = $this->connection(302, ApiConnectionProviders::OPENROUTER, 'OR');
        $free = $this->model($or, 'openrouter/free-a:free', true);
        $longPaid = $this->model($or, 'deepseek/deepseek-reasoner', false);
        $fastPaid = $this->model($or, 'deepseek/deepseek-chat', false);
        foreach ([$free, $longPaid, $fastPaid] as $m) {
            $this->grantText($or, $m);
        }
        app(AiModelPriorityService::class)->appendToArea(302, AiModelArea::TextLongform, [
            (int) $free->id,
            (int) $longPaid->id,
        ]);
        app(AiModelPriorityService::class)->appendToArea(302, AiModelArea::TextFast, [(int) $fastPaid->id]);

        $calls = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 302, freeOnly: true, hookKey: 'article.content.generate'),
                function ($candidate) use (&$calls): array {
                    $calls[] = $candidate->model;
                    throw new PromptRunException('503', 503);
                },
            );
            $this->fail('Expected exhaustion');
        } catch (AiRoutesExhaustedException $e) {
            $this->assertSame(['openrouter/free-a:free'], $calls);
            $this->assertSame(0, (int) ($e->context['paid_attempts'] ?? -1));
            $this->assertSame(AiRoutingPlan::PATH_PRIMARY_ONLY, $e->context['routing_path'] ?? null);
            $this->assertNotContains('deepseek/deepseek-chat', $calls);
            $this->assertNotContains('deepseek/deepseek-reasoner', $calls);
        }
    }

    /** D — SECONDARY manual sortable order is authoritative */
    public function test_d_secondary_manual_order_preserved(): void
    {
        $free = $this->candidate('or-free', true, 1, 'long_form_text');
        $mini = $this->candidate('gpt-mini', false, 1, 'fast_text');
        $chat = $this->candidate('deepseek-chat', false, 2, 'fast_text');
        $haiku = $this->candidate('claude-haiku', false, 3, 'fast_text');

        [$plan, $ordered] = (new AiCandidatePlanner())->plan(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 1),
            [$free, $this->candidate('deepseek-long', false, 2, 'long_form_text')],
            8,
            4,
            static fn (): ?string => null,
            modelArea: AiModelArea::TextLongform->value,
            secondaryCandidates: [$mini, $chat, $haiku],
            primaryArea: AiModelArea::TextLongform->value,
            secondaryArea: AiModelArea::TextFast->value,
        );

        $this->assertSame(AiRoutingPlan::PATH_FREE_PRIMARY_THEN_SECONDARY_PAID, $plan->routingPath);
        $this->assertSame(
            ['or-free', 'gpt-mini', 'deepseek-chat', 'claude-haiku'],
            array_map(static fn ($c) => $c->model, $ordered),
        );
        $this->assertSame(
            ['gpt-mini', 'deepseek-chat', 'claude-haiku'],
            array_map(static fn ($r) => $r->providerModel, $plan->secondaryPaidPhase),
        );
    }

    /** E — generic free provider failure still enters secondary when allowed */
    public function test_e_generic_free_failure_enters_secondary(): void
    {
        (new AiResilienceSettingsService())->save(303, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $or = $this->connection(303, ApiConnectionProviders::OPENROUTER, 'OR');
        $free = $this->model($or, 'meta/llama:free', true);
        $fast = $this->model($or, 'openai/gpt-5.4-mini', false);
        $this->grantText($or, $free);
        $this->grantText($or, $fast);
        app(AiModelPriorityService::class)->appendToArea(303, AiModelArea::TextLongform, [(int) $free->id]);
        app(AiModelPriorityService::class)->appendToArea(303, AiModelArea::TextFast, [(int) $fast->id]);

        $calls = [];
        [, , $selected, , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 303),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw new PromptRunException('timeout waiting for upstream', 408);
                }

                return ['ok', null];
            },
        );

        $this->assertSame(['meta/llama:free', 'openai/gpt-5.4-mini'], $calls);
        $this->assertSame('openai/gpt-5.4-mini', $selected->model);
        $paid = collect($attempts)->firstWhere('model', 'openai/gpt-5.4-mini');
        $this->assertSame('secondary_paid', $paid['phase'] ?? null);
        $this->assertSame('primary_free_exhausted', $paid['lane_transition_reason'] ?? null);
    }

    /** F — daily free quota: Normal → secondary; FreeOnly → fail closed */
    public function test_f_daily_free_quota_normal_enters_secondary_free_only_closes(): void
    {
        (new AiResilienceSettingsService())->save(304, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $or = $this->connection(304, ApiConnectionProviders::OPENROUTER, 'OR');
        $free = $this->model($or, 'meta/llama:free', true);
        $fast = $this->model($or, 'openai/gpt-5.4-mini', false);
        $this->grantText($or, $free);
        $this->grantText($or, $fast);
        app(AiModelPriorityService::class)->appendToArea(304, AiModelArea::TextLongform, [(int) $free->id]);
        app(AiModelPriorityService::class)->appendToArea(304, AiModelArea::TextFast, [(int) $fast->id]);

        $calls = [];
        [, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 304),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->isFree) {
                    throw new PromptRunException('Rate limit exceeded: free-models-per-day. quota exceeded', 429);
                }

                return ['ok', null];
            },
        );
        $this->assertSame(['meta/llama:free', 'openai/gpt-5.4-mini'], $calls);
        $this->assertSame('openai/gpt-5.4-mini', $selected->model);

        AiRuntimeHealthService::clearSuppressedFreeLanes();
        (new AiResilienceSettingsService())->save(305, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $or2 = $this->connection(305, ApiConnectionProviders::OPENROUTER, 'OR2');
        $free2 = $this->model($or2, 'meta/llama2:free', true);
        $fast2 = $this->model($or2, 'openai/gpt-5.4-nano', false);
        $this->grantText($or2, $free2);
        $this->grantText($or2, $fast2);
        app(AiModelPriorityService::class)->appendToArea(305, AiModelArea::TextLongform, [(int) $free2->id]);
        app(AiModelPriorityService::class)->appendToArea(305, AiModelArea::TextFast, [(int) $fast2->id]);

        $calls2 = [];
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: 305, freeOnly: true),
                function ($candidate) use (&$calls2): array {
                    $calls2[] = $candidate->model;
                    throw new PromptRunException('Rate limit exceeded: free-models-per-day. quota exceeded', 429);
                },
            );
            $this->fail('Expected free-only exhaustion');
        } catch (AiRoutesExhaustedException $e) {
            $this->assertSame(['meta/llama2:free'], $calls2);
            $this->assertSame(0, (int) ($e->context['paid_attempts'] ?? -1));
        }
    }

    /** G — shape independence: FREE-FIRST ⇒ SPLIT snapshot; secondary does not recalc shape */
    public function test_g_shape_independent_of_secondary_lane(): void
    {
        $free = $this->candidate('nemotron-free', true, 1);
        $router = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $router->method('resolveFirstAttemptable')->willReturn($free);
        $planner = new ArticleGenerationExecutionPlanner($router, new GenerationShapeResolver($router));
        [, $snap] = $planner->plan('text.longform', new AiRoutingContext(userId: 1), []);

        $this->assertSame(ArticleGenerationShape::Sectioned, $snap->generationShape);

        [$plan] = (new AiCandidatePlanner())->plan(
            'text.longform',
            new AiRoutingContext(userId: 1),
            [$free],
            6,
            3,
            static fn (): ?string => null,
            secondaryCandidates: [$this->candidate('gpt-nano', false, 1, 'fast_text')],
            primaryArea: AiModelArea::TextLongform->value,
            secondaryArea: AiModelArea::TextFast->value,
        );
        $this->assertSame(AiRoutingPlan::PATH_FREE_PRIMARY_THEN_SECONDARY_PAID, $plan->routingPath);
        // Planner must not embed generation_shape — shape SSOT stays outside lane routing.
        $this->assertArrayNotHasKey('generation_shape', $plan->meta);
    }

    /** H — primary === secondary: dedupe physical routes across phases */
    public function test_h_same_area_dedupes_physical_routes(): void
    {
        $free = $this->candidate('or-free', true, 1, 'fast_text', connectionId: 20);
        $paid = $this->candidate('gpt-mini', false, 2, 'fast_text', connectionId: 21);
        $dupFree = $this->candidate('or-free', true, 3, 'fast_text', connectionId: 20);

        [$plan, $ordered] = (new AiCandidatePlanner())->plan(
            AiExecutionProfile::TextFast->value,
            new AiRoutingContext(userId: 1),
            [$free, $paid, $dupFree],
            6,
            3,
            static fn (): ?string => null,
            modelArea: AiModelArea::TextFast->value,
            secondaryCandidates: [$free, $paid, $dupFree],
            primaryArea: AiModelArea::TextFast->value,
            secondaryArea: AiModelArea::TextFast->value,
        );

        $this->assertSame(AiRoutingPlan::PATH_FREE_PRIMARY_THEN_SECONDARY_PAID, $plan->routingPath);
        $keys = array_map(static fn ($c) => $c->physicalRouteKey(), $ordered);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertSame(['or-free', 'gpt-mini'], array_map(static fn ($c) => $c->model, $ordered));
    }

    private function candidate(
        string $model,
        bool $isFree,
        int $priority,
        string $profile = 'text.longform',
        string $provider = 'openrouter',
        int $connectionId = 0,
    ): RoutedAiCandidate {
        $id = $connectionId > 0 ? $connectionId : (10 + $priority + ($isFree ? 0 : 50));
        $connection = new ApiConnection([
            'id' => $id,
            'name' => 'C'.$id,
            'provider' => $provider,
            'paid_locked' => false,
        ]);
        $connection->id = $id;

        return new RoutedAiCandidate(
            profile: $profile,
            connection: $connection,
            provider: $provider,
            model: $model,
            capabilities: [],
            priority: $priority,
            seoAiModelId: 100 + $priority + $id,
            isFree: $isFree,
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
