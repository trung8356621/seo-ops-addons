<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Proves legacy credential / experimental / extension-state DB paths stay retired.
 */
final class LegacyDatabaseCodeCleanupContractTest extends TestCase
{
    public function test_seeding_bootstrap_source_does_not_query_legacy_credential_table(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/seeding/src/Services/SeedingDatabaseConnectionService.php');

        self::assertStringNotContainsString("hasTable('seeding_database_connections')", $src);
        self::assertStringNotContainsString('SeedingDatabaseConnection::query()', $src);
        self::assertStringContainsString('ServiceDatabaseConnectionResolver', $src);
        self::assertStringContainsString('PUBLIC_SEEDING', $src);
        self::assertStringContainsString('omi_seeding', $src);
    }

    public function test_seo_shared_bootstrap_prefers_service_database_connection_resolver(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/search-foundation/src/Services/SeoDatabaseConnectionService.php');

        self::assertStringContainsString('bootstrapCanonicalSharedConnection', $src);
        self::assertStringContainsString('ServiceDatabaseConnectionResolver', $src);
        self::assertStringContainsString("tryBootstrap('seo'", $src);
        // Shared bootstrap must not fall back to querying the retired table as primary SoT.
        self::assertStringContainsString('Legacy seo_database_connections credential plane is retired', $src);
    }

    public function test_publishing_cron_does_not_abort_when_legacy_credential_table_absent(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/publishing/src/Services/ScheduledArticlePublishRunner.php');

        self::assertStringNotContainsString("if (! Schema::hasTable('seo_database_connections')) {\n                return \$stats;", $src);
        self::assertStringContainsString('bootstrapLegacySharedConnection', $src);
        self::assertStringContainsString('canonical_shared', $src);
    }

    public function test_seeding_runtime_has_no_link_resource_consumer(): void
    {
        $roots = [
            dirname(__DIR__, 3).'/seeding/src',
        ];

        $hits = [];
        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                if (str_contains($contents, 'LinkResource')
                    || str_contains($contents, "link_resources")
                    || str_contains($contents, 'seeding_topic_links')
                ) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $hits, 'Unexpected LinkResource / link_resources / seeding_topic_links references');
        self::assertDirectoryDoesNotExist(dirname(__DIR__, 3).'/seeding/database/legacy-experimental');
        self::assertFileDoesNotExist(dirname(__DIR__, 3).'/seeding/src/LinkIntelligence/Models/LinkResource.php');
    }

    public function test_active_migration_discovery_excludes_legacy_experimental(): void
    {
        self::assertDirectoryDoesNotExist(dirname(__DIR__, 3).'/seeding/database/legacy-experimental');

        $clientOwnership = dirname(__DIR__, 3).'/../omnichannel-client/config/addon_migration_ownership.php';
        if (! is_file($clientOwnership)) {
            return;
        }

        $ownership = (string) file_get_contents($clientOwnership);
        if (str_contains($ownership, 'legacy-experimental')) {
            self::assertStringContainsString('legacy-experimental intentionally omitted', $ownership);
        }
    }

    public function test_extension_state_store_is_cache_only(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/agent/src/Extension/ExtensionStateStore.php');

        self::assertStringContainsString("CACHE_PREFIX = 'seo_extension_state:'", $src);
        self::assertDoesNotMatchRegularExpression("/->table\\(\\s*['\"]seo_extension_states['\"]/", $src);
        self::assertDoesNotMatchRegularExpression("/hasTable\\(\\s*['\"]seo_extension_states['\"]/", $src);
        self::assertStringNotContainsString('hasDbTable', $src);
        self::assertStringNotContainsString('DB::connection', $src);
        self::assertStringNotContainsString('Schema::', $src);
        self::assertStringContainsString('Cache::get', $src);
        self::assertStringContainsString('Cache::forever', $src);
    }

    public function test_extension_states_tombstone_migration_exists_and_create_is_historical(): void
    {
        $create = dirname(__DIR__, 3).'/seo/database/migrations/2026_07_27_160000_create_seo_extension_states_table.php';
        $drop = dirname(__DIR__, 3).'/seo/database/migrations/2026_09_22_100000_drop_retired_seo_extension_states_table.php';

        self::assertFileExists($create);
        self::assertFileExists($drop);
        $dropSrc = (string) file_get_contents($drop);
        self::assertStringContainsString("drop('seo_extension_states')", $dropSrc);
        self::assertStringContainsString('hasTable', $dropSrc);
    }

    public function test_ownership_map_marks_legacy_credential_and_extension_tables_obsolete(): void
    {
        $map = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/DB_OWNERSHIP_MAP.json'), true);
        self::assertIsArray($map);

        $core = $map['owners']['core'] ?? [];
        $seo = $map['owners']['seo'] ?? [];
        $obsolete = $map['owners']['legacy-obsolete'] ?? [];

        self::assertNotContains('seo_database_connections', $core);
        self::assertContains('service_database_connections', $core);
        self::assertNotContains('seo_extension_states', $seo);
        self::assertContains('seo_database_connections', $obsolete);
        self::assertContains('seeding_database_connections', $obsolete);
        self::assertContains('seo_extension_states', $obsolete);
        self::assertContains('link_resources', $obsolete);
    }
}
