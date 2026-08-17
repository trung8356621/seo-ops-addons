<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateParser;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionFormSchema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Tests\TestCase;

final class ProviderConnectionResolverTest extends TestCase
{
    private ProviderConnectionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $templateConnection = (new AiProviderTemplate())->getConnectionName();
        Schema::connection($templateConnection)->dropIfExists('ai_provider_templates');
        Schema::dropIfExists('ai_provider_templates');
        Schema::dropIfExists('api_connections');
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
    }

    public function test_builtin_providers_resolve_canonical_base_urls(): void
    {
        $catalog = (new AiProviderTemplateCatalog())->builtins();
        foreach ([
            ApiConnectionProviders::DEEPSEEK,
            ApiConnectionProviders::OPENROUTER,
            ApiConnectionProviders::GEMINI,
        ] as $key) {
            $connection = new ApiConnection([
                'user_id' => 1,
                'provider' => $key,
                'name' => $key,
                'metadata' => ['base_url' => 'https://api.deepseek.com'],
            ]);
            $resolved = $this->resolver->resolve($connection);
            $this->assertSame($catalog[$key]['connection']['base_url'], $resolved->effectiveBaseUrl);
            $this->assertSame(ProviderConnectionResolver::SOURCE_BUILTIN, $resolved->source);
            $this->assertFalse($resolved->overrideApplied);
        }
    }

    public function test_provider_switch_does_not_leak_previous_url(): void
    {
        $deepseek = $this->resolver->resolveForProvider(1, ApiConnectionProviders::DEEPSEEK, [
            'base_url' => 'https://api.deepseek.com',
        ]);
        $openrouter = $this->resolver->resolveForProvider(1, ApiConnectionProviders::OPENROUTER, [
            'base_url' => 'https://api.deepseek.com',
        ]);
        $gemini = $this->resolver->resolveForProvider(1, ApiConnectionProviders::GEMINI, [
            'base_url' => 'https://api.deepseek.com',
        ]);
        $this->assertSame('https://api.deepseek.com', $deepseek->effectiveBaseUrl);
        $this->assertSame('https://openrouter.ai/api/v1', $openrouter->effectiveBaseUrl);
        $this->assertSame('https://generativelanguage.googleapis.com', $gemini->effectiveBaseUrl);
    }

    public function test_unapproved_override_is_ignored(): void
    {
        $resolved = $this->resolver->resolveForProvider(1, ApiConnectionProviders::OPENROUTER, [
            'override_base_url' => true,
            'base_url_override' => 'https://example-evil.test/v1',
            'base_url' => 'https://example-evil.test/v1',
        ]);
        $this->assertSame('https://openrouter.ai/api/v1', $resolved->effectiveBaseUrl);
        $this->assertFalse($resolved->overrideApplied);

        $clean = $this->resolver->sanitizeSubmittedMetadata(1, ApiConnectionProviders::OPENROUTER, [
            'override_base_url' => true,
            'base_url_override' => 'https://example-evil.test/v1',
            'base_url' => 'https://example-evil.test/v1',
        ]);
        $this->assertArrayNotHasKey('base_url', $clean);
        $this->assertArrayNotHasKey('base_url_override', $clean);
    }

    public function test_imported_template_resolves_without_submitted_base_url(): void
    {
        $parsed = (new AiProviderTemplateParser())->parse(json_encode([
            'package_type' => 'ai_provider_template',
            'schema_version' => '1.0',
            'provider' => ['key' => 'abc-ai', 'name' => 'ABC AI', 'protocol' => 'openai_compatible'],
            'connection' => ['base_url' => 'https://example.com/v1', 'auth' => ['type' => 'bearer']],
            'endpoints' => [
                'models' => ['enabled' => true, 'method' => 'GET', 'path' => '/models', 'response' => ['items_path' => 'data', 'id_path' => 'id', 'name_path' => 'id']],
                'text' => ['enabled' => true, 'method' => 'POST', 'path' => '/chat/completions'],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ], JSON_THROW_ON_ERROR));
        AiProviderTemplate::query()->create([
            'user_id' => 1,
            'provider_key' => 'abc-ai',
            'name' => 'ABC AI',
            'protocol' => 'openai_compatible',
            'schema_version' => '1.0',
            'config' => $parsed->toStorageArray(),
            'is_builtin' => false,
            'enabled' => true,
            'revision' => 1,
        ]);

        $resolved = $this->resolver->resolveForProvider(1, 'abc-ai', []);
        $this->assertSame('https://example.com/v1', $resolved->effectiveBaseUrl);
        $this->assertSame(ProviderConnectionResolver::SOURCE_IMPORTED, $resolved->source);
    }

    public function test_permitted_override_is_validated_and_used(): void
    {
        $parsed = (new AiProviderTemplateParser())->parse(json_encode([
            'package_type' => 'ai_provider_template',
            'schema_version' => '1.0',
            'provider' => ['key' => 'abc-ai', 'name' => 'ABC AI', 'protocol' => 'openai_compatible'],
            'connection' => [
                'base_url' => 'https://example.com/custom',
                'auth' => ['type' => 'bearer'],
                'allow_base_url_override' => true,
            ],
            'endpoints' => [
                'models' => ['enabled' => false],
                'text' => ['enabled' => false],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->assertTrue($parsed->allowBaseUrlOverride);
        AiProviderTemplate::query()->create([
            'user_id' => 1,
            'provider_key' => 'abc-ai',
            'name' => 'ABC AI',
            'protocol' => 'openai_compatible',
            'schema_version' => '1.0',
            'config' => $parsed->toStorageArray(),
            'is_builtin' => false,
            'enabled' => true,
            'revision' => 1,
        ]);

        $resolved = $this->resolver->resolveForProvider(1, 'abc-ai', [
            'override_base_url' => true,
            'base_url_override' => 'https://example.com/v1',
        ]);
        $this->assertSame('https://example.com/v1', $resolved->effectiveBaseUrl);
        $this->assertTrue($resolved->overrideApplied);
    }

    public function test_adapter_and_deepseek_client_use_resolver(): void
    {
        $connection = new ApiConnection([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'metadata' => ['base_url' => 'https://api.deepseek.com'],
        ]);
        $template = (new OpenAiCompatibleProtocolAdapter())->templateForConnection($connection);
        $this->assertSame('https://openrouter.ai/api/v1', $template->baseUrl);

        $deepseek = new ApiConnection([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => 'DeepSeek',
            'metadata' => ['base_url' => 'https://openrouter.ai/api/v1'],
        ]);
        $this->assertSame('https://api.deepseek.com', (new DeepSeekChatClient())->baseUrl($deepseek));
    }

    public function test_form_schema_hides_normal_base_url_and_resets_on_provider_change(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(ApiConnectionFormSchema::class))->getFileName());
        $this->assertStringNotContainsString("TextInput::make('metadata.base_url')", $src);
        $this->assertStringContainsString('afterStateUpdated', $src);
        $this->assertStringContainsString("\$set('metadata.base_url', null)", $src);
        $this->assertStringContainsString('technical_details', $src);
        $this->assertStringContainsString('ai_connection.advanced', $src);
        $this->assertStringContainsString('ProviderConnectionResolver', $src);
        $this->assertStringNotContainsString("'https://api.deepseek.com'", $src);
    }

    public function test_missing_provider_template_is_a_clear_error(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $this->resolver->resolveForProvider(1, 'unknown-vendor');
    }
}
