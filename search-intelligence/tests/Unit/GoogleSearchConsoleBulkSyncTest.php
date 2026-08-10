<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleBulkSyncService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GoogleSearchConsoleBulkSyncTest extends TestCase
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
        $this->ensureTables();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-bulk-gsc@test.local',
            'password' => 'secret',
            'role' => 'admin',
        ]);
        $this->actingAs($user);
    }

    public function test_auto_map_preserves_valid_manual_mapping(): void
    {
        $connection = $this->createConnection();
        $site = Site::query()->create([
            'name' => 'Example',
            'domain' => 'example.com',
            'user_id' => auth()->id(),
            'status' => 1,
        ]);

        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $connection->id,
            'site_id' => $site->id,
            'property_url' => 'https://example.com/',
            'metadata' => ['match_source' => 'manual', 'match_status' => 'manual'],
        ]);

        $connection->metadata = ['properties' => ['https://example.com/', 'sc-domain:example.com']];
        $connection->save();

        $service = app(GoogleSearchConsoleBulkSyncService::class);
        $result = $service->autoMapAccessibleSites($connection, ['https://example.com/', 'sc-domain:example.com'], (int) auth()->id());

        $this->assertSame(1, $result['summary']['already_mapped']);
        $this->assertSame(0, $result['summary']['newly_matched']);

        $mapping = SeoGscPropertyMapping::query()->where('site_id', $site->id)->first();
        $this->assertSame('https://example.com/', $mapping?->property_url);
    }

    public function test_auto_map_skips_ambiguous_and_unmatched(): void
    {
        $connection = $this->createConnection();
        Site::query()->create([
            'name' => 'Ambiguous',
            'domain' => 'ambiguous.com',
            'user_id' => auth()->id(),
            'status' => 1,
        ]);
        Site::query()->create([
            'name' => 'Unmatched',
            'domain' => 'unmatched.com',
            'user_id' => auth()->id(),
            'status' => 1,
        ]);

        $properties = ['https://ambiguous.com/', 'https://ambiguous.com'];

        $service = app(GoogleSearchConsoleBulkSyncService::class);
        $result = $service->autoMapAccessibleSites($connection, $properties, (int) auth()->id());

        $this->assertSame(1, $result['summary']['ambiguous']);
        $this->assertSame(1, $result['summary']['unmatched']);
    }

    public function test_sync_all_uses_per_site_property_and_isolates_failures(): void
    {
        $connection = $this->createConnection();

        $siteA = Site::query()->create(['name' => 'A', 'domain' => 'a.com', 'user_id' => auth()->id(), 'status' => 1]);
        $siteB = Site::query()->create(['name' => 'B', 'domain' => 'b.com', 'user_id' => auth()->id(), 'status' => 1]);

        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $connection->id,
            'site_id' => $siteA->id,
            'property_url' => 'https://a.com/',
        ]);
        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $connection->id,
            'site_id' => $siteB->id,
            'property_url' => 'https://b.com/',
        ]);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fa.com%2F/searchAnalytics/query' => Http::response([
                'rows' => [['keys' => ['alpha'], 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 5]],
            ]),
            'www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fb.com%2F/searchAnalytics/query' => Http::response([
                'error' => ['message' => 'permission denied'],
            ], 403),
        ]);

        $result = app(GoogleSearchConsoleBulkSyncService::class)->syncAllMappedSites(
            (int) auth()->id(),
            $connection->id,
            queueSync: false,
            autoMapFirst: false,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['summary']['synced']);
        $this->assertSame(1, $result['summary']['failed']);

        $metaA = SiteMeta::query()->where('site_id', $siteA->id)->where('meta_key', 'gsc_query_snapshot')->first();
        $metaB = SiteMeta::query()->where('site_id', $siteB->id)->where('meta_key', 'gsc_query_snapshot')->first();
        $this->assertNotNull($metaA);
        $this->assertNull($metaB);
    }

    public function test_empty_gsc_response_is_successful_empty_not_failed(): void
    {
        $connection = $this->createConnection();
        $site = Site::query()->create(['name' => 'Empty', 'domain' => 'empty.com', 'user_id' => auth()->id(), 'status' => 1]);

        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $connection->id,
            'site_id' => $site->id,
            'property_url' => 'https://empty.com/',
        ]);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fempty.com%2F/searchAnalytics/query' => Http::sequence()
                ->push(['rows' => []])
                ->push(['rows' => []])
                ->push(['rows' => []]),
        ]);

        $result = app(GoogleSearchConsoleSyncService::class)->syncSiteWithDetails((int) $site->id, (int) auth()->id());

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['query_count']);
        $this->assertNotNull(
            SiteMeta::query()->where('site_id', $site->id)->where('meta_key', 'gsc_query_snapshot')->first(),
        );
    }

    public function test_auto_map_and_sync_all_maps_unmapped_domain_before_sync(): void
    {
        $connection = $this->createConnection();
        $connection->metadata = ['properties' => ['https://fresh.com/', 'sc-domain:fresh.com']];
        $connection->save();

        $site = Site::query()->create([
            'name' => 'Fresh',
            'domain' => 'fresh.com',
            'user_id' => auth()->id(),
            'status' => 1,
        ]);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://fresh.com/'],
                ],
            ]),
            'www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Ffresh.com%2F/searchAnalytics/query' => Http::response([
                'rows' => [
                    ['keys' => ['fresh keyword'], 'clicks' => 2, 'impressions' => 20, 'ctr' => 0.1, 'position' => 7],
                ],
            ]),
        ]);

        $result = app(GoogleSearchConsoleBulkSyncService::class)->autoMapAndSyncAll((int) auth()->id(), $connection->id, queueSync: false);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['newly_matched']);
        $this->assertSame(1, $result['summary']['synced']);

        $mapping = SeoGscPropertyMapping::query()->where('site_id', $site->id)->first();
        $this->assertNotNull($mapping);
        $this->assertSame('https://fresh.com/', $mapping?->property_url);

        $meta = SiteMeta::query()->where('site_id', $site->id)->where('meta_key', 'gsc_query_snapshot')->first();
        $this->assertNotNull($meta);
    }

    public function test_ensure_site_mapped_auto_maps_current_domain(): void
    {
        $connection = $this->createConnection();
        $connection->metadata = ['properties' => ['https://ensure.com/']];
        $connection->save();

        $site = Site::query()->create([
            'name' => 'Ensure',
            'domain' => 'ensure.com',
            'user_id' => auth()->id(),
            'status' => 1,
        ]);

        $result = app(GoogleSearchConsoleBulkSyncService::class)->ensureSiteMapped((int) $site->id, $connection->id, (int) auth()->id());

        $this->assertTrue($result['ok']);
        $this->assertSame('auto_matched', $result['status']);

        $mapping = SeoGscPropertyMapping::query()->where('site_id', $site->id)->first();
        $this->assertSame('https://ensure.com/', $mapping?->property_url);
    }

    private function createConnection(): SeoGscMasterConnection
    {
        return SeoGscMasterConnection::query()->create([
            'user_id' => auth()->id(),
            'name' => 'GSC',
            'status' => 'connected',
            'oauth_client_id' => 'gsc-test-client-id',
            'oauth_client_secret' => 'gsc-test-client-secret',
            'credentials' => [
                'access_token' => 'valid-access-token',
                'refresh_token' => 'valid-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);
    }

    private function ensureTables(): void
    {
        Schema::connection('mysql')->dropIfExists('site_meta');
        Schema::connection('mysql')->dropIfExists('sites');
        Schema::connection('mysql')->dropIfExists('users');
        Schema::connection('mysql')->dropIfExists('seo_gsc_property_mappings');
        Schema::connection('mysql')->dropIfExists('seo_gsc_master_connections');

        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('admin');
            $table->timestamps();
        });

        Schema::connection('mysql')->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('domain')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('status')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

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
