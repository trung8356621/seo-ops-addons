<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\SeoDatabaseConnection;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

final class SeoSiteServiceConnectionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_manual_mode_uses_existing_seo_database_connection(): void
    {
        Config::set('database.core_connection', 'sqlite');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'mayhopphat.com',
            'status' => 'active',
        ]);

        $existing = SeoDatabaseConnection::query()->create([
            'name' => 'Hosting manual',
            'type' => 'manual',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $existing->users()->sync([$owner->id]);

        $service = Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'is_active' => true,
        ]);

        $siteService = SiteService::query()->create([
            'site_id' => $site->id,
            'service_id' => $service->id,
            'status' => 'active',
            'settings' => [
                'db_config_type' => 'manual',
            ],
        ]);

        $connection = app(SeoDatabaseConnectionService::class)->syncConnectionFromSiteService($siteService);

        $this->assertSame($existing->id, $connection->id);
        $this->assertSame('omi_seo_ai', $connection->database);
    }

    public function test_sync_manual_mode_fails_without_seo_database_connection(): void
    {
        Config::set('database.core_connection', 'sqlite');

        $owner = User::query()->create([
            'name' => 'Owner 2',
            'email' => 'owner2@example.test',
            'password' => bcrypt('password'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'example.com',
            'status' => 'active',
        ]);

        $service = Service::query()->create([
            'name' => 'SEO Content AI',
            'slug' => 'seo-content-ai',
            'addon_namespace' => 'App\\Addons\\SeoContentAi\\SeoContentAiServiceProvider',
            'is_active' => true,
        ]);

        $siteService = SiteService::query()->create([
            'site_id' => $site->id,
            'service_id' => $service->id,
            'status' => 'active',
            'settings' => ['db_config_type' => 'manual'],
        ]);

        $this->expectException(RuntimeException::class);
        app(SeoDatabaseConnectionService::class)->syncConnectionFromSiteService($siteService);
    }

    public function test_map_site_service_settings_to_connection_record_auto_mode(): void
    {
        $service = app(SeoDatabaseConnectionService::class);
        $record = $service->mapSiteServiceSettingsToConnectionRecord(
            ['db_config_type' => 'auto'],
            5,
            'example.com',
        );

        $this->assertSame('auto', $record['type']);
        $this->assertSame('omi_seo_ai', $record['database']);
        $this->assertSame('SEO DB — example.com', $record['name']);
    }
}
