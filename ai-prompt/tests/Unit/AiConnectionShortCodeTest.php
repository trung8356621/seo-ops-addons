<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiExecutionTargetPresenter;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateParser;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Tests\TestCase;

final class AiConnectionShortCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_builtin_codes(): void
    {
        $this->assertSame('GG', AiConnectionShortCode::builtin(ApiConnectionProviders::GEMINI));
        $this->assertSame('DS', AiConnectionShortCode::builtin(ApiConnectionProviders::DEEPSEEK));
        $this->assertSame('OR', AiConnectionShortCode::builtin(ApiConnectionProviders::OPENROUTER));
    }

    public function test_generated_code_is_deterministic(): void
    {
        $this->assertSame('EA', AiConnectionShortCode::generate('Example AI'));
        $this->assertSame('EA', AiConnectionShortCode::generate('Example AI'));
        $this->assertSame('QW', AiConnectionShortCode::generate('qwen'));
        $this->assertSame(AiConnectionShortCode::generate('Example AI'), AiConnectionShortCode::generate('Example AI'));
    }

    public function test_imported_template_short_code(): void
    {
        $json = json_encode([
            'schema_version' => '1.0',
            'provider' => [
                'key' => 'example-ai',
                'name' => 'Example AI',
                'short_code' => 'EA',
                'protocol' => 'openai_compatible',
            ],
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
            ],
            'endpoints' => [
                'models' => [
                    'enabled' => true,
                    'method' => 'GET',
                    'path' => '/models',
                    'response' => ['items_path' => 'data', 'id_path' => 'id', 'name_path' => 'id'],
                ],
                'text' => ['enabled' => true, 'method' => 'POST', 'path' => '/chat/completions'],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ], JSON_THROW_ON_ERROR);
        $parsed = (new AiProviderTemplateParser())->parse($json);
        $this->assertSame('EA', $parsed->shortCode);
        $this->assertSame('EA', $parsed->toStorageArray()['provider']['short_code']);
    }

    public function test_builtin_catalog_includes_short_codes(): void
    {
        $builtins = (new AiProviderTemplateCatalog())->builtins();
        $this->assertSame('GG', $builtins['gemini']['provider']['short_code']);
        $this->assertSame('DS', $builtins['deepseek']['provider']['short_code']);
        $this->assertSame('OR', $builtins['openrouter']['provider']['short_code']);
        $doc = json_decode((new AiProviderTemplateCatalog())->downloadableDocument(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('_short_code_help', $doc);
        $this->assertSame('EA', $doc['provider']['short_code']);
    }

    public function test_collision_assigns_distinct_codes(): void
    {
        $a = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter A',
            'api_key' => 'a',
            'status' => 'active',
        ]);
        $b = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter B',
            'api_key' => 'b',
            'status' => 'active',
        ]);
        $presenter = new AiConnectionPresenter();
        $codes = $presenter->codesForUser(1);
        $this->assertNotSame($codes[(int) $a->id], $codes[(int) $b->id]);
        $this->assertContains('OR', $codes);
        $this->assertTrue(str_starts_with($codes[(int) $a->id], 'OR') || str_starts_with($codes[(int) $b->id], 'OR'));
    }

    public function test_badge_variant_stable_per_connection_and_label_shared(): void
    {
        $gemini = ApiConnection::query()->create([
            'user_id' => 2,
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'Gemini',
            'api_key' => 'g',
            'status' => 'active',
        ]);
        $openrouter = ApiConnection::query()->create([
            'user_id' => 2,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'o',
            'status' => 'active',
        ]);
        $connections = new AiConnectionPresenter();
        $targets = new AiExecutionTargetPresenter($connections);
        $g1 = $targets->presentNamed($gemini, 'Gemini Pro', 2);
        $g2 = $targets->presentNamed($gemini, 'Gemini Flash', 2);
        $o1 = $targets->presentNamed($openrouter, 'Gemini Pro', 2);
        $this->assertSame('GG', $g1['short_code']);
        $this->assertSame('OR', $o1['short_code']);
        $this->assertSame('[GG] Gemini Pro', $g1['full_label']);
        $this->assertSame('[OR] Gemini Pro', $o1['full_label']);
        $this->assertSame($g1['badge_variant'], $g2['badge_variant']);
        $this->assertNotSame($g1['badge_variant'], $o1['badge_variant']);
        $this->assertSame($g1['badge_variant'], $connections->badgeVariant($gemini));
        $this->assertDoesNotMatchRegularExpression('/Math\.random|random\(/', (string) file_get_contents(
            (new \ReflectionClass(AiConnectionPresenter::class))->getFileName()
        ));
    }

    public function test_display_code_override_does_not_change_identity_keys(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 3,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'o',
            'status' => 'active',
            'metadata' => ['display_code' => 'ora'],
        ]);
        $beforeId = (int) $connection->id;
        $label = (new AiExecutionTargetPresenter())->presentNamed($connection, 'Gemini Pro', 3);
        $this->assertSame('ORA', $label['short_code']);
        $this->assertSame('[ORA] Gemini Pro', $label['full_label']);
        $this->assertSame($beforeId, (int) $connection->id);
        $this->assertSame(ApiConnectionProviders::OPENROUTER, (string) $connection->provider);
    }
}
