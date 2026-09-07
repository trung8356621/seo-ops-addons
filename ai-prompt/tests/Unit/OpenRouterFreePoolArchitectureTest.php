<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCapacityStatusService;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreeLanguageGateService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterModelEconomics;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\OpenRouterFreeLanguageState;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class OpenRouterFreePoolArchitectureTest extends TestCase
{
    private AiModelPriorityService $priorities;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['seo_ai_models', 'api_connections', 'wp_options'] as $table) {
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
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        $this->priorities = new AiModelPriorityService();
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
        \Illuminate\Support\Facades\Cache::forget('seo_content_language_settings.v1');
        \Illuminate\Support\Facades\DB::table('wp_options')->where('option_name', 'seo_content_language_settings')->delete();
    }

    private function setPrimaryLanguage(string $code): void
    {
        \Illuminate\Support\Facades\Cache::forget('seo_content_language_settings.v1');
        $service = \Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService::withDefaults();
        $service->save(['default_content_language' => $code]);
        $this->app->instance(\Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService::class, $service);
    }

    public function test_a_free_detection_from_pricing_and_suffix(): void
    {
        $this->assertTrue(OpenRouterModelEconomics::isFree([
            'provider_metadata' => ['pricing' => ['prompt' => '0', 'completion' => '0']],
        ], 'acme/chat'));
        $this->assertFalse(OpenRouterModelEconomics::isFree([
            'provider_metadata' => ['pricing' => ['prompt' => '0.000001', 'completion' => '0']],
        ], 'acme/chat'));
        $this->assertTrue(OpenRouterModelEconomics::isFree([], 'google/gemma-4-31b-it:free'));
    }

    public function test_b_text_grouping_one_pool_hides_individuals(): void
    {
        $or = $this->connection(90);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        $a = $this->model($or, 'google/gemma-x:free', 'Gemma Free', true);
        $b = $this->model($or, 'qwen/qwen-x:free', 'Qwen Free', true);
        $c = $this->model($or, 'meta-llama/llama-x:free', 'Llama Free', true);
        $paid = $this->model($or, 'openai/gpt-5.4-nano', 'GPT-5.4 Nano', false);
        foreach ([$router, $a, $b, $c, $paid] as $m) {
            $this->priorities->appendToArea(90, AiModelArea::TextFast, [(int) $m->id]);
        }

        // English primary → supported
        $this->setPrimaryLanguage('en');

        $rows = (new AiCenterModelPresenter())->areaRows(90, AiModelArea::TextFast);
        $pools = array_values(array_filter($rows, static fn (array $r): bool => ! empty($r['is_free_pool'])));
        $this->assertCount(1, $pools);
        $this->assertGreaterThanOrEqual(3, (int) ($pools[0]['member_count'] ?? 0));
        foreach ($rows as $row) {
            $name = (string) ($row['model_name'] ?? $row['label'] ?? '');
            $this->assertStringNotContainsString('Gemma Free', $name);
            $this->assertStringNotContainsString('Qwen Free', $name);
            $this->assertStringNotContainsString('Llama Free', $name);
        }
        $paidRows = array_values(array_filter(
            $rows,
            static fn (array $r): bool => str_contains((string) ($r['model_name'] ?? ''), 'GPT-5.4 Nano'),
        ));
        $this->assertNotEmpty($paidRows);
    }

    public function test_c_no_image_video_free_pool(): void
    {
        $or = $this->connection(91);
        $this->model($or, 'google/gemma-x:free', 'Gemma Free', true);
        $pool = (new OpenRouterFreePoolService())->presentPoolRow(91, AiModelArea::Image);
        $this->assertNull($pool);
        $poolV = (new OpenRouterFreePoolService())->presentPoolRow(91, AiModelArea::Video);
        $this->assertNull($poolV);
    }

    public function test_d_pending_language_not_unsupported(): void
    {
        $or = $this->connection(92);
        $model = $this->model($or, 'google/gemma-pending:free', 'Gemma Pending', true);
        $this->setPrimaryLanguage('vi');
        $gate = new OpenRouterFreeLanguageGateService();
        $gate->ensurePendingIfMissing($model);
        $model->refresh();
        $this->assertSame(OpenRouterFreeLanguageState::Pending, $gate->effectiveState($model));
        $this->assertNotSame(OpenRouterFreeLanguageState::Unsupported, $gate->effectiveState($model));
    }

    public function test_e_english_skips_language_llm(): void
    {
        $this->setPrimaryLanguage('en');
        $gate = new OpenRouterFreeLanguageGateService();
        $this->assertTrue($gate->isEnglishPrimary());
        $or = $this->connection(93);
        $model = $this->model($or, 'google/gemma-en:free', 'Gemma EN', true);
        $this->assertSame(OpenRouterFreeLanguageState::Supported, $gate->effectiveState($model));
    }

    public function test_f_manual_language_evaluation_no_rank_mutation(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(94);
        $model = $this->model($or, 'google/gemma-vi:free', 'Gemma VI', true);
        $gate = new OpenRouterFreeLanguageGateService();
        $gate->recordEvaluation($model, 'vi', true, 0.9, 'ok');
        $model->refresh();
        $this->assertSame(OpenRouterFreeLanguageState::Supported, $gate->effectiveState($model));
        $gate->recordEvaluation($model, 'vi', false, 0.9, 'no');
        $model->refresh();
        $this->assertSame(OpenRouterFreeLanguageState::Unsupported, $gate->effectiveState($model));
        // Ranking is metadata-only; language state must not change provider_model_id identity.
        $this->assertSame('google/gemma-vi:free', (string) $model->raw_model_name);
    }

    public function test_j_snap_regression_gemini_pro_multi_route(): void
    {
        $or = $this->connection(95);
        $gem = ApiConnection::query()->create([
            'user_id' => 95,
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'GG',
            'api_key' => 'k',
            'is_global' => false,
            'status' => 'active',
        ]);
        $orModel = $this->model($or, 'google/gemini-3.1-pro-preview', 'Gemini Pro', false);
        $gemModel = SeoAiModel::query()->create([
            'api_connection_id' => (int) $gem->id,
            'category' => AiModelCategory::GEMINI_PRO,
            'raw_model_name' => 'gemini-3.1-pro-preview',
            'display_name' => 'Gemini Pro',
            'priority' => 10,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => false,
            'capabilities' => [],
        ]);
        $this->priorities->appendToArea(95, AiModelArea::TextReasoning, [(int) $orModel->id, (int) $gemModel->id]);
        $rows = (new AiCenterModelPresenter())->areaRows(95, AiModelArea::TextReasoning);
        $snapped = array_values(array_filter(
            $rows,
            static fn (array $r): bool => (string) ($r['canonical_model_key'] ?? '') === 'gemini.pro',
        ));
        $this->assertCount(1, $snapped);
        $routes = $snapped[0]['routes'] ?? [];
        $this->assertGreaterThanOrEqual(2, count($routes));
        $this->assertFalse((bool) ($routes[0]['is_aggregator'] ?? true));
        $this->assertTrue((bool) ($routes[1]['is_aggregator'] ?? false));
        $codes = array_map(static fn (array $r): string => (string) ($r['short_code'] ?? ''), $routes);
        $this->assertGreaterThanOrEqual(2, count(array_filter($codes)));
    }

    public function test_a_pending_pool_gate_off_expands_exact_members_not_openrouter_free(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(101);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        for ($i = 1; $i <= 7; $i++) {
            $this->model($or, "vendor/free-{$i}:free", "Free {$i}", true);
        }
        $pool = new OpenRouterFreePoolService();
        $this->assertFalse($pool->isLanguageGateEnabled(101));
        $this->assertCount(7, $pool->runtimeMembers(101, AiModelArea::TextFast));
        $counts = $pool->catalogLanguageCounts(101, AiModelArea::TextFast);
        $this->assertSame(7, $counts['technical']);
        $this->assertSame(0, $counts['supported']);
        $this->assertSame(7, $counts['pending']);

        $candidate = new \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate(
            profile: 'text.fast',
            connection: $or,
            provider: ApiConnectionProviders::OPENROUTER,
            model: OpenRouterModelEconomics::FREE_ROUTER_ID,
            capabilities: [],
            priority: 1,
            seoAiModelId: (int) $router->id,
            isFree: true,
        );
        $expanded = $pool->expandFreeRouterCandidates(101, AiModelArea::TextFast, [$candidate]);
        $this->assertCount(7, $expanded);
        $models = array_map(static fn ($c) => $c->model, $expanded);
        $this->assertNotContains(OpenRouterModelEconomics::FREE_ROUTER_ID, $models);
        $this->assertContains('vendor/free-1:free', $models);
        $this->assertContains('vendor/free-7:free', $models);
        $diag = $pool->lastExpansionDiagnostics();
        $this->assertNull($diag['free_pool_reason'] ?? null);
        $this->assertSame(7, (int) ($diag['free_pool_expanded_candidates'] ?? -1));
        $this->assertSame(7, (int) ($diag['free_pool_runtime_members'] ?? -1));
    }

    public function test_a2_gate_on_pending_only_does_not_fallback_to_openrouter_free(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(111);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        for ($i = 1; $i <= 3; $i++) {
            $this->model($or, "vendor/gated-{$i}:free", "Gated {$i}", true);
        }
        $pool = new OpenRouterFreePoolService();
        $pool->enableLanguageGate(111, 'vi');
        $this->assertTrue($pool->isLanguageGateEnabled(111));
        $this->assertCount(0, $pool->runtimeMembers(111, AiModelArea::TextFast));

        $candidate = new \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate(
            profile: 'text.fast',
            connection: $or,
            provider: ApiConnectionProviders::OPENROUTER,
            model: OpenRouterModelEconomics::FREE_ROUTER_ID,
            capabilities: [],
            priority: 1,
            seoAiModelId: (int) $router->id,
            isFree: true,
        );
        $expanded = $pool->expandFreeRouterCandidates(111, AiModelArea::TextFast, [$candidate]);
        $this->assertSame([], $expanded);
        $diag = $pool->lastExpansionDiagnostics();
        $this->assertSame(OpenRouterFreePoolService::DIAG_PENDING_LANGUAGE, $diag['free_pool_reason'] ?? null);
    }

    public function test_b_supported_expands_exact_free_provider_model_id(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(102);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        $exact = $this->model($or, 'vendor/model:free', 'Exact Free', true);
        (new OpenRouterFreeLanguageGateService())->recordEvaluation($exact, 'vi', true, 0.9, 'ok');
        $exact->refresh();
        $pool = new OpenRouterFreePoolService();
        // Gate OFF still expands exact technical members (including evaluated ones).
        $candidate = new \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate(
            profile: 'text.fast',
            connection: $or,
            provider: ApiConnectionProviders::OPENROUTER,
            model: OpenRouterModelEconomics::FREE_ROUTER_ID,
            capabilities: [],
            priority: 1,
            seoAiModelId: (int) $router->id,
            isFree: true,
        );
        $expanded = $pool->expandFreeRouterCandidates(102, AiModelArea::TextFast, [$candidate]);
        $this->assertCount(1, $expanded);
        $this->assertSame('vendor/model:free', $expanded[0]->model);
        $this->assertNotSame(OpenRouterModelEconomics::FREE_ROUTER_ID, $expanded[0]->model);

        $pool->enableLanguageGate(102, 'vi');
        $expandedOn = $pool->expandFreeRouterCandidates(102, AiModelArea::TextFast, [$candidate]);
        $this->assertCount(1, $expandedOn);
        $this->assertSame('vendor/model:free', $expandedOn[0]->model);
    }

    public function test_c_english_pending_metadata_is_effective_supported(): void
    {
        $this->setPrimaryLanguage('en');
        $or = $this->connection(103);
        $model = $this->model($or, 'google/gemma-en-meta:free', 'Gemma EN', true);
        $gate = new OpenRouterFreeLanguageGateService();
        $this->assertSame(OpenRouterFreeLanguageState::Supported, $gate->effectiveState($model));
        $pool = new OpenRouterFreePoolService();
        $members = $pool->runtimeMembers(103, AiModelArea::TextFast);
        $this->assertNotEmpty($members);
        $this->assertSame('google/gemma-en-meta:free', (string) $members[0]->raw_model_name);
    }

    public function test_d_pool_ui_gate_off_active_not_pending_status(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(104);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        for ($i = 1; $i <= 7; $i++) {
            $this->model($or, "vendor/ui-{$i}:free", "UI Free {$i}", true);
        }
        $pool = new OpenRouterFreePoolService();
        $row = $pool->presentPoolRow(104, AiModelArea::TextFast, $router);
        $this->assertNotNull($row);
        $this->assertFalse((bool) ($row['language_gate_enabled'] ?? true));
        $this->assertSame(OpenRouterFreePoolService::POOL_STATUS_ACTIVE, $row['status']);
        $this->assertSame(7, (int) $row['available_count']);
        $this->assertSame(7, (int) $row['pending_language_count']);
        $this->assertStringContainsString('Chưa kiểm tra ngôn ngữ', (string) $row['subtitle']);

        $pool->enableLanguageGate(104, 'vi');
        $rowOn = $pool->presentPoolRow(104, AiModelArea::TextFast, $router);
        $this->assertSame(OpenRouterFreePoolService::POOL_STATUS_PENDING_LANGUAGE, $rowOn['status']);
        $this->assertSame(0, (int) $rowOn['available_count']);
        $this->assertStringContainsString('chờ đánh giá', (string) $rowOn['subtitle']);
    }

    public function test_e_capacity_true_when_gate_off_with_pending_only(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(105);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        $this->model($or, 'vendor/cap-pending:free', 'Cap Pending', true);
        $this->priorities->appendToArea(105, AiModelArea::TextFast, [(int) $router->id]);

        $targets = new AiRoutingTargetService(new ModelCapabilityRegistry());
        $this->app->instance(AiRoutingTargetService::class, $targets);
        $this->app->instance(OpenRouterFreePoolService::class, new OpenRouterFreePoolService());

        $status = (new AiCapacityStatusService())->status(105);
        $this->assertTrue((bool) $status['free_text_available']);
        $this->assertNotSame(AiCapacityStatusService::STATE_CRITICAL, $status['state']);
    }

    public function test_e2_capacity_false_when_gate_on_and_zero_supported(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(115);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        $this->model($or, 'vendor/cap-blocked:free', 'Cap Blocked', true);
        $this->priorities->appendToArea(115, AiModelArea::TextFast, [(int) $router->id]);
        $pool = new OpenRouterFreePoolService();
        $pool->enableLanguageGate(115, 'vi');

        $this->app->instance(AiRoutingTargetService::class, new AiRoutingTargetService(new ModelCapabilityRegistry()));
        $this->app->instance(OpenRouterFreePoolService::class, $pool);

        $status = (new AiCapacityStatusService())->status(115);
        $this->assertFalse((bool) $status['free_text_available']);
    }

    public function test_f_capacity_true_when_supported_runtime_member(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(106);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', true);
        $supported = $this->model($or, 'vendor/cap-ok:free', 'Cap OK', true);
        (new OpenRouterFreeLanguageGateService())->recordEvaluation($supported, 'vi', true, 0.95, 'ok');
        $this->priorities->appendToArea(106, AiModelArea::TextFast, [(int) $router->id]);
        $pool = new OpenRouterFreePoolService();
        $pool->enableLanguageGate(106, 'vi');

        $this->app->instance(AiRoutingTargetService::class, new AiRoutingTargetService(new ModelCapabilityRegistry()));
        $this->app->instance(OpenRouterFreePoolService::class, $pool);

        $status = (new AiCapacityStatusService())->status(106);
        $this->assertTrue((bool) $status['free_text_available']);
    }

    public function test_gate_on_runtime_only_supported_members(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(120);
        $a = $this->model($or, 'vendor/a:free', 'A', true);
        $b = $this->model($or, 'vendor/b:free', 'B', true);
        $c = $this->model($or, 'vendor/c:free', 'C', true);
        $d = $this->model($or, 'vendor/d:free', 'D', true);
        $gate = new OpenRouterFreeLanguageGateService();
        $gate->recordEvaluation($a, 'vi', true, 0.9, 'ok');
        $gate->recordEvaluation($b, 'vi', true, 0.9, 'ok');
        // C stays PENDING via ensurePendingIfMissing
        $gate->recordEvaluation($d, 'vi', false, 0.9, 'no');
        $pool = new OpenRouterFreePoolService();
        $pool->enableLanguageGate(120, 'vi');
        $members = $pool->runtimeMembers(120, AiModelArea::TextFast);
        $ids = array_map(static fn ($m) => (string) $m->raw_model_name, $members);
        $this->assertSame(['vendor/a:free', 'vendor/b:free'], array_values($ids));
        $this->assertNotContains('vendor/c:free', $ids);
        $this->assertNotContains('vendor/d:free', $ids);
    }

    public function test_failed_evaluation_keeps_gate_off(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(121);
        $this->model($or, 'vendor/fail-eval:free', 'Fail Eval', true);
        $pool = new OpenRouterFreePoolService();
        $this->assertFalse($pool->isLanguageGateEnabled(121));
        // Simulate partial eval without enableLanguageGate (failed midway).
        (new OpenRouterFreeLanguageGateService())->recordEvaluation(
            SeoAiModel::query()->where('raw_model_name', 'vendor/fail-eval:free')->first(),
            'vi',
            true,
            0.5,
            'partial',
        );
        $this->assertFalse($pool->isLanguageGateEnabled(121));
        $this->assertCount(1, $pool->runtimeMembers(121, AiModelArea::TextFast));
    }

    public function test_disable_language_gate_restores_technical_runtime(): void
    {
        $this->setPrimaryLanguage('vi');
        $or = $this->connection(122);
        $this->model($or, 'vendor/restore:free', 'Restore', true);
        $pool = new OpenRouterFreePoolService();
        $pool->enableLanguageGate(122, 'vi');
        $this->assertCount(0, $pool->runtimeMembers(122, AiModelArea::TextFast));
        $pool->disableLanguageGate(122, 'vi');
        $this->assertFalse($pool->isLanguageGateEnabled(122));
        $this->assertCount(1, $pool->runtimeMembers(122, AiModelArea::TextFast));
    }

    public function test_g_snap_regression_unchanged_alias(): void
    {
        // Alias of test_j — Gemini Pro [GG][OR] snap must remain one logical card.
        $this->test_j_snap_regression_gemini_pro_multi_route();
    }

    public function test_h_402_paid_lane_regression_contract(): void
    {
        $path = dirname(__DIR__).'/Unit/LogicalModelFallbackArchitectureTest.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString(
            'test_402_suppresses_paid_lane_only_free_same_connection_runs',
            $src,
        );
    }

    private function connection(int $userId): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OR',
            'api_key' => 'k',
            'is_global' => false,
            'status' => 'active',
        ]);
    }

    private function model(ApiConnection $connection, string $raw, string $display, bool $free): SeoAiModel
    {
        $caps = [
            'provider_metadata' => [
                'pricing' => $free
                    ? ['prompt' => '0', 'completion' => '0']
                    : ['prompt' => '0.000001', 'completion' => '0.000002'],
                'architecture' => ['modality' => 'text->text'],
                'context_length' => 32000,
            ],
            'resolved' => ['text.generate', 'text.reasoning'],
        ];

        return SeoAiModel::query()->create([
            'api_connection_id' => (int) $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => $raw,
            'display_name' => $display,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => false,
            'capabilities' => $caps,
        ]);
    }
}
