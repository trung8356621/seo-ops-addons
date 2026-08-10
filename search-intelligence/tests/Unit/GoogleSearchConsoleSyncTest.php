<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPropertyMapping;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use App\Models\Site;
use App\Models\SiteMeta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GoogleSearchConsoleSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'services.google_search_console.client_id' => 'gsc-test-client-id',
            'services.google_search_console.client_secret' => 'gsc-test-client-secret',
            'services.google_search_console.redirect' => 'https://seo.teamviahe.com/seo/oauth/google-search-console/callback',
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('mysql');
        $this->ensureTables();
    }

    public function test_sync_site_writes_gsc_query_snapshot_schema(): void
    {
        $site = Site::query()->create([
            'name' => 'Example',
            'domain' => 'example.com',
            'user_id' => 1,
            'status' => 1,
        ]);

        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'valid-access-token',
                'refresh_token' => 'valid-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);

        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $connection->id,
            'site_id' => $site->id,
            'property_url' => 'https://example.com/',
        ]);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fexample.com%2F/searchAnalytics/query' => Http::sequence()
                ->push([
                    'rows' => [
                        [
                            'keys' => ['seo tips'],
                            'clicks' => 12,
                            'impressions' => 240,
                            'ctr' => 0.05,
                            'position' => 8.4,
                        ],
                    ],
                ])
                ->push([
                    'rows' => [
                        ['keys' => ['2026-06-01'], 'clicks' => 3, 'impressions' => 30, 'ctr' => 0.1, 'position' => 8.0],
                    ],
                ])
                ->push([
                    'rows' => [
                        ['keys' => ['2026-05-01'], 'clicks' => 2, 'impressions' => 20, 'ctr' => 0.1, 'position' => 9.0],
                    ],
                ]),
        ]);

        $synced = app(GoogleSearchConsoleSyncService::class)->syncSite((int) $site->id, 1);
        $this->assertTrue($synced);

        $meta = SiteMeta::query()
            ->where('site_id', $site->id)
            ->where('meta_key', 'gsc_query_snapshot')
            ->first();

        $this->assertNotNull($meta);
        $decoded = json_decode((string) $meta->meta_value, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('kpis', $decoded);
        $this->assertArrayHasKey('queries', $decoded);
        $this->assertSame(12, $decoded['kpis']['total_clicks']);
        $this->assertSame(240, $decoded['kpis']['total_impressions']);
        $this->assertSame('gsc_api', $decoded['source']);
        $this->assertArrayHasKey('timeseries', $decoded);
        $this->assertNotEmpty($decoded['timeseries']['current']);
        $this->assertSame('ok', $decoded['chart_status']);
    }

    public function test_sync_site_uses_mapping_connection_not_first_user_connection(): void
    {
        $site = Site::query()->create([
            'name' => 'Mapped',
            'domain' => 'mapped.com',
            'user_id' => 1,
            'status' => 1,
        ]);

        $firstConnection = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'First',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'wrong-token',
                'refresh_token' => 'wrong-refresh',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);

        $mappedConnection = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'Mapped',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'valid-access-token',
                'refresh_token' => 'valid-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);

        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $mappedConnection->id,
            'site_id' => $site->id,
            'property_url' => 'https://mapped.com/',
        ]);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fmapped.com%2F/searchAnalytics/query' => Http::sequence()
                ->push(['rows' => [['keys' => ['mapped keyword'], 'clicks' => 4, 'impressions' => 40, 'ctr' => 0.1, 'position' => 6.5]]])
                ->push(['rows' => []])
                ->push(['rows' => []]),
        ]);

        $result = app(GoogleSearchConsoleSyncService::class)->syncSiteWithDetails((int) $site->id, 1);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['query_count']);
        $this->assertNotSame($firstConnection->id, $mappedConnection->id);
    }

    public function test_sync_site_returns_api_error_when_google_request_fails(): void
    {
        $site = Site::query()->create([
            'name' => 'Example',
            'domain' => 'example.com',
            'user_id' => 1,
            'status' => 1,
        ]);

        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'valid-access-token',
                'refresh_token' => 'valid-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);

        SeoGscPropertyMapping::query()->create([
            'gsc_connection_id' => $connection->id,
            'site_id' => $site->id,
            'property_url' => 'https://example.com/',
        ]);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fexample.com%2F/searchAnalytics/query' => Http::response([
                'error' => ['message' => 'User does not have sufficient permission for site'],
            ], 403),
        ]);

        $result = app(GoogleSearchConsoleSyncService::class)->syncSiteWithDetails((int) $site->id, 1);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower($result['message']));
        $this->assertNull(
            SiteMeta::query()
                ->where('site_id', $site->id)
                ->where('meta_key', 'gsc_query_snapshot')
                ->first(),
        );
    }

    public function test_list_properties_returns_site_urls(): void
    {
        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 1,
            'name' => 'GSC',
            'status' => 'connected',
            'credentials' => [
                'access_token' => 'valid-access-token',
                'refresh_token' => 'valid-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'is_global' => false,
        ]);

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:example.com'],
                    ['siteUrl' => 'https://example.com/'],
                ],
            ]),
        ]);

        $properties = app(GoogleSearchConsoleSyncService::class)->listProperties($connection);
        $this->assertSame(['sc-domain:example.com', 'https://example.com/'], $properties);
    }

    private function ensureTables(): void
    {
        Schema::connection('mysql')->dropIfExists('site_meta');
        Schema::connection('mysql')->dropIfExists('sites');
        Schema::connection('mysql')->dropIfExists('seo_gsc_property_mappings');
        Schema::connection('mysql')->dropIfExists('seo_gsc_master_connections');

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
