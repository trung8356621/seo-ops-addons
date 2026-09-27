<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Contracts\ResolvedDecisionModel;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\DecisionModelCompletionService;
use Omnichannel\Addons\AiPrompt\Services\JevCompatibleDecisionTransport;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterModelEconomics;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterTextRoutingCatalog;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\DecisionModelIdentityCatalog;
use Tests\TestCase;

final class OpenRouterJevDecisionCatalogTest extends TestCase
{
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
            $table->string('name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_routing_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('profile_key')->nullable();
            $table->unsignedBigInteger('api_connection_id')->nullable();
            $table->string('model_key')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('ai_provider_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider_key');
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_discovery_classifies_openrouter_jev_as_decision_and_lists_it(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'output_modalities=decisions')) {
                return Http::response([
                    'data' => [
                        ['id' => 'typesafe/jev-latest', 'name' => 'Jev Latest', 'architecture' => ['modality' => 'text->decisions', 'output_modalities' => ['decisions']]],
                        ['id' => 'typesafe/jev-1.13', 'name' => 'Jev 1.13', 'architecture' => ['modality' => 'text->decisions', 'output_modalities' => ['decisions']]],
                        ['id' => 'respan/span-01', 'name' => 'Span', 'architecture' => ['modality' => 'text->decisions', 'output_modalities' => ['decisions']]],
                    ],
                ], 200);
            }

            return Http::response([
                'data' => [
                    ['id' => 'typesafe/jev-router', 'name' => 'Jev Router', 'architecture' => ['modality' => 'text->text', 'output_modalities' => ['text']]],
                    ['id' => 'deepseek/deepseek-chat', 'name' => 'DeepSeek Chat', 'architecture' => ['modality' => 'text->text']],
                ],
            ], 200);
        });

        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'sk-or-test-not-real',
            'status' => 'active',
        ]);

        $this->assertTrue((new AiModelRouterService())->syncOpenAiCompatibleModels((int) $connection->id));

        $jev = SeoAiModel::query()->where('raw_model_name', 'typesafe/jev-latest')->first();
        $this->assertNotNull($jev);
        $caps = is_array($jev->capabilities) ? $jev->capabilities : [];
        $this->assertSame(AiModelArea::Decision->value, $caps[AiModelArea::PRIMARY_TYPE_KEY] ?? null);
        $this->assertNotContains(AiModelCapability::TextGenerate->value, $caps['resolved'] ?? []);
        $this->assertFalse(OpenRouterModelEconomics::isChatTextModel($caps, 'typesafe/jev-latest'));
        $this->assertTrue(OpenRouterModelEconomics::isChatTextModel(
            ['provider_metadata' => ['architecture' => ['modality' => 'text->text']]],
            'deepseek/deepseek-chat',
        ));

        $this->assertNull(SeoAiModel::query()->where('raw_model_name', 'respan/span-01')->first());
        $routerRow = SeoAiModel::query()->where('raw_model_name', 'typesafe/jev-router')->first();
        $this->assertNotNull($routerRow);
        $routerCaps = is_array($routerRow->capabilities) ? $routerRow->capabilities : [];
        $this->assertNotSame(AiModelArea::Decision->value, $routerCaps[AiModelArea::PRIMARY_TYPE_KEY] ?? null);

        $presenter = new AiCenterModelPresenter();
        $decision = $presenter->availablePage(1, (int) $connection->id, [
            'area' => 'decision',
            'status' => 'available',
            'provider' => 'openrouter',
        ]);
        $this->assertSame(2, $decision['total']);
        $this->assertSame(2, $presenter->areaCounts(1)['decision']['available']);
        $raws = [];
        foreach ($decision['rows'] as $row) {
            $this->assertSame('decision.jev', $row['family_key']);
            $raws[] = (string) ($row['releases'][0]['raw'] ?? '');
        }
        $this->assertEqualsCanonicalizing(DecisionModelIdentityCatalog::openRouterJevModelIds(), $raws);

        $otherProvider = $presenter->availablePage(1, (int) $connection->id, [
            'area' => 'decision',
            'status' => 'available',
            'provider' => 'gemini',
        ]);
        $this->assertSame(0, $otherProvider['total']);

        $text = $presenter->availablePage(1, (int) $connection->id, [
            'area' => 'text.reasoning',
            'status' => 'available',
            'provider' => 'all',
        ]);
        $textRaws = [];
        foreach ($text['rows'] as $row) {
            foreach ($row['releases'] ?? [] as $release) {
                $textRaws[] = (string) ($release['raw'] ?? '');
            }
        }
        $this->assertNotContains('typesafe/jev-latest', $textRaws);
        $this->assertContains('deepseek/deepseek-chat', $textRaws);

        $decisionRaws = [];
        foreach ($presenter->availablePage(1, (int) $connection->id, ['area' => 'decision', 'status' => 'available'])['rows'] as $row) {
            foreach ($row['releases'] ?? [] as $release) {
                $decisionRaws[] = (string) ($release['raw'] ?? '');
            }
        }
        $this->assertNotContains('typesafe/jev-router', $decisionRaws);
        $this->assertNotContains('deepseek/deepseek-chat', $decisionRaws);

        $mapped = (new \Omnichannel\Addons\AiPrompt\Services\AiRecommendedModelMapper())->mapForUser(1);
        $this->assertGreaterThan(0, $mapped->enabled);
        $enabledAny = false;
        foreach (['typesafe/jev-latest', 'typesafe/jev-1.13'] as $raw) {
            $model = SeoAiModel::query()->where('raw_model_name', $raw)->first();
            $caps = is_array($model?->capabilities) ? $model->capabilities : [];
            $enabledAny = $enabledAny || (bool) ($caps['omi_areas']['decision']['enabled'] ?? false);
        }
        $this->assertTrue($enabledAny);
    }

    public function test_decision_model_without_transport_is_not_available(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => 'DeepSeek',
            'api_key' => 'sk-test',
            'status' => 'active',
        ]);
        SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'typesafe/jev-1.13',
            'display_name' => 'Jev',
            'status' => 'active',
            'is_hidden' => true,
            'capabilities' => [
                'resolved' => array_map(
                    static fn (AiModelCapability $capability): string => $capability->value,
                    AiModelCapability::decision(),
                ),
            ],
        ]);

        $presenter = new AiCenterModelPresenter();
        $available = $presenter->availablePage(1, (int) $connection->id, [
            'area' => 'decision',
            'status' => 'available',
        ]);
        $this->assertSame(0, $available['total']);

        $unknown = $presenter->availablePage(1, (int) $connection->id, [
            'area' => 'decision',
            'status' => 'unknown',
        ]);
        $this->assertSame(1, $unknown['total']);
        $this->assertSame('unavailable', $unknown['rows'][0]['decision_transport']);
        $this->assertStringContainsString('transport unavailable', (string) $unknown['rows'][0]['model_name']);
    }

    public function test_catalog_does_not_insert_a_fake_jev_row(): void
    {
        $catalog = (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/DecisionModelIdentityCatalog.php');
        $this->assertDoesNotMatchRegularExpression('/SeoAiModel::/', $catalog);
        $seed = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/OpenRouterTextRoutingCatalog.php');
        $this->assertStringNotContainsString('typesafe/jev', $seed);
        $this->assertArrayNotHasKey('typesafe/jev-latest', OpenRouterTextRoutingCatalog::MODELS);
        $this->assertSame(0, SeoAiModel::query()->count());
    }

    public function test_jev_completion_uses_decisions_endpoint(): void
    {
        Http::fake([
            'https://openrouter.ai/api/alpha/decisions' => Http::response([
                'model' => 'typesafe/jev-1.13',
                'answers' => [
                    'need_site' => ['type' => 'noul', 'noul' => 0.2],
                    'need_keywords' => ['type' => 'noul', 'noul' => 0.4],
                    'need_gsc' => ['type' => 'noul', 'noul' => 0.91],
                    'needs_parameter_extraction' => ['type' => 'noul', 'noul' => 0.1],
                    'needs_user_confirmation' => ['type' => 'noul', 'noul' => 0.0],
                ],
            ], 200),
        ]);
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'sk-or-test-not-real',
            'status' => 'active',
        ]);

        $json = (new DecisionModelCompletionService())->complete(
            new ResolvedDecisionModel((int) $connection->id, 'openrouter', 'typesafe/jev-1.13', 'Jev', 10),
            'September traffic',
            800,
        );
        $decoded = json_decode($json, true);
        $this->assertSame(0.91, $decoded['needs']['gsc']);
        $this->assertArrayNotHasKey('period', $decoded['parameters']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://openrouter.ai/api/alpha/decisions'
                && ($body['model'] ?? '') === 'typesafe/jev-1.13'
                && ($body['questions']['need_gsc']['type'] ?? '') === 'noul'
                && ! isset($body['messages']);
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'chat/completions'));
    }

    public function test_laya_transport_is_an_explicit_gap(): void
    {
        $connection = new ApiConnection([
            'provider' => ApiConnectionProviders::OPENROUTER,
            'api_key' => 'sk-or-test-not-real',
        ]);
        $transport = new JevCompatibleDecisionTransport();
        $this->assertFalse($transport->supports($connection, 'laya'));
        $gap = JevCompatibleDecisionTransport::gap();
        $this->assertSame('Laya or another self-hosted Jev-compatible endpoint', $gap['use_case']);
        $this->assertNotSame('', $gap['missing']);
    }
}
