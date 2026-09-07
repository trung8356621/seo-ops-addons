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
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingOwnerResolver;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
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
 * CASE focus: attempts=0 "No eligible" caused by connection_locked / bad credentials,
 * not empty TextReasoning catalog.
 */
final class OutlineConnectionLockRoutingTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRoutingTargetService $targets;

    private AiModelRouterService $router;

    private AiRuntimeHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections', 'users', 'wp_options'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::connection('mysql')->dropIfExists('ai_runtime_health_states');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->timestamps();
        });
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

    public function test_placeholder_api_key_is_not_usable(): void
    {
        $this->assertFalse(AiConnectionCredential::isUsable('k'));
        $this->assertFalse(AiConnectionCredential::isUsable(''));
        $this->assertFalse(AiConnectionCredential::isUsable(null));
        $this->assertTrue(AiConnectionCredential::isUsable('test-key'));
        $this->assertTrue(AiConnectionCredential::isUsable('sk-or-v1-abcdefghijklmnopqrstuvwxyz'));
    }

    public function test_g_rewrite_item_outline_uses_text_reasoning_not_article_rewrite_area(): void
    {
        $this->seedReasoningRoute(40, ['openai/gpt-5.4', 'google/gemini-3.5-flash']);
        $outline = $this->targets->eligibleCandidates(
            40,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(
                userId: 40,
                hookKey: ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK,
                itemGenerationMode: 'article.rewrite',
            ),
        );
        $this->assertGreaterThanOrEqual(2, count($outline));
        $diag = $this->targets->lastEligibilityDiagnostics();
        $this->assertSame(2, (int) ($diag['candidates_after_production_eligibility'] ?? 0));
    }

    public function test_a_outline_has_multiple_reasoning_candidates(): void
    {
        $this->seedReasoningRoute(41, ['model/a', 'model/b', 'model/c']);
        $cands = $this->targets->eligibleCandidates(
            41,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 41, hookKey: 'article.outline.structure.generate'),
        );
        $this->assertGreaterThanOrEqual(2, count($cands));
    }

    public function test_h_healthy_routes_never_no_eligible_zero_attempt_message(): void
    {
        $this->seedReasoningRoute(42, ['model/a', 'model/b']);
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 42, hookKey: 'article.outline.structure.generate'),
            static fn (): array => ['ok', null],
        );
        $this->assertSame('ok', $output);
    }

    public function test_connection_locked_all_skipped_reports_lock_not_generic_no_eligible(): void
    {
        $models = $this->seedReasoningRoute(43, ['model/a', 'model/b']);
        $cands = $this->targets->eligibleCandidates(
            43,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 43),
        );
        $this->assertCount(2, $cands);
        $this->health->recordFailure(43, $cands[0], new AiFailureDecision(
            category: AiFailureClass::CredentialInvalid,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::ConnectionLocked,
            safeMessage: 'Invalid API credentials.',
            httpStatus: 401,
            lockConnection: true,
            manualUnlockRequired: true,
            affectsRuntimeHealth: true,
            failureStage: 'provider_http',
        ));
        $this->assertSame('connection_locked', $this->health->skipReason(43, $cands[0]));
        $this->assertSame('connection_locked', $this->health->skipReason(43, $cands[1]));

        try {
            $this->router->executeWithProfile(
                AiExecutionProfile::TextReasoning->value,
                new AiRoutingContext(userId: 43, hookKey: 'article.outline.structure.generate'),
                static fn (): array => ['should-not-run', null],
            );
            $this->fail('Expected AiRoutesExhaustedException');
        } catch (AiRoutesExhaustedException $exception) {
            $this->assertSame(0, (int) ($exception->context['attempt_count'] ?? -1));
            $this->assertStringContainsString('connection lock', $exception->getMessage());
            $this->assertStringNotContainsString('No eligible AI route was attempted', $exception->getMessage());
            $this->assertSame(2, (int) (($exception->context['skip_counts']['connection_locked'] ?? 0)));
        }
        unset($models);
    }

    public function test_unlock_for_api_connection_clears_foreign_owner_lock_and_allows_attempt(): void
    {
        $this->seedReasoningRoute(44, ['model/a', 'model/b']);
        $cands = $this->targets->eligibleCandidates(44, AiExecutionProfile::TextReasoning, new AiRoutingContext(userId: 44));
        $this->health->recordFailure(44, $cands[0], new AiFailureDecision(
            category: AiFailureClass::CredentialInvalid,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::ConnectionLocked,
            safeMessage: 'Invalid API credentials.',
            httpStatus: 401,
            lockConnection: true,
            manualUnlockRequired: true,
            affectsRuntimeHealth: true,
            failureStage: 'provider_http',
        ));

        // Auth-style unlock for a different user must not leave the lock in place.
        $this->health->unlockConnection(1, (int) $cands[0]->connection->id);
        $this->assertSame('connection_locked', $this->health->skipReason(44, $cands[0]));

        $cleared = $this->health->unlockConnectionForApiConnection((int) $cands[0]->connection->id);
        $this->assertGreaterThanOrEqual(1, $cleared);
        $this->assertNull($this->health->skipReason(44, $cands[0]));

        $tried = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextReasoning->value,
            new AiRoutingContext(userId: 44, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertNotSame([], $tried);
    }

    public function test_missing_credentials_excluded_from_live_compatible(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 45,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'bad',
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $model = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'openai/gpt-5.4',
            'display_name' => 'GPT',
            'category' => AiModelCategory::GEMINI_FLASH,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [],
        ]);
        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $capability) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => $model->raw_model_name,
                'capability' => $capability,
                'enabled' => true,
            ]);
        }
        $this->priorities->appendToArea(45, AiModelArea::TextReasoning, [(int) $model->id]);
        $cands = $this->targets->eligibleCandidates(45, AiExecutionProfile::TextReasoning, new AiRoutingContext(userId: 45));
        $this->assertSame([], $cands);
        $diag = $this->targets->lastEligibilityDiagnostics();
        $this->assertSame(1, (int) (($diag['live_compatible_rejection_counts']['missing_credentials'] ?? 0)));

        try {
            $this->router->resolve(AiExecutionProfile::TextReasoning->value, new AiRoutingContext(userId: 45));
            $this->fail('Expected AiRoutingException');
        } catch (AiRoutingException $exception) {
            $this->assertStringContainsString('No active model supports', $exception->getMessage());
        }
    }

    public function test_for_connection_uses_fallback_when_owner_missing(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 0,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'no-owner',
            'api_key' => 'usable-key-123',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $resolver = new AiRoutingOwnerResolver();
        $this->assertSame(0, $resolver->resolve(connection: $connection));
        $this->assertSame(99, $resolver->forConnection($connection, 99));
    }

    public function test_b_model_cooldown_falls_through_to_second(): void
    {
        $this->seedReasoningRoute(46, ['model/a', 'model/b']);
        $cands = $this->targets->eligibleCandidates(46, AiExecutionProfile::TextReasoning, new AiRoutingContext(userId: 46));
        $this->health->recordFailure(46, $cands[0], new AiFailureDecision(
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
            new AiRoutingContext(userId: 46, hookKey: 'article.outline.structure.generate'),
            function ($candidate) use (&$tried): array {
                $tried[] = $candidate->model;

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['model/b'], $tried);
    }

    /**
     * @param  list<string>  $raws
     * @return list<SeoAiModel>
     */
    private function seedReasoningRoute(int $userId, array $raws): array
    {
        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OR '.$userId,
            'api_key' => 'test-key-usable',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $models = [];
        $ids = [];
        foreach ($raws as $raw) {
            $model = SeoAiModel::query()->create([
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
            foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $capability) {
                AiModelCapabilityRow::query()->create([
                    'api_connection_id' => $connection->id,
                    'seo_ai_model_id' => $model->id,
                    'model_key' => $raw,
                    'capability' => $capability,
                    'enabled' => true,
                ]);
            }
            $models[] = $model;
            $ids[] = (int) $model->id;
        }
        $this->priorities->appendToArea($userId, AiModelArea::TextReasoning, $ids);

        return $models;
    }
}
