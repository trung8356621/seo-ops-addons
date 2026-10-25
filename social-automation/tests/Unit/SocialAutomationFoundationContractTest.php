<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase A foundation contracts — discovery, namespace, entitlement posture, ownership.
 */
final class SocialAutomationFoundationContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_addon_manifest_slug_and_providers(): void
    {
        $path = $this->root.'/addon.json';
        self::assertFileExists($path);

        $meta = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($meta);
        self::assertSame('social-automation', $meta['slug'] ?? null);
        self::assertSame('omi_social_automation', $meta['db_connection'] ?? null);
        self::assertSame(
            'Omnichannel\\Addons\\SocialAutomation\\SocialAutomationServiceProvider',
            $meta['provider'] ?? null
        );
        self::assertSame(
            'Omnichannel\\Addons\\SocialAutomation\\Providers\\SocialAutomationPanelProvider',
            $meta['panel_provider'] ?? null
        );
        self::assertTrue((bool) ($meta['register_early'] ?? false));
    }

    public function test_composer_autoload_maps_namespace(): void
    {
        $composer = json_decode((string) file_get_contents(dirname($this->root).'/composer.json'), true);
        self::assertIsArray($composer);
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        self::assertSame(
            'social-automation/src/',
            $psr4['Omnichannel\\Addons\\SocialAutomation\\'] ?? null
        );
        $dev = $composer['autoload-dev']['psr-4'] ?? [];
        self::assertSame(
            'social-automation/tests/',
            $dev['Omnichannel\\Addons\\SocialAutomation\\Tests\\'] ?? null
        );
    }

    public function test_resolver_does_not_auto_activate_service(): void
    {
        $source = (string) file_get_contents($this->root.'/src/Support/SocialAutomationServiceResolver.php');

        self::assertStringContainsString("'is_active' => false", $source);
        self::assertStringContainsString('return false;', $source);
        self::assertStringNotContainsString("'is_active' => true", $source);
        self::assertStringNotContainsString('ServiceEntitlementGate', $source);
    }

    public function test_access_gate_has_no_site_id_dependency(): void
    {
        $source = (string) file_get_contents($this->root.'/src/Support/SocialAutomationAccess.php');

        self::assertStringNotContainsString('site_id', $source);
        self::assertStringNotContainsString('SiteAccess', $source);
        self::assertStringContainsString('isActive()', $source);
        self::assertStringContainsString('RpaAdminRoleGate', $source);
    }

    public function test_provider_contains_addon_boot_failures(): void
    {
        $source = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');

        self::assertStringContainsString('ensureCatalogRow();', $source);
        self::assertGreaterThanOrEqual(3, substr_count($source, 'catch (Throwable)'));
    }

    public function test_provider_registers_session_auth_api_not_service_key(): void
    {
        $source = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');

        self::assertStringContainsString("prefix('api/social-automation')", $source);
        self::assertStringContainsString('Authenticate::class', $source);
        self::assertStringNotContainsString('service_key', $source);
        // Must not nest under seeding routes.
        self::assertStringNotContainsString('/api/seeding', $source);
        self::assertStringNotContainsString("url('/seeding')", $source);
    }

    public function test_panel_is_global_path(): void
    {
        $source = (string) file_get_contents($this->root.'/src/Providers/SocialAutomationPanelProvider.php');

        self::assertStringContainsString("->id('social-automation')", $source);
        self::assertStringContainsString("->path('social-automation')", $source);
    }

    public function test_db_connection_constant_and_migration_readme(): void
    {
        $config = (string) file_get_contents($this->root.'/src/Support/SocialAutomationServiceConfig.php');
        self::assertStringContainsString("CONNECTION = 'omi_social_automation'", $config);

        $readme = (string) file_get_contents($this->root.'/database/migrations/README.md');
        self::assertStringContainsString('omi_social_automation', $readme);
        self::assertStringContainsString('sa_', $readme);
    }
}
