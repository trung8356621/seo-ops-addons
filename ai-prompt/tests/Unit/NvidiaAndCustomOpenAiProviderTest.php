<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Services\AiProviderModelCatalogGateway;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderConnectionTester;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderCustomFormFactory;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver;
use Omnichannel\Addons\AiPrompt\Services\SeoApiConnectionProviderCatalog;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionFormSchema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode;
use Tests\TestCase;

final class NvidiaAndCustomOpenAiProviderTest extends TestCase
{
    private ProviderConnectionResolver $resolver;

    private AiProviderCustomFormFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $templateConnection = (new AiProviderTemplate())->getConnectionName();
        Schema::connection($templateConnection)->dropIfExists('ai_provider_templates');
        Schema::dropIfExists('ai_provider_templates');
        Schema::dropIfExists('api_connections');
        Schema::dropIfExists('seo_ai_models');
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
            $table->string('raw_model_name');
            $table->string('display_name')->nullable();
            $table->string('category')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('status')->default('active');
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
        $createTemplates = function (Blueprint $table): void {
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
        };
        if (! Schema::connection($templateConnection)->hasTable('ai_provider_templates')) {
            Schema::connection($templateConnection)->create('ai_provider_templates', $createTemplates);
        }
        $this->resolver = new ProviderConnectionResolver();
        $this->factory = new AiProviderCustomFormFactory();
    }

    public function test_nvidia_is_builtin_and_in_dropdown_catalog(): void
    {
        $builtins = (new AiProviderTemplateCatalog())->builtins();
        $this->assertArrayHasKey(ApiConnectionProviders::NVIDIA, $builtins);
        $this->assertSame('https://integrate.api.nvidia.com/v1', $builtins['nvidia']['connection']['base_url']);
        $this->assertSame('NV', $builtins['nvidia']['provider']['short_code']);
        $this->assertSame('openai_compatible', $builtins['nvidia']['provider']['protocol']);
        $this->assertSame('NV', AiConnectionShortCode::builtin(ApiConnectionProviders::NVIDIA));

        $catalog = app(SeoApiConnectionProviderCatalog::class);
        $this->assertTrue($catalog->has(ApiConnectionProviders::NVIDIA));
        $this->assertTrue($catalog->has(ApiConnectionProviders::CUSTOM_OPENAI));
        $grouped = $catalog->groupedProviderOptions();
        $ai = $grouped[__('seo-content-ai::filament.api_connections.group_ai_providers')] ?? [];
        $this->assertArrayHasKey(ApiConnectionProviders::NVIDIA, $ai);
        $this->assertArrayHasKey(ApiConnectionProviders::CUSTOM_OPENAI, $ai);
        $this->assertArrayHasKey(ApiConnectionProviders::OPENROUTER, $ai);
        $this->assertArrayHasKey(ApiConnectionProviders::DEEPSEEK, $ai);
    }

    public function test_nvidia_connection_resolves_and_allows_template_backed_text(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 7,
            'provider' => ApiConnectionProviders::NVIDIA,
            'name' => 'NVIDIA NIM',
            'api_key' => 'nvapi-test',
            'status' => 'active',
            'metadata' => [],
        ]);
        $resolved = $this->resolver->resolve($connection);
        $this->assertSame('https://integrate.api.nvidia.com/v1', $resolved->effectiveBaseUrl);
        $this->assertTrue(ApiConnectionProviders::allowsTemplateBackedText('nvidia', 7));
        $this->assertTrue((new AiProviderModelCatalogGateway())->supportsDiscovery($connection));
    }

    public function test_custom_form_creates_distinct_providers_and_rejects_duplicates(): void
    {
        $groq = $this->factory->buildFromForm([
            'provider_name' => 'GroqCloud',
            'provider_key' => 'groqcloud',
            'short_code' => 'GQ',
            'base_url' => 'https://api.groq.com/openai/v1',
            'auth_type' => 'bearer',
            'models_path' => '/models',
            'text_path' => '/chat/completions',
            'model_discovery' => true,
        ]);
        $this->assertSame('groqcloud', $groq->providerKey);
        $this->assertSame('openai_compatible', $groq->protocol->value);

        $this->persistTemplate(7, $groq);

        $together = $this->factory->buildFromForm([
            'provider_name' => 'Together AI',
            'provider_key' => 'together-ai',
            'short_code' => 'TG',
            'base_url' => 'https://api.together.xyz/v1',
            'auth_type' => 'bearer',
            'models_path' => '/models',
            'text_path' => '/chat/completions',
            'model_discovery' => false,
        ]);
        $this->persistTemplate(7, $together);

        $this->assertTrue($this->resolver->hasTemplate(7, 'groqcloud'));
        $this->assertTrue($this->resolver->hasTemplate(7, 'together-ai'));
        $this->assertNotSame($groq->providerKey, $together->providerKey);

        $this->expectException(AiProviderTemplateException::class);
        $this->factory->assertProviderKeyAvailable(7, 'groqcloud');
    }

    public function test_custom_rejects_reserved_and_builtin_keys(): void
    {
        foreach (['nvidia', 'openrouter', 'deepseek', 'custom_openai', 'gemini'] as $key) {
            try {
                $this->factory->assertProviderKeyAvailable(7, $key);
                $this->fail('Expected rejection for '.$key);
            } catch (AiProviderTemplateException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_custom_rejects_ssrf_and_http_base_url(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $this->factory->buildFromForm([
            'provider_name' => 'Evil',
            'provider_key' => 'evil-local',
            'short_code' => 'EV',
            'base_url' => 'http://127.0.0.1/v1',
            'auth_type' => 'bearer',
            'models_path' => '/models',
            'text_path' => '/chat/completions',
            'model_discovery' => true,
        ]);
    }

    public function test_edit_keeps_provider_identity_when_display_name_changes(): void
    {
        $template = $this->factory->buildFromForm([
            'provider_name' => 'GroqCloud',
            'provider_key' => 'groqcloud',
            'short_code' => 'GQ',
            'base_url' => 'https://api.groq.com/openai/v1',
            'auth_type' => 'bearer',
            'models_path' => '/models',
            'text_path' => '/chat/completions',
            'model_discovery' => true,
        ]);
        $this->persistTemplate(7, $template);

        $renamed = $this->factory->buildFromForm([
            'provider_name' => 'Groq Production',
            'provider_key' => 'groqcloud',
            'short_code' => 'GQ',
            'base_url' => 'https://api.groq.com/openai/v1',
            'auth_type' => 'bearer',
            'models_path' => '/models',
            'text_path' => '/chat/completions',
            'model_discovery' => true,
        ]);
        $this->assertSame('groqcloud', $renamed->providerKey);
        $this->assertSame('Groq Production', $renamed->providerName);
        $this->persistTemplate(7, $renamed);

        $resolved = $this->resolver->resolveForProvider(7, 'groqcloud');
        $this->assertSame('groqcloud', $resolved->template->providerKey);
        $this->assertSame('Groq Production', $resolved->template->providerName);
    }

    public function test_connection_tester_does_not_claim_generation_success(): void
    {
        $connection = new ApiConnection([
            'user_id' => 7,
            'provider' => ApiConnectionProviders::NVIDIA,
            'name' => 'NVIDIA NIM',
            'api_key' => 'nvapi-test',
            'metadata' => [],
        ]);
        $stages = (new AiProviderConnectionTester())->test($connection);
        $this->assertArrayHasKey('text', $stages);
        $this->assertStringContainsString('inference not verified', strtolower($stages['text']['detail']));
        $this->assertStringNotContainsString('generation not run', strtolower($stages['text']['detail']));
    }

    public function test_test_generation_reports_http_failure_without_claiming_success(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);
        $connection = ApiConnection::query()->create([
            'user_id' => 7,
            'provider' => ApiConnectionProviders::NVIDIA,
            'name' => 'NVIDIA NIM',
            'api_key' => 'nvapi-bad',
            'status' => 'active',
            'metadata' => [],
        ]);
        $result = (new AiProviderConnectionTester())->testGeneration($connection, 'meta/llama-3.1-8b-instruct', 'ping');
        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http_status']);
        $this->assertStringNotContainsString('nvapi-bad', $result['detail']);
    }

    public function test_form_schema_mentions_nvidia_and_custom_fields(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(ApiConnectionFormSchema::class))->getFileName());
        $this->assertStringContainsString('CUSTOM_OPENAI', $src);
        $this->assertStringContainsString('custom_provider.provider_key', $src);
        $this->assertStringContainsString('NVIDIA', $src);
        $this->assertStringContainsString('custom_provider.base_url', $src);
    }

    public function test_builtin_providers_unchanged_base_urls(): void
    {
        foreach ([
            ApiConnectionProviders::DEEPSEEK => 'https://api.deepseek.com',
            ApiConnectionProviders::OPENROUTER => 'https://openrouter.ai/api/v1',
            ApiConnectionProviders::GEMINI => 'https://generativelanguage.googleapis.com',
            ApiConnectionProviders::NVIDIA => 'https://integrate.api.nvidia.com/v1',
        ] as $key => $url) {
            $resolved = $this->resolver->resolveForProvider(1, $key);
            $this->assertSame($url, $resolved->effectiveBaseUrl);
        }
    }

    private function persistTemplate(int $userId, \Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate $template): void
    {
        AiProviderTemplate::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'provider_key' => $template->providerKey,
            ],
            [
                'name' => $template->providerName,
                'protocol' => $template->protocol->value,
                'schema_version' => $template->schemaVersion,
                'config' => $template->toStorageArray(),
                'is_builtin' => false,
                'enabled' => true,
                'revision' => 1,
                'updated_by' => $userId,
            ],
        );
    }
}
