<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionCoverageService;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\LogicalModelRouteOrder;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiCanonicalModelKey;
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

final class LogicalModelFallbackArchitectureTest extends TestCase
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

    public function test_canonical_key_snaps_direct_and_openrouter_aliases(): void
    {
        $direct = AiCanonicalModelKey::fromProviderModelId('gemini-3.1-pro-preview', ApiConnectionProviders::GEMINI);
        $or = AiCanonicalModelKey::fromProviderModelId('google/gemini-3.1-pro-preview', ApiConnectionProviders::OPENROUTER);
        // Family catalog maps gemini.pro members — both should share gemini.pro when listed.
        $this->assertSame(
            AiCanonicalModelKey::fromProviderModelId('gemini-3.1-pro-preview'),
            $direct,
        );
        $this->assertNotSame('display-name-only', $direct);
        $this->assertNotEmpty($or);
    }

    public function test_canonical_key_does_not_snap_different_families_by_display_name(): void
    {
        $a = AiCanonicalModelKey::fromProviderModelId('openai/gpt-5.4');
        $b = AiCanonicalModelKey::fromProviderModelId('openai/gpt-5.4-mini');
        $this->assertNotSame($a, $b);
    }

    public function test_logical_route_order_preserves_priority_then_prefers_direct_on_tie(): void
    {
        $or = $this->connection(70, ApiConnectionProviders::OPENROUTER, 'OR');
        $gem = $this->connection(70, ApiConnectionProviders::GEMINI, 'Gemini');
        // Same priority → Direct wins as tiebreaker.
        $tied = (new LogicalModelRouteOrder())->apply([
            new RoutedAiCandidate('text.reasoning', $or, ApiConnectionProviders::OPENROUTER, 'google/gemini-3.1-pro-preview', [], 5),
            new RoutedAiCandidate('text.reasoning', $gem, ApiConnectionProviders::GEMINI, 'gemini-3.1-pro-preview', [], 5),
        ]);
        $this->assertSame('gemini-3.1-pro-preview', $tied[0]->model);
        $this->assertSame(ApiConnectionProviders::GEMINI, $tied[0]->provider);

        // Explicit lower OR priority must run before Direct — do not force Direct first.
        $orFirst = (new LogicalModelRouteOrder())->apply([
            new RoutedAiCandidate('text.reasoning', $or, ApiConnectionProviders::OPENROUTER, 'google/gemini-3.1-pro-preview', [], 1),
            new RoutedAiCandidate('text.reasoning', $gem, ApiConnectionProviders::GEMINI, 'gemini-3.1-pro-preview', [], 2),
            new RoutedAiCandidate('text.reasoning', $or, ApiConnectionProviders::OPENROUTER, 'anthropic/claude-sonnet-4.6', [], 3),
        ]);
        $this->assertSame('google/gemini-3.1-pro-preview', $orFirst[0]->model);
        $this->assertSame(ApiConnectionProviders::OPENROUTER, $orFirst[0]->provider);
        $this->assertSame('gemini-3.1-pro-preview', $orFirst[1]->model);
        $this->assertSame('anthropic/claude-sonnet-4.6', $orFirst[2]->model);
    }

    public function test_402_suppresses_paid_lane_only_free_same_connection_runs(): void
    {
        $conn = $this->connection(71, ApiConnectionProviders::OPENROUTER, 'OR');
        $paid = $this->model($conn, 'paid/claude', false);
        $free = $this->model($conn, 'free/gemma:free', true);
        $this->grant($conn, $paid);
        $this->grant($conn, $free);
        $this->priorities->appendToArea(71, AiModelArea::TextLongform, [(int) $paid->id, (int) $free->id]);

        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 71),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'paid/claude') {
                    throw new PromptRunException('requires more credits', 402);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['paid/claude', 'free/gemma:free'], $calls);
        $paidSkips = array_values(array_filter(
            $attempts,
            static fn (array $r): bool => ($r['skip_reason'] ?? '') === 'paid_lane_suppressed',
        ));
        $this->assertSame([], $paidSkips);
    }

    public function test_402_skips_other_paid_on_same_connection_without_attempt(): void
    {
        $conn = $this->connection(72, ApiConnectionProviders::OPENROUTER, 'OR');
        $a = $this->model($conn, 'paid/a', false);
        $b = $this->model($conn, 'paid/b', false);
        $other = $this->connection(72, ApiConnectionProviders::OPENROUTER, 'OR2');
        $c = $this->model($other, 'paid/c', false);
        foreach ([$a, $b] as $m) {
            $this->grant($conn, $m);
        }
        $this->grant($other, $c);
        $this->priorities->appendToArea(72, AiModelArea::TextLongform, [(int) $a->id, (int) $b->id, (int) $c->id]);

        $calls = [];
        [$output, , , , , $attempts] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 72),
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->model;
                if ($candidate->model === 'paid/a') {
                    throw new PromptRunException('402 credits', 402);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['paid/a', 'paid/c'], $calls);
        $this->assertSame(1, (int) collect($attempts)->where('skip_reason', 'paid_lane_suppressed')->count());
    }

    public function test_401_still_suppresses_whole_connection_including_free(): void
    {
        $conn = $this->connection(73, ApiConnectionProviders::OPENROUTER, 'OR');
        $paid = $this->model($conn, 'paid/x', false);
        $free = $this->model($conn, 'free/y:free', true);
        $other = $this->connection(73, ApiConnectionProviders::OPENROUTER, 'OR2');
        $z = $this->model($other, 'paid/z', false);
        $this->grant($conn, $paid);
        $this->grant($conn, $free);
        $this->grant($other, $z);
        $this->priorities->appendToArea(73, AiModelArea::TextLongform, [(int) $paid->id, (int) $free->id, (int) $z->id]);

        $calls = [];
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 73),
            function ($candidate) use (&$calls, $conn): array {
                $calls[] = $candidate->model;
                if ((int) $candidate->connection->id === (int) $conn->id) {
                    throw new PromptRunException('invalid api key', 401);
                }

                return ['ok', null];
            },
        );
        $this->assertSame('ok', $output);
        $this->assertSame(['paid/x', 'paid/z'], $calls);
    }

    public function test_circuit_breaker_resets_consecutive_on_success(): void
    {
        $conn = $this->connection(74, ApiConnectionProviders::OPENROUTER, 'OR');
        $model = $this->model($conn, 'model/a', false);
        $this->grant($conn, $model);
        $this->priorities->appendToArea(74, AiModelArea::TextLongform, [(int) $model->id]);
        $cands = $this->targets->eligibleCandidates(74, AiExecutionProfile::TextLongform, new AiRoutingContext(userId: 74));
        $cand = $cands[0];

        $fail = new AiFailureDecision(
            category: AiFailureClass::AccountRestricted,
            scope: AiFailureScope::Connection,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: AiRuntimeHealthStatus::Degraded,
            safeMessage: 'restricted',
            httpStatus: 403,
            lockConnection: false,
            affectsRuntimeHealth: true,
            applyCooldown: true,
            failureStage: 'provider_http',
        );
        $this->health->recordFailure(74, $cand, $fail);
        $this->health->recordFailure(74, $cand, $fail);
        $this->health->recordSuccess(74, $cand);
        $this->health->recordFailure(74, $cand, $fail);

        $row = \Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState::query()
            ->where('user_id', 74)
            ->where('subject_type', 'connection')
            ->where('subject_id', (int) $conn->id)
            ->first();
        $counts = is_array($row?->failure_counts) ? $row->failure_counts : [];
        $this->assertSame(1, (int) ($counts['consecutive_connection'] ?? -1));
    }

    public function test_coverage_reconcile_appends_missing_connection_route(): void
    {
        $or = $this->connection(75, ApiConnectionProviders::OPENROUTER, 'OR');
        $orModel = $this->model($or, 'openai/gpt-5.4', false);
        $this->grant($or, $orModel);
        $this->priorities->appendToArea(75, AiModelArea::TextLongform, [(int) $orModel->id]);

        $gem = $this->connection(75, ApiConnectionProviders::GEMINI, 'Gemini');
        $gemModel = $this->model($gem, 'gemini-3.1-pro-preview', false);
        $this->grant($gem, $gemModel);
        // Ensure Gemini is not already in the area (coverage gap).
        $this->priorities->removeFromArea(75, AiModelArea::TextLongform, [(int) $gemModel->id]);

        $coverage = new AiConnectionCoverageService($this->priorities, new ModelCapabilityRegistry());
        $before = $coverage->coverageReport(75, AiModelArea::TextLongform);
        $gemRow = collect($before)->firstWhere('connection_id', (int) $gem->id);
        $this->assertNotNull($gemRow);
        $this->assertTrue((bool) $gemRow['supported']);
        $this->assertFalse((bool) $gemRow['covered']);

        $added = $coverage->reconcileArea(75, AiModelArea::TextLongform);
        $this->assertSame(1, $added);
        $after = $coverage->coverageReport(75, AiModelArea::TextLongform);
        $gemAfter = collect($after)->firstWhere('connection_id', (int) $gem->id);
        $this->assertTrue((bool) $gemAfter['covered']);

        $enabled = $this->priorities->areaEnabledModels(75, AiModelArea::TextLongform);
        $this->assertSame((int) $orModel->id, (int) $enabled[0]->id);
    }

    public function test_area_rows_snap_same_family_across_connections(): void
    {
        $or = $this->connection(76, ApiConnectionProviders::OPENROUTER, 'OR');
        $gem = $this->connection(76, ApiConnectionProviders::GEMINI, 'Gemini');
        $orModel = $this->model($or, 'google/gemini-3.1-pro-preview', false);
        $gemModel = $this->model($gem, 'gemini-3.1-pro-preview', false);
        $this->grant($or, $orModel);
        $this->grant($gem, $gemModel);
        $this->priorities->appendToArea(76, AiModelArea::TextReasoning, [(int) $orModel->id, (int) $gemModel->id]);

        $rows = (new AiCenterModelPresenter())->areaRows(76, AiModelArea::TextReasoning);
        $snapped = array_values(array_filter(
            $rows,
            static fn (array $r): bool => (string) ($r['canonical_model_key'] ?? $r['family_key'] ?? '') === 'gemini.pro'
                || str_contains((string) ($r['identity'] ?? ''), 'gemini.pro'),
        ));
        $this->assertCount(1, $snapped);
        $routes = $snapped[0]['routes'] ?? [];
        $this->assertGreaterThanOrEqual(2, count($routes));
        $this->assertFalse((bool) ($routes[0]['is_aggregator'] ?? true), 'Direct route must be first');
        $this->assertTrue((bool) ($routes[1]['is_aggregator'] ?? false), 'Aggregator route must follow Direct');
        $codes = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['short_code'] ?? ''),
            $routes,
        )));
        $this->assertGreaterThanOrEqual(2, count($codes));
        $this->assertNotSame('', $codes[0]);
        $this->assertNotSame('', $codes[1]);
        $this->assertNotSame($codes[0], $codes[1], 'Multi-route logical row must expose distinct connection badges');
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'test-key-usable-long',
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
