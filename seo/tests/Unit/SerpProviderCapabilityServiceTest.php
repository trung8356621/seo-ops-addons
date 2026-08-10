<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordSearchVolumeService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpProviderCapabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SerpProviderCapabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('mysql');
        Schema::connection('mysql')->create('seo_dataforseo_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('login')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('not_configured');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('seo_serp_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 32);
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('status')->default('not_configured');
            $table->boolean('is_global')->default(false);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('seo_extended_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 32);
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('status')->default('not_configured');
            $table->boolean('is_global')->default(false);
            $table->timestamps();
        });
    }

    public function test_serper_allintitle_not_supported(): void
    {
        $service = app(SerpProviderCapabilityService::class);
        $volume = app(KeywordSearchVolumeService::class);

        $caps = $service->resolveForUser(1, 'serper', $volume);

        $this->assertTrue($caps['rank']);
        $this->assertFalse($caps['allintitle']);
        $this->assertFalse($caps['search_volume_configured']);
    }

    public function test_filter_dispatchable_metrics_skips_unsupported(): void
    {
        \Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection::query()->create([
            'user_id' => 1,
            'provider' => 'serper',
            'name' => 'Serper',
            'api_key' => 'test-key',
            'status' => 'active',
        ]);

        $service = app(SerpProviderCapabilityService::class);
        $volume = app(KeywordSearchVolumeService::class);

        $metrics = $service->filterDispatchableMetrics(1, 'serper', ['rank', 'allintitle', 'search_volume'], $volume);

        $this->assertSame(['rank'], $metrics);
    }
}
