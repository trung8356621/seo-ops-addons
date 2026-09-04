<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\AiModelPrimaryTypeClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterModelEconomics;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class AiCenterTextTaxonomyFreeRoutingTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRoutingTargetService $targets;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections', 'ai_provider_templates'] as $table) {
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
            $table->string('profile_key');
            $table->unsignedBigInteger('api_connection_id');
            $table->string('model_key');
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_provider_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider_key');
            $table->string('name');
            $table->string('protocol');
            $table->string('schema_version')->default('1.0');
            $table->json('config');
            $table->boolean('is_builtin')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $this->priorities = new AiModelPriorityService();
        $this->targets = new AiRoutingTargetService(new ModelCapabilityRegistry());
    }

    public function test_free_detection_from_pricing_and_suffix(): void
    {
        $this->assertTrue(OpenRouterModelEconomics::isFree([
            'provider_metadata' => ['pricing' => ['prompt' => '0', 'completion' => '0']],
        ], 'nvidia/nemotron-x'));
        $this->assertFalse(OpenRouterModelEconomics::isFree([
            'provider_metadata' => ['pricing' => ['prompt' => '0.000001', 'completion' => '0']],
        ], 'openai/gpt-5.4'));
        $this->assertTrue(OpenRouterModelEconomics::isFree([], 'google/gemma-3-27b-it:free'));
        $this->assertTrue(OpenRouterModelEconomics::isFree([], OpenRouterModelEconomics::FREE_ROUTER_ID));
        $this->assertFalse(OpenRouterModelEconomics::isChatTextModel([], 'qwen/qwen3-embed-8b'));
        $this->assertFalse(OpenRouterModelEconomics::isChatTextModel([], 'qwen/qwen3-rerank-8b'));
        $this->assertTrue(OpenRouterModelEconomics::isChatTextModel([
            'provider_metadata' => ['architecture' => ['modality' => 'text->text']],
        ], 'nvidia/nemotron-3-nano-30b-a3b:free'));
    }

    public function test_openrouter_free_router_is_a_family(): void
    {
        $family = (new AiModelFamilyCatalog())->familyForModelId(OpenRouterModelEconomics::FREE_ROUTER_ID);
        $this->assertNotNull($family);
        $this->assertSame('openrouter.free', $family->familyKey);
        $this->assertSame(OpenRouterModelEconomics::FREE_ROUTER_LABEL, $family->displayName);
        $synthetic = (new AiModelFamilyCatalog())->aggregatorFamily('nvidia/nemotron-3-nano-30b-a3b:free');
        $this->assertNotNull($synthetic);
        $this->assertNull((new AiModelFamilyCatalog())->aggregatorFamily('qwen/qwen3-embed-8b'));
    }

    public function test_ui_areas_are_five_types(): void
    {
        $this->assertSame([
            AiModelArea::TextFast,
            AiModelArea::TextLongform,
            AiModelArea::TextReasoning,
            AiModelArea::Image,
            AiModelArea::Video,
        ], AiModelArea::uiCases());
        $this->assertSame(AiModelArea::TextFast, AiModelArea::fromProfile(AiExecutionProfile::TextFast));
        $this->assertSame(AiModelArea::TextLongform, AiModelArea::fromProfile(AiExecutionProfile::TextLongform));
        $this->assertSame(AiModelArea::TextReasoning, AiModelArea::fromProfile(AiExecutionProfile::TextReasoning));
    }

    public function test_hooks_map_to_matching_text_profiles(): void
    {
        $resolver = new PromptExecutionProfileResolver();
        $this->assertSame(AiExecutionProfile::TextLongform, $resolver->resolve(null, 'article.content.generate'));
        $this->assertSame(AiExecutionProfile::TextLongform, $resolver->resolve(null, 'article.content.rewrite'));
        $this->assertSame(AiExecutionProfile::TextLongform, $resolver->resolve(null, 'article.content.translate'));
        $this->assertSame(AiExecutionProfile::TextLongform, $resolver->resolve(null, 'article.content.improve'));
        $this->assertSame(AiExecutionProfile::TextReasoning, $resolver->resolve(null, 'article.outline.generate'));
        // KD must NOT resolve to text.reasoning (deepseek-reasoner chain) — incident 2026-09-03 run20.
        $this->assertSame(AiExecutionProfile::TextLongform, $resolver->resolve(null, 'keyword.discovery.structured'));
        $this->assertSame(AiExecutionProfile::TextFast, $resolver->resolve(null, 'article.title_suggestion'));
        $this->assertSame(AiExecutionProfile::TextFast, $resolver->resolve(null, 'article.meta_description_suggestion'));
        $this->assertSame(AiExecutionProfile::TextFast, $resolver->resolve(null, 'article.faq.generate'));
    }

    public function test_existing_longform_routing_hint_classifies_primary(): void
    {
        $connection = $this->openRouter(21);
        $long = $this->orModel($connection, 'google/gemma-3-27b-it:free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
                'context_length' => 131072,
            ],
        ]);
        $this->targets->writeProfileSettings(21, AiExecutionProfile::TextLongform, [
            'allowed_execution_keys' => [(int) $connection->id.'|'.$this->familyKey((string) $long->raw_model_name)],
        ]);
        (new AiModelPrimaryTypeClassifier())->classifyConnection($connection);
        $long->refresh();
        // Free inventory is classified but not auto-enabled onto the route.
        $this->assertFalse($this->priorities->isAreaEnabled($long, AiModelArea::TextLongform, $connection));
        $this->assertFalse($this->priorities->isAreaEnabled($long, AiModelArea::TextFast, $connection));
        $this->assertSame(AiModelArea::TextLongform->value, $long->capabilities[AiModelArea::PRIMARY_TYPE_KEY] ?? null);
        $this->assertSame(AiModelArea::SOURCE_AUTO, $long->capabilities[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] ?? null);
    }

    public function test_manual_override_survives_classify(): void
    {
        $connection = $this->openRouter(22);
        $model = $this->orModel($connection, 'nvidia/nemotron-3-nano-30b-a3b:free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $this->priorities->appendToArea(22, AiModelArea::TextReasoning, [(int) $model->id]);
        $model->refresh();
        $this->assertSame(AiModelArea::SOURCE_MANUAL, $model->capabilities[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] ?? null);
        (new AiModelPrimaryTypeClassifier())->classifyConnection($connection);
        $model->refresh();
        $this->assertTrue($this->priorities->isAreaEnabled($model, AiModelArea::TextReasoning, $connection));
        $this->assertSame(AiModelArea::SOURCE_MANUAL, $model->capabilities[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] ?? null);
    }

    public function test_free_embedding_is_not_enabled_in_text_tabs(): void
    {
        $connection = $this->openRouter(23);
        $embed = $this->orModel($connection, 'qwen/qwen3-embed-8b', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->embedding'],
            ],
        ], hidden: true);
        $result = (new AiModelPrimaryTypeClassifier())->classifyConnection($connection);
        $embed->refresh();
        $this->assertGreaterThan(0, $result['excluded']);
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($embed, AiModelArea::TextFast));
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($embed, AiModelArea::TextLongform));
        $this->assertTrue((bool) $embed->is_hidden);
    }

    public function test_default_policy_preserves_manual_order_including_free(): void
    {
        $connection = $this->openRouter(27);
        $free = $this->orModel($connection, 'nvidia/nemotron-3-super-free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $paid = $this->orModel($connection, 'anthropic/claude-sonnet-4.6', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $this->priorities->appendToArea(27, AiModelArea::TextLongform, [(int) $free->id, (int) $paid->id]);
        $resolved = $this->targets->eligibleCandidates(
            27,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 27, costPolicy: AiCostPolicy::Default),
        );
        $models = array_map(static fn ($c): string => $c->model, $resolved);
        $this->assertSame(['nvidia/nemotron-3-super-free', 'anthropic/claude-sonnet-4.6'], $models);
    }

    public function test_free_only_skips_paid_and_falls_through_free_pool(): void
    {
        $connection = $this->openRouter(24);
        $freeA = $this->orModel($connection, 'nvidia/nemotron-3-super-free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $freeB = $this->orModel($connection, 'google/gemma-3-12b-it:free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
                'context_length' => 131072,
            ],
        ]);
        $paid = $this->orModel($connection, 'anthropic/claude-sonnet-4.6', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $this->priorities->appendToArea(24, AiModelArea::TextLongform, [(int) $freeA->id, (int) $freeB->id, (int) $paid->id]);
        $context = new AiRoutingContext(userId: 24, costPolicy: AiCostPolicy::FreeOnly);
        $resolved = $this->targets->eligibleCandidates(24, AiExecutionProfile::TextLongform, $context);
        $models = array_map(static fn ($c): string => $c->model, $resolved);
        $this->assertNotContains('anthropic/claude-sonnet-4.6', $models);
        $this->assertContains('nvidia/nemotron-3-super-free', $models);
        $this->assertContains('google/gemma-3-12b-it:free', $models);

        $router = new AiModelRouterService(new ModelCapabilityRegistry(), $this->targets);
        $tried = [];
        try {
            $router->executeWithProfile(
                AiExecutionProfile::TextLongform->value,
                $context,
                function ($candidate) use (&$tried): array {
                    $tried[] = $candidate->model;
                    throw new PromptRunException('429 rate limit', 429);
                },
            );
            $this->fail('Expected infrastructure exhaustion');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertStringContainsString('AI_ROUTES_EXHAUSTED', $exception->getMessage());
        }
        $this->assertNotContains('anthropic/claude-sonnet-4.6', $tried);
        $this->assertGreaterThanOrEqual(2, count($tried));
    }

    public function test_paid_mode_still_includes_claude(): void
    {
        $connection = $this->openRouter(25);
        $paid = $this->orModel($connection, 'anthropic/claude-sonnet-4.6', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $this->priorities->appendToArea(25, AiModelArea::TextLongform, [(int) $paid->id]);
        $resolved = $this->targets->eligibleCandidates(
            25,
            AiExecutionProfile::TextLongform,
            new AiRoutingContext(userId: 25, costPolicy: AiCostPolicy::Default),
        );
        $this->assertSame(['anthropic/claude-sonnet-4.6'], array_map(static fn ($c): string => $c->model, $resolved));
    }

    public function test_restore_strips_auto_merged_free_keys_from_custom(): void
    {
        $connection = $this->openRouter(28);
        $paid = $this->orModel($connection, 'anthropic/claude-sonnet-4.6', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $free = $this->orModel($connection, 'google/gemma-3-12b-it:free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $paidKey = (int) $connection->id.'|'.(string) ((new AiModelFamilyCatalog())->aggregatorFamily((string) $paid->raw_model_name)?->familyKey);
        $freeKey = (int) $connection->id.'|'.(string) ((new AiModelFamilyCatalog())->aggregatorFamily((string) $free->raw_model_name)?->familyKey);
        $this->targets->writeProfileSettings(28, AiExecutionProfile::TextLongform, [
            'allowed_execution_keys' => [$paidKey, $freeKey],
            'allowed_family_keys' => [
                $this->familyKey((string) $paid->raw_model_name),
                $this->familyKey((string) $free->raw_model_name),
            ],
            AiRoutingTargetService::SETTING_ALLOW_PAID_FALLBACK => false,
        ]);
        $restore = (new \Omnichannel\Addons\AiPrompt\Services\AiFreeOnlyRoutingConfigurator(
            $this->targets,
        ))->restoreGlobalRouting(28);
        $this->assertContains($freeKey, $restore['stripped_keys'][AiExecutionProfile::TextLongform->value]);
        $settings = $this->targets->profileSettings(28, AiExecutionProfile::TextLongform);
        $this->assertSame([$paidKey], $settings['allowed_execution_keys']);
        $this->assertArrayNotHasKey(AiRoutingTargetService::SETTING_ALLOW_PAID_FALLBACK, $settings);
    }

    public function test_free_only_respects_text_profile_area(): void
    {
        $connection = $this->openRouter(29);
        $fast = $this->orModel($connection, 'nvidia/nemotron-3-nano-30b-a3b:free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $long = $this->orModel($connection, 'google/gemma-3-27b-it:free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
                'context_length' => 131072,
            ],
        ]);
        $reason = $this->orModel($connection, 'deepseek/deepseek-r1:free', [
            'provider_metadata' => [
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'architecture' => ['modality' => 'text->text'],
            ],
        ]);
        $this->priorities->appendToArea(29, AiModelArea::TextFast, [(int) $fast->id]);
        $this->priorities->appendToArea(29, AiModelArea::TextLongform, [(int) $long->id]);
        $this->priorities->appendToArea(29, AiModelArea::TextReasoning, [(int) $reason->id]);
        $freeOnly = new AiRoutingContext(userId: 29, costPolicy: AiCostPolicy::FreeOnly);
        $this->assertSame(
            ['nvidia/nemotron-3-nano-30b-a3b:free'],
            array_map(static fn ($c): string => $c->model, $this->targets->eligibleCandidates(29, AiExecutionProfile::TextFast, $freeOnly)),
        );
        $this->assertSame(
            ['google/gemma-3-27b-it:free'],
            array_map(static fn ($c): string => $c->model, $this->targets->eligibleCandidates(29, AiExecutionProfile::TextLongform, $freeOnly)),
        );
        // Inventory still lists DeepSeek on reasoning area…
        $this->assertSame(
            ['deepseek/deepseek-r1:free'],
            array_map(static fn ($c): string => $c->model, $this->targets->liveCompatibleCandidates(29, AiExecutionProfile::TextReasoning)),
        );
        // …but production eligibility excludes DeepSeek from TextReasoning (Outline/Vocabulary).
        $this->assertSame(
            [],
            array_map(static fn ($c): string => $c->model, $this->targets->eligibleCandidates(29, AiExecutionProfile::TextReasoning, $freeOnly)),
        );
    }

    public function test_sync_upserts_free_router_and_unhides_priced_free_chat(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'data' => [
                    [
                        'id' => 'nvidia/nemotron-3-nano-30b-a3b:free',
                        'name' => 'Nemotron Nano',
                        'pricing' => ['prompt' => '0', 'completion' => '0'],
                        'architecture' => ['modality' => 'text->text'],
                    ],
                    [
                        'id' => 'qwen/qwen3-embed-8b',
                        'name' => 'Qwen Embed',
                        'pricing' => ['prompt' => '0', 'completion' => '0'],
                        'architecture' => ['modality' => 'text->embedding'],
                    ],
                    [
                        'id' => 'openai/gpt-5.4',
                        'name' => 'GPT-5.4',
                        'pricing' => ['prompt' => '0.000001', 'completion' => '0.000004'],
                        'architecture' => ['modality' => 'text->text'],
                    ],
                ],
            ], 200),
        ]);
        $connection = $this->openRouter(26);
        $this->assertTrue((new AiModelRouterService())->syncOpenAiCompatibleModels((int) $connection->id));
        $router = SeoAiModel::query()->where('raw_model_name', OpenRouterModelEconomics::FREE_ROUTER_ID)->first();
        $this->assertNotNull($router);
        $this->assertFalse((bool) $router->is_hidden);
        $nano = SeoAiModel::query()->where('raw_model_name', 'nvidia/nemotron-3-nano-30b-a3b:free')->first();
        $this->assertNotNull($nano);
        $this->assertFalse((bool) $nano->is_hidden);
        $embed = SeoAiModel::query()->where('raw_model_name', 'qwen/qwen3-embed-8b')->first();
        $this->assertNotNull($embed);
        $this->assertTrue((bool) $embed->is_hidden);
        $paid = SeoAiModel::query()->where('raw_model_name', 'openai/gpt-5.4')->first();
        $this->assertNotNull($paid);
        $this->assertTrue((bool) $paid->is_hidden);
    }

    private function openRouter(int $userId): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'sk-or-test',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function orModel(ApiConnection $connection, string $raw, array $capabilities, bool $hidden = false): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => $hidden,
            'capabilities' => $capabilities,
        ]);
    }

    private function familyKey(string $raw): string
    {
        return (string) ((new AiModelFamilyCatalog())->familyForModelId($raw)?->familyKey);
    }
}
