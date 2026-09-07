<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
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
