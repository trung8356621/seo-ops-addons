<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageRegistryContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_migration_uses_omi_social_automation_and_sa_prefix(): void
    {
        $path = $this->root.'/database/migrations/2026_10_21_100000_create_sa_package_registry_tables.php';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        self::assertStringContainsString("protected \$connection = 'omi_social_automation'", $sql);
        self::assertStringContainsString("create('sa_packages'", $sql);
        self::assertStringContainsString("create('sa_package_releases'", $sql);
        self::assertStringContainsString("create('sa_package_dependencies'", $sql);
        self::assertStringContainsString('content_digest', $sql);
        self::assertStringNotContainsString('omi_seo_ai', $sql);
        self::assertStringNotContainsString('omi_seeding', $sql);
        self::assertStringNotContainsString('foreign(', $sql);
    }

    public function test_api_services_routes_use_service_api_not_service_key(): void
    {
        $routes = (string) file_get_contents($this->root.'/routes/api-services.php');

        self::assertStringContainsString('EnsureSocialAutomationServiceApi', $routes);
        self::assertStringContainsString('social-automation:package:read', $routes);
        self::assertStringContainsString('social-automation:package:publish', $routes);
        self::assertStringContainsString('packages/publish', $routes);
        self::assertStringNotContainsString('services.service_key', $routes);
        self::assertStringContainsString('runner:job', $routes); // reserved, documented
    }

    public function test_client_migration_ownership_registers_plane(): void
    {
        $clientOwnership = dirname($this->root, 2).'/omnichannel-client/config/addon_migration_ownership.php';
        if (! is_file($clientOwnership)) {
            // Junction layout: client may be sibling via different workspace mount.
            $alt = 'D:/work/omnichannel-client/config/addon_migration_ownership.php';
            $clientOwnership = is_file($alt) ? $alt : $clientOwnership;
        }
        self::assertFileExists($clientOwnership);
        $config = (string) file_get_contents($clientOwnership);
        self::assertStringContainsString("'social-automation'", $config);
        self::assertStringContainsString('omi_social_automation', $config);
        self::assertStringContainsString('addons/social-automation/database/migrations', $config);
    }

    public function test_scopes_do_not_expose_service_key_literal(): void
    {
        $source = (string) file_get_contents($this->root.'/src/Support/SocialAutomationScopes.php');
        self::assertStringContainsString('PACKAGE_PUBLISH', $source);
        self::assertStringNotContainsString('service_key', $source);
    }
}
