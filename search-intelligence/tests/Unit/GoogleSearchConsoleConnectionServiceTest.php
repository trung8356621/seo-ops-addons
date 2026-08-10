<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GoogleSearchConsoleConnectionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('mysql');
        $this->ensureGscTables();
    }

    public function test_resolve_effective_status_variants(): void
    {
        $service = app(GoogleSearchConsoleConnectionService::class);

        $empty = new SeoGscMasterConnection(['status' => 'not_configured']);
        $this->assertSame('not_configured', $service->resolveEffectiveStatus($empty));

        $oauthOnly = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
            'status' => 'connected',
            'is_global' => false,
        ]);
        $this->assertSame('not_configured', $service->resolveEffectiveStatus($oauthOnly));

        $expired = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC expired',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
            'account_email' => 'owner@example.com',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'old-access',
                'refresh_token' => 'refresh-token',
                'expires_at' => now()->subHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);
        $this->assertSame('token_expired', $service->resolveEffectiveStatus($expired));

        $connected = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC ok',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
            'account_email' => 'owner@example.com',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'fresh-access',
                'refresh_token' => 'refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);
        $this->assertSame('connected', $service->resolveEffectiveStatus($connected));
    }

    public function test_map_site_property_rejects_unknown_property(): void
    {
        $service = app(GoogleSearchConsoleConnectionService::class);
        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC',
            'status' => 'connected',
            'metadata' => ['properties' => ['https://example.com/']],
            'is_global' => false,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $service->mapSiteProperty($connection, 99, 'https://unknown.example/');
    }

    public function test_resolve_for_site_uses_property_mapping_connection(): void
    {
        $service = app(GoogleSearchConsoleConnectionService::class);

        $first = SeoGscMasterConnection::query()->create([
            'user_id' => 10,
            'name' => 'First',
            'status' => 'connected',
            'is_global' => false,
        ]);
        $second = SeoGscMasterConnection::query()->create([
            'user_id' => 10,
            'name' => 'Second',
            'status' => 'connected',
            'is_global' => false,
        ]);

        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $second->id,
            'site_id' => 77,
            'property_url' => 'https://mapped.example/',
        ]);

        $resolved = $service->resolveForSite(77, 10);
        $this->assertInstanceOf(SeoGscMasterConnection::class, $resolved);
        $this->assertSame($second->id, $resolved->id);
        $this->assertNotSame($first->id, $resolved->id);
    }

    public function test_property_options_only_include_synced_properties(): void
    {
        $service = app(GoogleSearchConsoleConnectionService::class);
        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC',
            'metadata' => ['properties' => ['sc-domain:example.com']],
            'is_global' => false,
        ]);

        $options = $service->propertyOptionsForForm($connection);
        $this->assertSame(['sc-domain:example.com' => 'sc-domain:example.com'], $options);
    }

    public function test_read_paths_fall_back_when_gsc_tables_are_missing(): void
    {
        Schema::connection('mysql')->dropIfExists('seo_gsc_property_mappings');
        Schema::connection('mysql')->dropIfExists('seo_gsc_master_connections');

        $service = app(GoogleSearchConsoleConnectionService::class);

        $this->assertNull($service->resolveForUser(1));
        $this->assertNull($service->resolveForSite(77, 1));
        $this->assertNull($service->resolveByIdForUser(1, 123));
        $this->assertSame([], $service->mappingRowsForUser(1));

        $status = $service->statusForSite(77);

        $this->assertSame('not_configured', $status['status']);
        $this->assertFalse($status['configured']);
        $this->assertFalse($status['has_snapshot']);
    }

    private function ensureGscTables(): void
    {
        Schema::connection('mysql')->dropIfExists('seo_gsc_property_mappings');
        Schema::connection('mysql')->dropIfExists('seo_gsc_master_connections');

        Schema::connection('mysql')->create('seo_gsc_master_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default('not_configured');
            $table->text('credentials')->nullable();
            $table->string('account_email')->nullable();
            $table->string('oauth_client_id')->nullable();
            $table->text('oauth_client_secret')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_gsc_property_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gsc_connection_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('property_url');
            $table->string('property_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['gsc_connection_id', 'site_id']);
        });
    }
}
