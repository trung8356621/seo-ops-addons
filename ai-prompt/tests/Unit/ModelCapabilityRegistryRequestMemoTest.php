<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver;
use Omnichannel\Addons\AiPrompt\Support\AiCapabilitySource;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Tests\TestCase;

final class ModelCapabilityRegistryRequestMemoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelCapabilityRegistry::flushRequestMemo();
        ModelContextCapabilityResolver::flushRequestMemo();
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        ModelCapabilityRegistry::flushRequestMemo();
        ModelContextCapabilityResolver::flushRequestMemo();
        parent::tearDown();
    }

    public function test_identical_capabilities_for_hits_db_once_per_model(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'name' => 'memo-conn',
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'status' => 'active',
            'is_global' => true,
        ]);

        SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'deepseek-chat',
            'display_name' => 'DeepSeek Chat',
            'status' => SeoAiModel::STATUS_ACTIVE,
            'priority' => 100,
            'capabilities' => [],
        ]);

        AiModelCapabilityRow::query()->create([
            'source' => AiCapabilitySource::Manual->value,
            'model_key' => 'deepseek-chat',
            'api_connection_id' => $connection->id,
            'capability' => AiModelCapability::ToolCall->value,
            'enabled' => true,
        ]);

        $queries = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'seo_ai_models') || str_contains($sql, 'ai_model_capabilities')) {
                $queries[] = $sql;
            }
        });

        $a = new ModelCapabilityRegistry();
        $b = new ModelCapabilityRegistry();
        $first = $a->capabilitiesFor($connection, 'deepseek-chat');
        $second = $b->capabilitiesFor($connection, 'deepseek-chat');
        $third = $a->capabilitiesFor($connection, 'deepseek-chat');

        self::assertSame($first, $second);
        self::assertSame($first, $third);
        self::assertContains(AiModelCapability::ToolCall->value, $first);

        $beforeRepeat = count($queries);
        self::assertGreaterThan(0, $beforeRepeat);

        for ($i = 0; $i < 10; $i++) {
            (new ModelCapabilityRegistry())->capabilitiesFor($connection, 'deepseek-chat');
        }

        self::assertSame(
            $beforeRepeat,
            count($queries),
            'Repeated identical capability lookups must not issue more AI model/capability SQL.',
        );
    }

    public function test_context_resolver_reuses_identical_resolve_for(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'name' => 'memo-ctx',
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'status' => 'active',
            'is_global' => true,
        ]);

        $model = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'raw_model_name' => 'deepseek-v4-pro',
            'display_name' => 'V4 Pro',
            'status' => SeoAiModel::STATUS_ACTIVE,
            'priority' => 10,
            'capabilities' => [
                'provider_metadata' => ['context_length' => 64000],
                'max_output_tokens' => 4096,
            ],
        ]);

        $queries = 0;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            if (str_contains(strtolower($event->sql), 'seo_ai_models')) {
                $queries++;
            }
        });

        $resolver = new ModelContextCapabilityResolver(new ModelCapabilityRegistry());
        $one = $resolver->resolveFor($connection, 'deepseek-v4-pro', (int) $model->id, [
            AiModelCapability::TextGenerate->value,
        ]);
        $two = $resolver->resolveFor($connection, 'deepseek-v4-pro', (int) $model->id, [
            AiModelCapability::TextGenerate->value,
        ]);

        self::assertSame($one, $two);
        self::assertSame(64000, $one->contextWindow);
        self::assertSame(1, $queries);
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('api_connections')) {
            Schema::create('api_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name');
                $table->string('provider');
                $table->string('status')->default('active');
                $table->boolean('is_global')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('seo_ai_models')) {
            Schema::create('seo_ai_models', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('api_connection_id');
                $table->string('raw_model_name');
                $table->string('display_name')->nullable();
                $table->string('status')->default('active');
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_hidden')->default(false);
                $table->json('capabilities')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_model_capabilities')) {
            Schema::create('ai_model_capabilities', function (Blueprint $table): void {
                $table->id();
                $table->string('source');
                $table->string('model_key');
                $table->unsignedBigInteger('api_connection_id')->nullable();
                $table->unsignedBigInteger('seo_ai_model_id')->nullable();
                $table->string('capability');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }
    }
}
