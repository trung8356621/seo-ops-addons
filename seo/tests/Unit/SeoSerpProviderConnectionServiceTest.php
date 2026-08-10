<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;
use Omnichannel\Addons\SearchIntelligence\Services\SeoPerformanceDashboardService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoSerpProviderConnectionService;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoSerpProviderConnectionServiceTest extends TestCase
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
        $this->ensureTables();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-serp@test.local',
            'password' => 'secret',
            'role' => 'admin',
        ]);
        $this->actingAs($user);
    }

    public function test_save_encrypts_api_key_and_blank_edit_keeps_key(): void
    {
        $service = app(SeoSerpProviderConnectionService::class);
        $service->saveForUser((int) auth()->id(), SerpProviderKeys::SERPER, [
            'name' => 'Serper',
            'api_key' => 'first-secret',
            'status' => 'inactive',
        ]);

        $connection = $service->resolveForUser((int) auth()->id(), SerpProviderKeys::SERPER);
        $this->assertNotNull($connection);
        $this->assertSame('first-secret', $connection->api_key);

        $service->saveForUser((int) auth()->id(), SerpProviderKeys::SERPER, [
            'name' => 'Serper updated',
            'status' => 'inactive',
        ]);

        $connection->refresh();
        $this->assertSame('first-secret', $connection->api_key);
    }

    public function test_test_connection_marks_active_only_on_success(): void
    {
        Http::fake([
            'https://google.serper.dev/search' => Http::response([
                'organic' => [['title' => 'A', 'link' => 'https://a.com', 'position' => 1]],
            ], 200),
        ]);

        $service = app(SeoSerpProviderConnectionService::class);
        $connection = $service->saveForUser((int) auth()->id(), SerpProviderKeys::SERPER, [
            'name' => 'Serper',
            'api_key' => 'valid-key',
            'status' => 'inactive',
        ]);

        $result = $service->testConnection($connection);
        $connection->refresh();

        $this->assertTrue($result['ok']);
        $this->assertSame('active', $connection->status);
    }

    public function test_tab_sources_only_include_configured_providers(): void
    {
        $service = app(SeoSerpProviderConnectionService::class);
        $service->saveForUser((int) auth()->id(), SerpProviderKeys::SERPAPI, [
            'name' => 'SerpApi',
            'api_key' => 'key',
            'status' => 'inactive',
        ]);

        $tabs = $service->tabSourcesForUser((int) auth()->id());
        $this->assertCount(1, $tabs);
        $this->assertSame(SerpProviderKeys::SERPAPI, $tabs[0]['key']);
    }

    private function ensureTables(): void
    {
        Schema::connection('mysql')->dropIfExists('users');
        Schema::connection('mysql')->dropIfExists('seo_serp_provider_connections');

        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('admin');
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_serp_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 32);
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('status')->default('not_configured');
            $table->string('default_country', 8)->nullable();
            $table->string('default_language', 16)->nullable();
            $table->string('default_location')->nullable();
            $table->string('default_device', 16)->default('desktop');
            $table->unsignedSmallInteger('result_depth')->default(100);
            $table->boolean('is_global')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_rank_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }
}
