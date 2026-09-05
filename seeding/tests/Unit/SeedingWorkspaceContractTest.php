<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Http\Controllers\SeedingBootstrapController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingHealthController;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage;
use Omnichannel\Addons\Seeding\Providers\SeedingPanelProvider;
use Omnichannel\Addons\Seeding\SeedingServiceProvider;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingWorkspaceContractTest extends TestCase
{
    public function test_provider_registers_service_bootstrap_and_health_routes(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(SeedingServiceProvider::class))->getFileName()
        );

        self::assertStringContainsString(SeedingBootstrapController::class, $provider);
        self::assertStringContainsString(SeedingHealthController::class, $provider);
        self::assertStringContainsString('SeedingDatabaseConnectionService', $provider);
        self::assertStringContainsString('api/seeding', $provider);
        self::assertStringContainsString('seeding.bootstrap', $provider);
        self::assertStringContainsString('seeding.health', $provider);
        self::assertStringNotContainsString('$this->loadMigrationsFrom', $provider);
    }

    public function test_workspace_page_passes_bootstrap_not_topic_api_base(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeedingTopicsPage::class))->getFileName()
        );
        self::assertStringContainsString('bootstrap', $source);
        self::assertStringNotContainsString("/api/seeding/topics", $source);
        self::assertStringContainsString("\$panel ?? 'seeding'", $source);
    }

    public function test_panel_provider_path_is_seeding(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeedingPanelProvider::class))->getFileName()
        );
        self::assertStringContainsString("->id('seeding')", $source);
        self::assertStringContainsString("->path('seeding')", $source);
    }

    public function test_react_workspace_is_local_only_no_topic_crud(): void
    {
        $workspace = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        $entry = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding-workspace.jsx'
        );
        $storage = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding/storage.js'
        );

        self::assertStringContainsString('seeding:v3:', $storage);
        self::assertStringContainsString('schema_version', $storage);
        self::assertStringContainsString('bootstrap', $entry);
        self::assertStringNotContainsString('/api/seeding/topics', $workspace);
        self::assertStringNotContainsString("method: 'POST'", $workspace);
        self::assertStringNotContainsString("method: 'PATCH'", $workspace);
        self::assertStringNotContainsString("method: 'DELETE'", $workspace);
        self::assertStringNotContainsString('seedingApiFetch', $workspace);
        self::assertStringNotContainsString('/api/seeding/topics', $entry);
    }

    public function test_storage_keys_scope_installation_user_site(): void
    {
        $storage = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding/storage.js'
        );
        self::assertStringContainsString('installationId', $storage);
        self::assertStringContainsString('userId', $storage);
        self::assertStringContainsString('siteId', $storage);
        self::assertMatchesRegularExpression('/seeding:v3:\$\{installationId\}:\$\{userId\}:\$\{siteId\}:doc/', $storage);
    }

    public function test_db_connection_constant_is_omi_seeding_not_seo(): void
    {
        self::assertSame('omi_seeding', SeedingServiceConfig::CONNECTION);
        self::assertSame('seeding', SeedingServiceResolver::SLUG);

        $resolver = (string) file_get_contents(
            (new ReflectionClass(SeedingServiceResolver::class))->getFileName()
        );
        self::assertStringContainsString('omi_seeding', $resolver);
        self::assertStringContainsString("strcasecmp(\$databaseName, 'omi_seo_ai')", $resolver);
    }

    public function test_active_migrations_dir_has_no_business_php(): void
    {
        $dir = dirname(__DIR__, 2).'/database/migrations';
        $files = glob($dir.'/*.php') ?: [];
        self::assertSame([], $files);

        $legacy = dirname(__DIR__, 2).'/database/legacy-experimental/migrations';
        self::assertDirectoryExists($legacy);
        self::assertNotEmpty(glob($legacy.'/*.php') ?: []);
    }
}
