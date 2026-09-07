<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\AiModel;
use PHPUnit\Framework\TestCase;

/**
 * Ownership: Settings UI location ≠ data DB. Canonical AI config is CORE.
 */
final class CanonicalApiConnectionOwnershipTest extends TestCase
{
    public function test_ownership_registry_lists_ai_config_on_core(): void
    {
        $path = dirname(__DIR__, 3).'/../omnichannel-client/config/database_table_ownership.php';
        if (! is_file($path)) {
            $path = 'D:/work/omnichannel-client/config/database_table_ownership.php';
        }
        $this->assertFileExists($path);
        /** @var array<string, mixed> $own */
        $own = include $path;
        $coreTables = $own['owners']['core']['tables'] ?? [];
        foreach ([
            'api_connections',
            'seo_ai_models',
            'ai_model_capabilities',
            'ai_routing_profiles',
            'ai_routing_targets',
            'ai_provider_templates',
        ] as $table) {
            $this->assertContains($table, $coreTables, "{$table} must be owned by core");
        }
    }

    public function test_api_connection_and_ai_model_use_core_trait(): void
    {
        $this->assertContains(
            \App\Models\Concerns\UsesCoreDatabaseConnection::class,
            class_uses_recursive(ApiConnection::class),
        );
        $this->assertContains(
            \App\Models\Concerns\UsesCoreDatabaseConnection::class,
            class_uses_recursive(AiModel::class),
        );
    }
}
