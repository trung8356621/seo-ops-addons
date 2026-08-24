<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\CanonicalAiRouteResolver;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiCapabilitySource;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Contract: Models manual order = Routing UI = runtime candidates.
 * Free is metadata. No outside-route models. Strategy does not reorder at runtime.
 */
final class LongFormRoutingContractTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRoutingTargetService $targets;

    private CanonicalAiRouteResolver $canonical;

    private AiModelRouterService $router;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections'] as $table) {
            Schema::dropIfExists($table);
        }
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

        $registry = new ModelCapabilityRegistry();
        $this->priorities = new AiModelPriorityService();
        $this->targets = new AiRoutingTargetService($registry, priorities: $this->priorities);
        $this->canonical = new CanonicalAiRouteResolver($this->targets);
        $bootstrap = new AiRoutingBootstrapService($registry, $this->targets);
        $this->router = new AiModelRouterService($registry, $this->targets, $bootstrap);
    }

    public function test_a_exact_manual_order(): void
    {
        $ids = $this->seedOrderedLongform(40, [
            ['claude', 'claude-sonnet-4-20250514', ApiConnectionProviders::CLAUDE, false],
            ['gemini-free', 'google/gemini-2.5-flash:free', ApiConnectionProviders::OPENROUTER, true],
            ['deepseek', 'deepseek-chat', ApiConnectionProviders::DEEPSEEK, false],
            ['gemini-pro', 'gemini-3.1-pro-preview', ApiConnectionProviders::GEMINI, false],
        ]);
        $this->assertSame(
            ['claude-sonnet-4-20250514', 'google/gemini-2.5-flash:free', 'deepseek-chat', 'gemini-3.1-pro-preview'],
            $this->runtimeModels(40),
        );
        unset($ids);
    }

    public function test_b_economy_does_not_override_manual_order(): void
    {
        $this->seedPaidFreeAlternating(41);
        $this->targets->writeProfileSettings(41, AiExecutionProfile::TextLongform, [
            'usage_mode' => AiUsageMode::Economy->value,
            'preserve_explicit_order' => true,
        ]);
        $this->assertSame(
            ['anthropic/claude-sonnet-4.6', 'google/gemma-3-12b-it:free', 'openai/gpt-5.4', 'google/gemma-3-27b-it:free'],
            $this->runtimeModels(41),
        );
    }

    public function test_c_quality_first_does_not_override_manual_order(): void
    {
        $this->seedPaidFreeAlternating(42);
        $this->targets->writeProfileSettings(42, AiExecutionProfile::TextLongform, [
            'usage_mode' => AiUsageMode::QualityFirst->value,
            'preserve_explicit_order' => true,
        ]);
        $this->assertSame(
            ['anthropic/claude-sonnet-4.6', 'google/gemma-3-12b-it:free', 'openai/gpt-5.4', 'google/gemma-3-27b-it:free'],
            $this->runtimeModels(42),
        );
    }

    public function test_d_retry_follows_next_route_position(): void
    {
        $this->seedOrderedLongform(43, [
            ['a', 'deepseek-chat', ApiConnectionProviders::DEEPSEEK, false],
            ['b', 'gemini-3-flash-preview', ApiConnectionProviders::GEMINI, false],
            ['c', 'claude-sonnet-4-20250514', ApiConnectionProviders::CLAUDE, false],
        ]);
        $tried = [];
        [$output, , $used, $fallbackCount] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 43),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;
                if (count($tried) < 3) {
                    throw new PromptRunException('429 rate limit', 429);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['deepseek-chat', 'gemini-3-flash-preview', 'claude-sonnet-4-20250514'], $tried);
        $this->assertSame('claude-sonnet-4-20250514', $used->model);
        $this->assertSame(2, $fallbackCount);
        $this->assertNotContains('nvidia/nemotron-3-ultra-550b-a55b:free', $tried);
    }

    public function test_e_outside_route_nvidia_never_attempted(): void
    {
        $openrouter = $this->connection(44, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $onRoute = $this->model($openrouter, 'anthropic/claude-sonnet-4.6', false);
        $nvidia = $this->model($openrouter, 'nvidia/nemotron-3-ultra-550b-a55b:free', true);
        $this->grantText($openrouter, $onRoute);
        $this->grantText($openrouter, $nvidia);
        $this->priorities->appendToArea(44, AiModelArea::TextLongform, [(int) $onRoute->id]);
        // NVIDIA exists in inventory but was never Add'd to Long-form.
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($nvidia->fresh(), AiModelArea::TextLongform));

        foreach ([AiCostPolicy::Default, AiCostPolicy::FreeOnly] as $policy) {
            $models = array_map(
                static fn ($c): string => $c->model,
                $this->targets->eligibleCandidates(
                    44,
                    AiExecutionProfile::TextLongform,
                    new AiRoutingContext(userId: 44, costPolicy: $policy),
                ),
            );
            $this->assertNotContains('nvidia/nemotron-3-ultra-550b-a55b:free', $models);
        }

        $default = $this->runtimeModels(44);
        $this->assertSame(['anthropic/claude-sonnet-4.6'], $default);

        $freeOnly = array_map(
            static fn ($c): string => $c->model,
            $this->targets->eligibleCandidates(
                44,
                AiExecutionProfile::TextLongform,
                new AiRoutingContext(userId: 44, costPolicy: AiCostPolicy::FreeOnly),
            ),
        );
        $this->assertSame([], $freeOnly);
    }

    public function test_f_provider_variants_keep_separate_positions(): void
    {
        $gemini = $this->connection(45, ApiConnectionProviders::GEMINI, 'Gemini');
        $openrouter = $this->connection(45, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $native = $this->model($gemini, 'gemini-3-flash-preview', false);
        $viaOr = $this->model($openrouter, 'google/gemini-3-flash-preview', false);
        $this->grantText($gemini, $native);
        $this->grantText($openrouter, $viaOr);
        $this->priorities->appendToArea(45, AiModelArea::TextLongform, [(int) $viaOr->id, (int) $native->id]);
        $this->assertSame(
            ['google/gemini-3-flash-preview', 'gemini-3-flash-preview'],
            $this->runtimeModels(45),
        );
    }

    public function test_g_disabled_entry_skipped_order_preserved(): void
    {
        $deepseek = $this->connection(46, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(46, ApiConnectionProviders::GEMINI, 'Gemini');
        $claude = $this->connection(46, ApiConnectionProviders::CLAUDE, 'Claude');
        $a = $this->model($deepseek, 'deepseek-chat', false);
        $b = $this->model($gemini, 'gemini-3.1-pro-preview', false);
        $c = $this->model($claude, 'claude-sonnet-4-20250514', false);
        $this->grantText($deepseek, $a);
        $this->grantText($gemini, $b);
        $this->grantText($claude, $c);
        $this->priorities->appendToArea(46, AiModelArea::TextLongform, [(int) $a->id, (int) $b->id, (int) $c->id]);
        $this->priorities->removeFromArea(46, AiModelArea::TextLongform, [(int) $b->id]);
        $this->assertSame(['deepseek-chat', 'claude-sonnet-4-20250514'], $this->runtimeModels(46));
    }

    public function test_h_routing_ui_equals_models_and_runtime(): void
    {
        $this->seedPaidFreeAlternating(47);
        $modelsUi = array_map(
            static fn (SeoAiModel $m): string => (string) $m->raw_model_name,
            $this->priorities->areaEnabledModels(47, AiModelArea::TextLongform),
        );
        $routingUi = array_map(
            static fn ($c): string => $c->model,
            $this->canonical->resolveRoute(47, AiExecutionProfile::TextLongform),
        );
        $runtime = $this->runtimeModels(47);
        $this->assertSame($modelsUi, $routingUi);
        $this->assertSame($routingUi, $runtime);
    }

    public function test_i_article_content_generate_maps_to_longform_route(): void
    {
        $this->seedPaidFreeAlternating(48);
        $profile = (new PromptExecutionProfileResolver())->resolve(null, 'article.content.generate');
        $this->assertSame(AiExecutionProfile::TextLongform, $profile);
        $aliases = [
            'article.content.generate',
            'article.content.rewrite',
            'article.content.translate',
            'article.content.improve',
        ];
        foreach ($aliases as $hook) {
            $resolved = (new PromptExecutionProfileResolver())->resolve(null, $hook);
            $this->assertSame(AiExecutionProfile::TextLongform, $resolved);
            $this->assertSame(
                $this->runtimeModels(48),
                array_map(
                    static fn ($c): string => $c->model,
                    $this->router->resolveAll($resolved->value, new AiRoutingContext(userId: 48)),
                ),
            );
        }
    }

    public function test_j_cache_invalidation_after_reorder(): void
    {
        $deepseek = $this->connection(49, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(49, ApiConnectionProviders::GEMINI, 'Gemini');
        $claude = $this->connection(49, ApiConnectionProviders::CLAUDE, 'Claude');
        $a = $this->model($deepseek, 'deepseek-chat', false);
        $b = $this->model($gemini, 'gemini-3.1-pro-preview', false);
        $c = $this->model($claude, 'claude-sonnet-4-20250514', false);
        $this->grantText($deepseek, $a);
        $this->grantText($gemini, $b);
        $this->grantText($claude, $c);
        $this->priorities->appendToArea(49, AiModelArea::TextLongform, [(int) $a->id, (int) $b->id, (int) $c->id]);
        $rev1 = $this->canonical->routeRevision(49, AiExecutionProfile::TextLongform);
        $this->assertSame(
            ['deepseek-chat', 'gemini-3.1-pro-preview', 'claude-sonnet-4-20250514'],
            $this->runtimeModels(49),
        );

        $this->priorities->reorderCapabilityModels(49, AiModelArea::TextLongform, [
            (int) $c->id,
            (int) $a->id,
            (int) $b->id,
        ]);
        $this->targets->forgetMemo();
        $rev2 = $this->canonical->routeRevision(49, AiExecutionProfile::TextLongform);
        $this->assertNotSame($rev1, $rev2);
        $this->assertSame(
            ['claude-sonnet-4-20250514', 'deepseek-chat', 'gemini-3.1-pro-preview'],
            $this->runtimeModels(49),
        );
    }

    public function test_free_only_preserves_manual_order_subset(): void
    {
        $this->seedPaidFreeAlternating(50);
        $resolved = $this->targets->eligibleCandidates(
            50,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 50, costPolicy: AiCostPolicy::FreeOnly),
        );
        $this->assertSame(
            ['google/gemma-3-12b-it:free', 'google/gemma-3-27b-it:free'],
            array_map(static fn ($c): string => $c->model, $resolved),
        );
    }

    public function test_claude_first_does_not_try_nemotron_when_claude_succeeds(): void
    {
        $openrouter = $this->connection(51, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $claude = $this->model($openrouter, 'anthropic/claude-sonnet-4.6', false);
        $free = $this->model($openrouter, 'nvidia/nemotron-3-ultra-550b-a55b:free', true);
        $this->grantText($openrouter, $claude);
        $this->grantText($openrouter, $free);
        $this->priorities->appendToArea(51, AiModelArea::TextLongform, [(int) $claude->id, (int) $free->id]);

        $tried = [];
        [$output, , $used] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 51, costPolicy: AiCostPolicy::Default),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['anthropic/claude-sonnet-4.6'], $tried);
        $this->assertSame('anthropic/claude-sonnet-4.6', $used->model);
    }

    public function test_infrastructure_fail_steps_to_next_not_free_skip(): void
    {
        $openrouter = $this->connection(52, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $claude = $this->model($openrouter, 'anthropic/claude-sonnet-4.6', false);
        $gemini = $this->model($openrouter, 'google/gemini-2.5-flash', false);
        $free = $this->model($openrouter, 'nvidia/nemotron-3-ultra-550b-a55b:free', true);
        $this->grantText($openrouter, $claude);
        $this->grantText($openrouter, $gemini);
        $this->grantText($openrouter, $free);
        $this->priorities->appendToArea(52, AiModelArea::TextLongform, [(int) $claude->id, (int) $gemini->id, (int) $free->id]);

        $tried = [];
        [$output, , $used] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 52),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;
                if ($candidate->model === 'anthropic/claude-sonnet-4.6') {
                    throw new PromptRunException('429 rate limit', 429);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['anthropic/claude-sonnet-4.6', 'google/gemini-2.5-flash'], $tried);
        $this->assertSame('google/gemini-2.5-flash', $used->model);
    }

    public function test_text_membership_filter_does_not_remove_manual_route_models(): void
    {
        $this->seedOrderedLongform(53, [
            ['claude', 'anthropic/claude-sonnet-4.6', ApiConnectionProviders::OPENROUTER, false],
            ['free', 'google/gemma-3-12b-it:free', ApiConnectionProviders::OPENROUTER, true],
        ]);
        $this->targets->writeProfileSettings(53, AiExecutionProfile::TextLongform, [
            'allowed_execution_keys' => ['999|nonexistent-family'],
            'allowed_family_keys' => ['nonexistent-family'],
        ]);
        $this->assertSame(
            ['anthropic/claude-sonnet-4.6', 'google/gemma-3-12b-it:free'],
            $this->runtimeModels(53),
        );
    }

    public function test_reasoning_free_does_not_leak_into_content_generate_profile(): void
    {
        $openrouter = $this->connection(54, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $claude = $this->model($openrouter, 'anthropic/claude-sonnet-4.6', false);
        $free = $this->model($openrouter, 'nvidia/nemotron-3-ultra-550b-a55b:free', true);
        $this->grantText($openrouter, $claude);
        $this->grantText($openrouter, $free);
        $this->priorities->appendToArea(54, AiModelArea::TextReasoning, [(int) $free->id]);
        $this->priorities->appendToArea(54, AiModelArea::TextLongform, [(int) $claude->id]);

        $profile = (new PromptExecutionProfileResolver())->resolve(null, 'article.content.generate');
        $this->assertSame(AiExecutionProfile::TextLongform, $profile);
        $this->assertSame(['anthropic/claude-sonnet-4.6'], $this->runtimeModels(54));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: bool}>  $rows
     * @return list<int>
     */
    private function seedOrderedLongform(int $userId, array $rows): array
    {
        $ids = [];
        foreach ($rows as [$name, $raw, $provider, $free]) {
            $connection = $this->connection($userId, $provider, $name);
            $model = $this->model($connection, $raw, $free);
            $this->grantText($connection, $model);
            $ids[] = (int) $model->id;
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextLongform, $ids);

        return $ids;
    }

    private function seedPaidFreeAlternating(int $userId): void
    {
        $openrouter = $this->connection($userId, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $paid1 = $this->model($openrouter, 'anthropic/claude-sonnet-4.6', false);
        $free1 = $this->model($openrouter, 'google/gemma-3-12b-it:free', true);
        $paid2 = $this->model($openrouter, 'openai/gpt-5.4', false);
        $free2 = $this->model($openrouter, 'google/gemma-3-27b-it:free', true);
        foreach ([$paid1, $free1, $paid2, $free2] as $model) {
            $this->grantText($openrouter, $model);
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextLongform, [
            (int) $paid1->id,
            (int) $free1->id,
            (int) $paid2->id,
            (int) $free2->id,
        ]);
    }

    /** @return list<string> */
    private function runtimeModels(int $userId): array
    {
        return array_map(
            static fn ($c): string => $c->model,
            $this->router->resolveAll(
                AiExecutionProfile::TextLongform->value,
                new AiRoutingContext(userId: $userId, costPolicy: AiCostPolicy::Default),
            ),
        );
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function model(ApiConnection $connection, string $raw, bool $free): SeoAiModel
    {
        $caps = [
            'provider_metadata' => [
                'pricing' => $free
                    ? ['prompt' => '0', 'completion' => '0']
                    : ['prompt' => '0.000001', 'completion' => '0.000002'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ];

        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => false,
            'capabilities' => $caps,
        ]);
    }

    private function grantText(ApiConnection $connection, SeoAiModel $model): void
    {
        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $capability) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => (string) $model->raw_model_name,
                'capability' => $capability,
                'source' => AiCapabilitySource::Manual->value,
                'enabled' => true,
            ]);
        }
    }
}
