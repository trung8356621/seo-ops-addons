<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use ReflectionMethod;
use Tests\TestCase;

/**
 * FreeOnly true-truncation must fail the physical attempt inside PromptRunner
 * and continue remaining FREE candidates (never output_truncated_prefer_paid).
 */
final class FreeOnlyTrueTruncationFallbackTest extends TestCase
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

    /** T1 — FreeOnly + TextReasoning: A truncated → B success; paid=0 */
    public function test_t1_free_only_truncation_falls_back_to_next_free(): void
    {
        (new AiResilienceSettingsService())->save(901, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $seeded = $this->seedFreeReasoning(901, [
            ['OR-A', 'nvidia/nemotron-a:free'],
            ['OR-B', 'nvidia/nemotron-b:free'],
        ]);

        $calls = [];
        $paidCalls = 0;
        [$output, , $selected, , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            $this->freeOnlyContext(901, 'article.outline.structure.generate'),
            function ($candidate) use (&$calls, &$paidCalls, $seeded): array {
                $calls[] = (int) $candidate->connection->id;
                if (! $candidate->isFree) {
                    $paidCalls++;
                }
                if ((int) $candidate->connection->id === $seeded[0]['connection_id']) {
                    throw new OutputTruncated(
                        'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
                        AiProviderTerminalReason::OutputTruncated,
                        'length',
                    );
                }

                return ['outline-ok', ['finish_reason' => 'stop']];
            },
        );

        self::assertSame('outline-ok', $output);
        self::assertSame([$seeded[0]['connection_id'], $seeded[1]['connection_id']], $calls);
        self::assertSame(0, $paidCalls);
        self::assertSame($seeded[1]['connection_id'], (int) $selected->connection->id);
        self::assertSame('failed', $attempts[0]['result'] ?? null);
        self::assertSame(
            AiProviderTerminalReason::OutputTruncated->value,
            $attempts[0]['provider_terminal_reason'] ?? null,
        );
        self::assertSame('length', $attempts[0]['provider_finish_reason'] ?? null);
        self::assertSame('success', $attempts[1]['result'] ?? null);
        self::assertNotContains(
            'output_truncated_prefer_paid',
            array_column(
                array_filter($attempts, static fn (array $r): bool => ($r['result'] ?? '') === 'skipped'),
                'skip_reason',
            ),
        );
    }

    /** T2 — max_free=3: A/B truncated, C success, D not called */
    public function test_t2_three_free_attempt_budget(): void
    {
        (new AiResilienceSettingsService())->save(902, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $seeded = $this->seedFreeReasoning(902, [
            ['A', 'free/a:free'],
            ['B', 'free/b:free'],
            ['C', 'free/c:free'],
            ['D', 'free/d:free'],
        ]);

        $calls = [];
        [$output, , $selected, , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            $this->freeOnlyContext(902, 'article.outline.structure.generate'),
            function ($candidate) use (&$calls, $seeded): array {
                $calls[] = (int) $candidate->connection->id;
                if (in_array((int) $candidate->connection->id, [$seeded[0]['connection_id'], $seeded[1]['connection_id']], true)) {
                    throw new OutputTruncated(
                        'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
                        AiProviderTerminalReason::OutputTruncated,
                        'length',
                    );
                }

                return ['ok-c', null];
            },
        );

        self::assertSame('ok-c', $output);
        self::assertSame(
            [$seeded[0]['connection_id'], $seeded[1]['connection_id'], $seeded[2]['connection_id']],
            $calls,
        );
        self::assertNotContains($seeded[3]['connection_id'], $calls);
        self::assertSame($seeded[2]['connection_id'], (int) $selected->connection->id);
        self::assertSame(0, (int) (($attempts[2]['paid_attempts'] ?? 0)));
    }

    /** T3 — A/B/C all truncated → exhaust FreeOnly, no paid */
    public function test_t3_free_budget_exhaustion_no_paid(): void
    {
        (new AiResilienceSettingsService())->save(903, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $seeded = $this->seedFreeReasoning(903, [
            ['A', 'free/a:free'],
            ['B', 'free/b:free'],
            ['C', 'free/c:free'],
            ['D', 'free/d:free'],
        ]);
        // Paid sibling must never be called under FreeOnly.
        $paidConn = $this->connection(903, 'PAID', ApiConnectionProviders::OPENROUTER);
        $paid = $this->model($paidConn, 'openai/gpt-paid', false);
        $this->grantReasoning($paidConn, $paid);
        app(AiModelPriorityService::class)->appendToArea(903, AiModelArea::TextReasoning, [(int) $paid->id]);

        $calls = [];
        $paidCalls = 0;
        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                $this->freeOnlyContext(903, 'article.outline.structure.generate'),
                function ($candidate) use (&$calls, &$paidCalls): array {
                    $calls[] = $candidate->model;
                    if (! $candidate->isFree) {
                        $paidCalls++;
                    }
                    throw new OutputTruncated(
                        'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
                        AiProviderTerminalReason::OutputTruncated,
                        'length',
                    );
                },
            );
            self::fail('Expected FreeOnly exhaustion');
        } catch (AiRoutesExhaustedException) {
            self::assertCount(3, $calls);
            self::assertSame(0, $paidCalls);
            self::assertNotContains('openai/gpt-paid', $calls);
            self::assertNotContains('free/d:free', $calls);
            unset($seeded);
        }
    }

    /** T4 — same logical model, two physical OR connections both attempted */
    public function test_t4_same_model_two_physical_or_connections(): void
    {
        (new AiResilienceSettingsService())->save(904, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $modelId = 'nvidia/nemotron-3-ultra-550b-a55b:free';
        $seeded = $this->seedFreeReasoning(904, [
            ['seo-ops-2', $modelId],
            ['seo-ops-3', $modelId],
        ]);

        $calls = [];
        [$output, , $selected] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            $this->freeOnlyContext(904, 'article.outline.structure.generate'),
            function ($candidate) use (&$calls, $seeded): array {
                $calls[] = (int) $candidate->connection->id;
                if ((int) $candidate->connection->id === $seeded[0]['connection_id']) {
                    throw new OutputTruncated(
                        'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
                        AiProviderTerminalReason::OutputTruncated,
                        'length',
                    );
                }

                return ['ok', null];
            },
        );

        self::assertSame('ok', $output);
        self::assertSame([$seeded[0]['connection_id'], $seeded[1]['connection_id']], $calls);
        self::assertSame($seeded[1]['connection_id'], (int) $selected->connection->id);
    }

    /** T5 — short article warning: no OutputTruncated from article gate */
    public function test_t5_article_short_warning_no_fallback_throw(): void
    {
        $text = trim(str_repeat('word ', 479));
        $method = new ReflectionMethod(PromptRunnerService::class, 'assertArticleRouteOutputEligibleForFailover');
        $runner = (new \ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();

        $result = $method->invoke($runner, $text, ['finish_reason' => 'stop'], ['article_length' => 501]);

        self::assertIsArray($result);
        self::assertSame(ArticleGenerationLengthValidator::OUTCOME_SUCCESS_WITH_WARNING, $result['outcome'] ?? null);
    }

    /** T6 — true article truncation still throws for router failover */
    public function test_t6_true_article_truncation_still_throws(): void
    {
        $method = new ReflectionMethod(PromptRunnerService::class, 'assertArticleRouteOutputEligibleForFailover');
        $runner = (new \ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();

        $this->expectException(OutputTruncated::class);
        $method->invoke(
            $runner,
            trim(str_repeat('word ', 600)),
            ['finish_reason' => 'length'],
            ['article_length' => 501],
        );
    }

    /** T7 — OutputPipeline safety net still rejects finish_reason=length */
    public function test_t7_output_pipeline_safety_net_still_rejects_truncation(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;

        $this->expectException(OutputTruncated::class);
        $pipeline->process($this->simpleMarkdownDefinition(), [
            'text' => "# Title\n\n## Intro\nBody text here enough for non-empty validation.",
            'finish_reason' => 'length',
        ], null, []);
    }

    /** T8 — vocabulary hook FreeOnly truncates A then accepts B */
    public function test_t8_vocabulary_truncation_falls_back(): void
    {
        (new AiResilienceSettingsService())->save(908, ['max_ai_attempts' => 6, 'max_free_attempts' => 3]);
        $seeded = $this->seedFreeReasoning(908, [
            ['V-A', 'free/vocab-a:free'],
            ['V-B', 'free/vocab-b:free'],
        ]);

        $calls = [];
        [$output, , $selected, , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            $this->freeOnlyContext(908, 'article.vocabulary.generate'),
            function ($candidate) use (&$calls, $seeded): array {
                $calls[] = (int) $candidate->connection->id;
                if ((int) $candidate->connection->id === $seeded[0]['connection_id']) {
                    throw new OutputTruncated(
                        'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
                        AiProviderTerminalReason::OutputTruncated,
                        'length',
                    );
                }

                return ['vocab-ok', null];
            },
        );

        self::assertSame('vocab-ok', $output);
        self::assertSame([$seeded[0]['connection_id'], $seeded[1]['connection_id']], $calls);
        self::assertSame($seeded[1]['connection_id'], (int) $selected->connection->id);
        self::assertSame('failed', $attempts[0]['result'] ?? null);
        self::assertSame('success', $attempts[1]['result'] ?? null);
    }

    public function test_generic_terminal_gate_throws_on_length(): void
    {
        $method = new ReflectionMethod(PromptRunnerService::class, 'assertProviderTerminalReasonEligibleForFailover');
        $runner = (new \ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();

        $this->expectException(OutputTruncated::class);
        $method->invoke($runner, ['finish_reason' => 'length', 'completion_tokens' => 2048]);
    }

    public function test_prompt_runner_wires_generic_gate_for_non_article_routes(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptRunnerService.php',
        );
        self::assertStringContainsString('assertProviderTerminalReasonEligibleForFailover', $src);

        $attemptPos = strpos($src, 'private function executePlannedRouteAttempt');
        self::assertNotFalse($attemptPos);
        $articleBranchEnd = strpos($src, 'return [$output, $usage];', $attemptPos);
        self::assertNotFalse($articleBranchEnd);
        $nonArticleGate = strpos($src, 'assertProviderTerminalReasonEligibleForFailover', $articleBranchEnd + 1);
        self::assertNotFalse($nonArticleGate, 'Non-article planned route must invoke generic terminal gate');

        $routerSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/AiModelRouterService.php',
        );
        self::assertStringContainsString('! $isFreeOnlyRouting', $routerSrc);
        self::assertStringContainsString('preferPaidAfterOutputTruncation', $routerSrc);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows  [connectionName, modelId]
     * @return list<array{connection_id: int, model: string}>
     */
    private function seedFreeReasoning(int $userId, array $rows): array
    {
        $priorities = app(AiModelPriorityService::class);
        $seeded = [];
        $ids = [];
        foreach ($rows as [$name, $raw]) {
            $connection = $this->connection($userId, $name, ApiConnectionProviders::OPENROUTER);
            $model = $this->model($connection, $raw, true);
            $this->grantReasoning($connection, $model);
            $ids[] = (int) $model->id;
            $seeded[] = [
                'connection_id' => (int) $connection->id,
                'model' => $raw,
            ];
        }
        $priorities->appendToArea($userId, AiModelArea::FreeModels, $ids);

        return $seeded;
    }

    private function freeOnlyContext(int $userId, string $hookKey): AiRoutingContext
    {
        return new AiRoutingContext(
            userId: $userId,
            hookKey: $hookKey,
            costPolicy: AiCostPolicy::FreeOnly,
            freeOnly: true,
        );
    }

    private function connection(int $userId, string $name, string $provider): ApiConnection
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

    private function grantReasoning(ApiConnection $connection, SeoAiModel $model): void
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

    private function simpleMarkdownDefinition(): PromptHookDefinition
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );

        return $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.outline.structure.generate',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => [
                'type' => 'markdown',
                'validation' => [
                    'not_empty' => true,
                ],
                'normalize' => ['trim'],
            ],
            'template' => ['system' => 's', 'user' => 'u'],
            'side_effects' => [],
        ]);
    }
}
