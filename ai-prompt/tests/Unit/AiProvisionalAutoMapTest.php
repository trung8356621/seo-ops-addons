<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiProvisionalModelRankingPolicy;
use Omnichannel\Addons\AiPrompt\Services\AiRecommendedModelMapper;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\BuiltInModelCapabilityCatalog;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class AiProvisionalAutoMapTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRecommendedModelMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        ModelCapabilityRegistry::flushRequestMemo();
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
            $table->string('display_name')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('status')->default('active');
            $table->boolean('is_hidden')->default(true);
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
        $this->mapper = new AiRecommendedModelMapper(priorities: $this->priorities);
    }

    public function test_curated_catalog_still_maps_known_family(): void
    {
        $conn = $this->connection(50, ApiConnectionProviders::DEEPSEEK, 'DS');
        $model = $this->model($conn, 'deepseek-chat', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextLongform->value,
        ], hidden: true);
        // Grant physical support via capabilities bag used by registry overlay is not needed —
        // BuiltIn DeepSeek chat has text.generate.
        $result = $this->mapper->mapForUser(50);
        $this->assertGreaterThanOrEqual(1, $result->curatedMatched);
        $model->refresh();
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($model, AiModelArea::TextLongform)
            || $this->priorities->isExplicitlyAreaEnabled($model, AiModelArea::TextFast)
            || $this->priorities->isExplicitlyAreaEnabled($model, AiModelArea::TextReasoning)
            || $this->priorities->isExplicitlyAreaEnabled($model, AiModelArea::Text));
    }

    public function test_nvidia_chat_model_is_heuristic_mapped_embed_rejected(): void
    {
        $conn = $this->connection(51, ApiConnectionProviders::NVIDIA, 'NIM');
        $chat = $this->model($conn, 'google/gemma-3-12b-it', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextFast->value,
            AiModelArea::PRIMARY_TYPE_SOURCE_KEY => 'auto',
        ]);
        $embed = $this->model($conn, 'nvidia/embed-qa-4', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextFast->value,
        ]);
        $safety = $this->model($conn, 'nvidia/nemotron-3.5-content-safety', []);

        $result = $this->mapper->mapForUser(51);
        $this->assertGreaterThanOrEqual(1, $result->heuristicRanked);
        $this->assertGreaterThanOrEqual(1, $result->enabled);
        $chat->refresh();
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($chat, AiModelArea::TextFast));
        $this->assertFalse((bool) $chat->is_hidden);
        $this->assertSame('estimated', $chat->capabilities[AiRecommendedModelMapper::PROVISIONAL_KEY]['score_source'] ?? null);

        $embed->refresh();
        $safety->refresh();
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($embed, AiModelArea::TextFast));
        $this->assertFalse($this->priorities->isExplicitlyAreaEnabled($safety, AiModelArea::TextFast));
        $this->assertGreaterThanOrEqual(1, $result->rejected);
    }

    public function test_custom_openai_compatible_provider_can_be_auto_mapped(): void
    {
        $conn = $this->connection(52, 'groqcloud', 'Groq');
        $model = $this->model($conn, 'llama-3.3-70b-instruct', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextFast->value,
            AiModelArea::PRIMARY_TYPE_SOURCE_KEY => 'auto',
        ]);
        $result = $this->mapper->mapForUser(52);
        $this->assertGreaterThanOrEqual(1, $result->heuristicRanked);
        $model->refresh();
        $this->assertTrue($this->priorities->isExplicitlyAreaEnabled($model, AiModelArea::TextFast));
    }

    public function test_unknown_price_is_not_free_and_missing_latency_not_fast(): void
    {
        $conn = $this->connection(53, ApiConnectionProviders::NVIDIA, 'NIM');
        $model = $this->model($conn, 'mistralai/mistral-large-4-1t-a52b', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextLongform->value,
            'provider_metadata' => [],
        ]);
        $score = (new AiProvisionalModelRankingPolicy())->score(
            $model,
            AiModelArea::TextLongform,
            [AiModelCapability::TextGenerate->value],
        );
        $this->assertSame('unknown', $score->dimensions['price']['source']);
        $this->assertStringContainsString('not_free', (string) ($score->dimensions['price']['note'] ?? ''));
        $this->assertSame('unknown', $score->dimensions['speed']['source']);
        $this->assertStringContainsString('not_fast', (string) ($score->dimensions['speed']['note'] ?? ''));
        $this->assertContains('price', $score->missingMetadata);
        $this->assertEqualsWithDelta(AiProvisionalModelRankingPolicy::NEUTRAL, $score->dimensions['price']['value'], 0.001);
    }

    public function test_repeated_auto_map_is_idempotent_and_preserves_manual_order(): void
    {
        $conn = $this->connection(54, ApiConnectionProviders::NVIDIA, 'NIM');
        $a = $this->model($conn, 'google/gemma-3-4b-it', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextFast->value,
        ]);
        $b = $this->model($conn, 'minimaxai/minimax-m3.1-flash', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextFast->value,
        ]);
        $first = $this->mapper->mapForUser(54);
        $this->assertGreaterThanOrEqual(1, $first->enabled);
        $this->priorities->reorderCapabilityModels(54, AiModelArea::TextFast, [(int) $b->id, (int) $a->id], (int) $conn->id);
        // Mark as manual order by rewriting source on membership.
        foreach ([$a, $b] as $model) {
            $model->refresh();
            $caps = is_array($model->capabilities) ? $model->capabilities : [];
            $areas = is_array($caps['omi_areas'] ?? null) ? $caps['omi_areas'] : [];
            if (isset($areas['fast_text'])) {
                $areas['fast_text']['source'] = AiModelArea::SOURCE_MANUAL;
                $areas['fast_text']['enabled'] = true;
            }
            $caps['omi_areas'] = $areas;
            $model->capabilities = $caps;
            $model->save();
        }
        $before = [
            $this->priorities->physicalAreaPriority($b->fresh(), AiModelArea::TextFast),
            $this->priorities->physicalAreaPriority($a->fresh(), AiModelArea::TextFast),
        ];
        $second = $this->mapper->mapForUser(54);
        $this->assertSame(0, $second->enabled);
        $after = [
            $this->priorities->physicalAreaPriority($b->fresh(), AiModelArea::TextFast),
            $this->priorities->physicalAreaPriority($a->fresh(), AiModelArea::TextFast),
        ];
        $this->assertSame($before, $after);
    }

    public function test_capability_heuristic_rejects_clip_and_starcoder(): void
    {
        $this->assertSame([], BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('nvidia/nvclip'));
        $this->assertSame([], BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('bigcode/starcoder2-15b'));
        $this->assertSame([], BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('nvidia/riva-translate-4b-instruct-v2'));
        $this->assertSame([], BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('nvidia/ai-synthetic-video-detector'));
        $caps = BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('nvidia/nemotron-3.5-lightning-30b-a3b');
        $this->assertContains(AiModelCapability::TextGenerate->value, $caps ?? []);
        $this->assertContains(
            AiModelCapability::TextGenerate->value,
            BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('minimaxai/minimax-m3.1-flash') ?? [],
        );
    }

    public function test_assessor_rejects_translate_even_when_id_contains_instruct(): void
    {
        $conn = $this->connection(61, ApiConnectionProviders::NVIDIA, 'NIM');
        $model = $this->model($conn, 'nvidia/riva-translate-4b-instruct-v2', [
            AiModelArea::PRIMARY_TYPE_KEY => AiModelArea::TextFast->value,
        ], hidden: false);
        $assessment = (new \Omnichannel\Addons\AiPrompt\Services\AiTextCompatibilityAssessor)->assess($conn, $model);
        $this->assertFalse($assessment['compatible']);
        $this->assertSame('rejected_non_text_workload', $assessment['reason']);
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'sk-test-key-long-enough',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function model(ApiConnection $connection, string $raw, array $capabilities, bool $hidden = true): SeoAiModel
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
}
