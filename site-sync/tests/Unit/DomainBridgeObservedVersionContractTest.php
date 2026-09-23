<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use App\Models\Site;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;
use Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class DomainBridgeObservedVersionContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_bridge_version_prefers_heartbeat_plugin_version(): void
    {
        $site = $this->mockSite([
            'seo_platform' => 'wordpress',
            WordPressHeartbeatPollService::META_KEY => json_encode([
                'status' => 'ok',
                'plugin_version' => '1.0.50',
                'observed_at' => '2026-09-23T05:00:00+00:00',
            ]),
            WordPressSiteInfoService::META_PLUGIN_INFO => json_encode([
                'bridge_version' => '1.0.40',
            ]),
            'seo_wp_plugin_update' => json_encode([
                'plugin_update_supported' => false,
                'installed_version' => '1.0.50',
            ]),
        ]);

        $bridge = DomainListPresentation::bridgeVersion($site);

        self::assertSame('1.0.50', $bridge['line']);
        self::assertNull($bridge['detail']);
        self::assertSame('1.0.50', DomainListPresentation::observedBridgeVersion($site));
        self::assertStringNotContainsString('Unsupported', $bridge['line']);
    }

    public function test_bridge_version_falls_back_to_site_info_when_heartbeat_missing(): void
    {
        $site = $this->mockSite([
            'seo_platform' => 'wordpress',
            WordPressSiteInfoService::META_PLUGIN_INFO => json_encode([
                'bridge_version' => '1.0.50',
            ]),
            'seo_wp_plugin_update' => json_encode([
                'plugin_update_supported' => false,
            ]),
        ]);

        $bridge = DomainListPresentation::bridgeVersion($site);

        self::assertSame('1.0.50', $bridge['line']);
        self::assertNull($bridge['detail']);
    }

    public function test_missing_updater_capability_meta_does_not_render_unsupported(): void
    {
        $site = $this->mockSite([
            'seo_platform' => 'wordpress',
            WordPressHeartbeatPollService::META_KEY => json_encode([
                'status' => 'ok',
                'plugin_version' => '1.0.50',
            ]),
            'seo_wp_plugin_update' => json_encode([
                'plugin_update_supported' => false,
                'last_update_error' => 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel',
            ]),
        ]);

        $bridge = DomainListPresentation::bridgeVersion($site);

        self::assertSame('1.0.50', $bridge['line']);
        self::assertNotSame('Unsupported', $bridge['line']);
        self::assertNull($bridge['detail']);
    }

    public function test_general_domain_drops_laravel_managed_plugin_update_actions(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());

        self::assertStringContainsString('reconcileSiteWordPressState', $src);
        self::assertStringContainsString('WordPressHeartbeatPollService', $src);
        self::assertStringNotContainsString('WordPressPluginUpdateService', $src);
        self::assertStringNotContainsString('checkWpPluginVersion', $src);
        self::assertStringNotContainsString('installWpPlugin', $src);
        self::assertStringNotContainsString('wpPluginPhase', $src);
        self::assertStringNotContainsString('getWpPluginBridgeStatus', $src);
    }

    public function test_connection_card_and_domain_list_show_observed_version_only(): void
    {
        $bridge = LegacyAddonPath::resolve(
            'resources/views/filament/resources/domain-resource/pages/partials/wp-plugin-bridge-status.blade.php',
        );
        $bridgeSrc = (string) file_get_contents($bridge);
        self::assertStringContainsString('DomainListPresentation::bridgeVersion', $bridgeSrc);
        self::assertStringContainsString('Check status', $bridgeSrc);
        self::assertStringNotContainsString('Check version', $bridgeSrc);
        self::assertStringNotContainsString('Update Bridge', $bridgeSrc);
        self::assertStringNotContainsString('Unsupported', $bridgeSrc);
        self::assertStringNotContainsString('Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel', $bridgeSrc);

        $listCol = LegacyAddonPath::resolve(
            'resources/views/filament/tables/columns/domain-bridge-version.blade.php',
        );
        $listSrc = (string) file_get_contents($listCol);
        self::assertStringContainsString('DomainListPresentation::bridgeVersion', $listSrc);

        $presentation = (string) file_get_contents(
            (new ReflectionClass(DomainListPresentation::class))->getFileName(),
        );
        self::assertStringContainsString('observedBridgeVersion', $presentation);
        self::assertStringNotContainsString('WordPressPluginUpdateService', $presentation);
        self::assertStringNotContainsString('Unsupported', $presentation);
        self::assertStringNotContainsString('Update →', $presentation);
        self::assertStringNotContainsString("'Latest'", $presentation);
    }

    public function test_updater_service_and_release_widget_are_removed(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'wordpress';
        self::assertFileDoesNotExist($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'WordPressPluginUpdateService.php');
        self::assertFileDoesNotExist($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'WordPressPluginUpdateClient.php');
        self::assertFileDoesNotExist($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'WordPressPluginDomainsOverviewService.php');
        self::assertFileDoesNotExist($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Filament'.DIRECTORY_SEPARATOR.'Widgets'.DIRECTORY_SEPARATOR.'WpPluginReleaseWidget.php');
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'seo-content-ai-compat'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'filament'.DIRECTORY_SEPARATOR.'widgets'.DIRECTORY_SEPARATOR.'wp-plugin-release.blade.php',
        );
    }

    /**
     * @param  array<string, string|null>  $meta
     */
    private function mockSite(array $meta = []): Site
    {
        $store = $meta;
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('getKey')->andReturn(7);
        $site->shouldReceive('getMeta')->andReturnUsing(
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
        );

        return $site;
    }
}
