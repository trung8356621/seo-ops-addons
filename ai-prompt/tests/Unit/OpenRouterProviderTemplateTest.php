<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateStore;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Tests\TestCase;

final class OpenRouterProviderTemplateTest extends TestCase
{
    private AiModelRouterService $router;

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
            $table->text('api_key');
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_ai_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id');
            $table->string('category');
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

        $registry = new ModelCapabilityRegistry();
        $targets = new AiRoutingTargetService($registry);
        $this->router = new AiModelRouterService($registry, $targets, new AiRoutingBootstrapService($registry, $targets));
    }

    public function test_openrouter_is_catalogued(): void
    {
        $catalog = new \Omnichannel\Addons\AiPrompt\Services\SeoApiConnectionProviderCatalog();
        $this->assertTrue($catalog->has(ApiConnectionProviders::OPENROUTER));
    }

    public function test_sync_keeps_exact_ids_and_hidden_preference(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'data' => [
                    ['id' => 'deepseek/deepseek-chat', 'name' => 'DeepSeek Chat'],
                    ['id' => 'google/gemini-flash-1', 'name' => 'Gemini Flash'],
                ],
            ], 200),
        ]);

        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'sk-or-test-not-real',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);

        $this->assertTrue($this->router->syncOpenAiCompatibleModels((int) $connection->id));

        $chat = SeoAiModel::query()->where('raw_model_name', 'deepseek/deepseek-chat')->first();
        $this->assertNotNull($chat);
        $this->assertTrue((bool) $chat->is_hidden);
        $freeRouter = SeoAiModel::query()->where('raw_model_name', 'openrouter/free')->first();
        $this->assertNotNull($freeRouter);
        $this->assertFalse((bool) $freeRouter->is_hidden);
        $this->assertSame(1, SeoAiModel::query()->where('is_hidden', false)->count());

        $this->assertTrue($this->router->syncOpenAiCompatibleModels((int) $connection->id));
        $chat->refresh();
        $this->assertTrue((bool) $chat->is_hidden);
        $this->assertGreaterThan(1, SeoAiModel::query()->count());
        $family = (new AiModelFamilyCatalog())->familyForModelId('deepseek/deepseek-chat');
        $this->assertNotNull($family);
        $this->assertSame('deepseek.chat', $family->familyKey);
    }

    public function test_store_blocks_unauthenticated_import(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $parsed = (new \Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateParser())->parse(json_encode([
            'schema_version' => '1.0',
            'provider' => ['key' => 'example-ai', 'name' => 'Example', 'protocol' => 'openai_compatible'],
            'connection' => ['base_url' => 'https://example.com/v1', 'auth' => ['type' => 'bearer']],
            'endpoints' => [
                'models' => ['enabled' => false],
                'text' => ['enabled' => false],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ], JSON_THROW_ON_ERROR));
        (new AiProviderTemplateStore())->persist(1, $parsed);
    }

    public function test_cannot_apply_template_to_another_tenant_connection(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 99,
            'provider' => 'example-ai',
            'name' => 'Other',
            'api_key' => 'x',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
        $parsed = (new \Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateParser())->parse(json_encode([
            'schema_version' => '1.0',
            'provider' => ['key' => 'example-ai', 'name' => 'Example', 'protocol' => 'openai_compatible'],
            'connection' => ['base_url' => 'https://example.com/v1', 'auth' => ['type' => 'bearer']],
            'endpoints' => [
                'models' => ['enabled' => false],
                'text' => ['enabled' => false],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateStore())->applyToConnection(1, $connection, $parsed);
    }
}
