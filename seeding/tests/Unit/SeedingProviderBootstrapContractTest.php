<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\SeedingServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Peer addon providers are DB-gated via services.is_active unless listed in
 * seo-content-ai-compat early providers (same pattern as ContentServiceProvider).
 * Without this, Filament nav label resolves to "seeding::filament.topics.nav"
 * and seeding:: views are missing at runtime.
 */
final class SeedingProviderBootstrapContractTest extends TestCase
{
    public function test_seeding_service_provider_is_registered_early_via_seo_content_ai_compat(): void
    {
        $path = dirname(__DIR__, 3).'/seo-content-ai-compat/addon.json';
        self::assertFileExists($path);

        $meta = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($meta);
        self::assertTrue((bool) ($meta['register_early'] ?? false));
        self::assertContains(
            SeedingServiceProvider::class,
            array_map('strval', $meta['providers'] ?? []),
        );
    }
}
