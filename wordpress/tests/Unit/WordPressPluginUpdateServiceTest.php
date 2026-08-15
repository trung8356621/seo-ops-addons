<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\WordPressPluginUpdateClient;
use Omnichannel\Addons\WordPress\Services\WordPressPluginUpdateService;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class WordPressPluginUpdateServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_manual_check_persists_latest_version(): void
    {
        Http::fake([
            'https://baloquatang.net/wp-json/omi-seo-ai/v1/plugin-update/check*' => Http::response([
                'ok' => true,
                'installed_version' => '1.0.74',
                'latest_version' => '1.0.75',
                'update_available' => true,
                'checked_at' => '2026-08-14T03:00:00+00:00',
            ], 200),
        ]);

        $site = $this->mockSite();
        $service = new WordPressPluginUpdateService(new WordPressPluginUpdateClient());
        $result = $service->check($site);

        $this->assertTrue($result['ok']);
        $this->assertSame('1.0.74', $result['status']['installed_version']);
        $this->assertSame('1.0.75', $result['status']['latest_version']);
        $this->assertTrue($result['status']['update_available']);
        $this->assertTrue($result['status']['can_update']);
    }

    public function test_update_verifies_heartbeat_before_completed(): void
    {
        Http::fake([
            'https://baloquatang.net/wp-json/omi-seo-ai/v1/plugin-update/install' => Http::response([
                'ok' => true,
                'previous_version' => '1.0.74',
                'installed_version' => '1.0.75',
                'updated' => true,
            ], 200),
            'https://baloquatang.net/wp-json/omi-seo-ai/v1/heartbeat' => Http::response([
                'success' => true,
                'status' => 'ok',
                'plugin_version' => '1.0.75',
                'capabilities' => ['plugin_update' => true],
                'plugin_update_source' => 'github_release',
            ], 200),
        ]);

        $site = $this->mockSite([
            WordPressPluginUpdateService::META_KEY => json_encode([
                'installed_version' => '1.0.74',
                'latest_version' => '1.0.75',
                'update_available' => true,
                'plugin_update_supported' => true,
            ]),
        ]);

        $service = new WordPressPluginUpdateService(new WordPressPluginUpdateClient());
        $result = $service->update($site);

        $this->assertTrue($result['ok']);
        $this->assertSame('Đã cập nhật thành công', $result['message']);
        $this->assertSame('1.0.75', $result['status']['installed_version']);
        $this->assertFalse($result['status']['update_available']);
    }

    public function test_timeout_reconciles_success_from_heartbeat(): void
    {
        Http::fake([
            'https://baloquatang.net/wp-json/omi-seo-ai/v1/plugin-update/install' => function () {
                throw new ConnectionException('timeout');
            },
            'https://baloquatang.net/wp-json/omi-seo-ai/v1/heartbeat' => Http::response([
                'success' => true,
                'status' => 'ok',
                'plugin_version' => '1.0.75',
                'capabilities' => ['plugin_update' => true],
            ], 200),
        ]);

        $site = $this->mockSite([
            WordPressPluginUpdateService::META_KEY => json_encode([
                'installed_version' => '1.0.74',
                'latest_version' => '1.0.75',
                'update_available' => true,
                'plugin_update_supported' => true,
            ]),
        ]);

        $service = new WordPressPluginUpdateService(new WordPressPluginUpdateClient());
        $result = $service->update($site);

        $this->assertTrue($result['ok']);
        $this->assertSame('1.0.75', $result['status']['installed_version']);
        $this->assertSame('reconciled', $result['status']['last_update_status']);
    }

    public function test_old_plugin_without_capability_disables_update(): void
    {
        Http::fake([
            'https://baloquatang.net/wp-json/omi-seo-ai/v1/plugin-update/check*' => Http::response(['code' => 'rest_no_route'], 404),
        ]);

        $site = $this->mockSite();
        $service = new WordPressPluginUpdateService(new WordPressPluginUpdateClient());
        $result = $service->check($site);

        $this->assertFalse($result['ok']);
        $this->assertSame('capability_missing', $result['code']);
        $this->assertTrue($result['status']['unsupported']);
        $this->assertFalse($result['status']['can_update']);
        $this->assertSame('Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel', $result['message']);
    }

    public function test_offline_wordpress_returns_friendly_error(): void
    {
        Http::fake([
            'https://baloquatang.net/wp-json/omi-seo-ai/v1/plugin-update/check*' => function () {
                throw new ConnectionException('offline');
            },
        ]);

        $site = $this->mockSite();
        $service = new WordPressPluginUpdateService(new WordPressPluginUpdateClient());
        $result = $service->check($site);

        $this->assertFalse($result['ok']);
        $this->assertSame('Không thể kết nối website.', $result['message']);
    }

    /**
     * @param  array<string, string|null>  $meta
     */
    private function mockSite(array $meta = []): Site
    {
        $store = array_merge([
            'seo_read_token' => 'read-token',
            'seo_migration_token' => 'write-token',
        ], $meta);

        $site = Mockery::mock(Site::class);
        $site->shouldReceive('getKey')->andReturn(42);
        $site->shouldReceive('__get')->with('domain')->andReturn('https://baloquatang.net');
        $site->shouldReceive('getAttribute')->with('domain')->andReturn('https://baloquatang.net');
        $site->shouldReceive('loadMissing')->andReturnSelf();
        $site->shouldReceive('load')->andReturnSelf();
        $site->shouldReceive('unsetRelation')->andReturnNull();
        $site->shouldReceive('getMeta')->andReturnUsing(
            function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
        );
        $metas = Mockery::mock();
        $metas->shouldReceive('updateOrCreate')->andReturnUsing(
            function (array $keys, array $values) use (&$store) {
                $store[$keys['meta_key']] = $values['meta_value'];

                return true;
            },
        );
        $site->shouldReceive('metas')->andReturn($metas);

        return $site;
    }
}
