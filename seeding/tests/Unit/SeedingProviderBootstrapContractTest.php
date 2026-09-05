<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Providers\SeedingPanelProvider;
use Omnichannel\Addons\Seeding\SeedingServiceProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Seeding must boot and register routes without SeoAccessControl / SEO ownership.
 */
final class SeedingProviderBootstrapContractTest extends TestCase
{
    public function test_seeding_registers_early_on_its_own_manifest(): void
    {
        $path = dirname(__DIR__, 2).'/addon.json';
        self::assertFileExists($path);

        $meta = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($meta);
        self::assertTrue((bool) ($meta['register_early'] ?? false));
        self::assertSame(SeedingServiceProvider::class, (string) ($meta['provider'] ?? ''));
        self::assertSame(SeedingPanelProvider::class, (string) ($meta['panel_provider'] ?? ''));
        self::assertSame('omi_seeding', (string) ($meta['db_connection'] ?? ''));
        self::assertSame([], $meta['requires'] ?? null);
        self::assertContains('seeding.workspace', array_map('strval', $meta['provides'] ?? []));
    }

    public function test_seeding_is_not_bootstrapped_only_via_seo_content_ai_compat(): void
    {
        $path = dirname(__DIR__, 3).'/seo-content-ai-compat/addon.json';
        self::assertFileExists($path);

        $meta = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($meta);
        self::assertNotContains(
            SeedingServiceProvider::class,
            array_map('strval', $meta['providers'] ?? []),
        );
        self::assertNotSame(
            SeedingPanelProvider::class,
            (string) ($meta['panel_provider'] ?? ''),
        );
    }

    public function test_seeding_php_sources_do_not_import_seo_access_control_or_seo_namespace(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\.php$/i',
        );

        foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            $source = (string) file_get_contents($pathname);
            self::assertStringNotContainsString(
                'use Omnichannel\\Addons\\Seo\\Support\\SeoAccessControl',
                $source,
                $pathname,
            );
            self::assertDoesNotMatchRegularExpression(
                '/^use\\s+Omnichannel\\\\Addons\\\\Seo\\\\/m',
                $source,
                $pathname,
            );
        }
    }

    public function test_canonical_runtime_paths_do_not_query_omi_seo_ai(): void
    {
        $roots = [
            dirname(__DIR__, 2).'/src/Support',
            dirname(__DIR__, 2).'/src/Providers',
            dirname(__DIR__, 2).'/src/Http',
            dirname(__DIR__, 2).'/src/Filament',
            dirname(__DIR__, 2).'/src/Settings',
            dirname(__DIR__, 2).'/src/Console',
        ];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new RegexIterator(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
                '/\.php$/i',
            );
            foreach ($iterator as $file) {
                $source = (string) file_get_contents($file->getPathname());
                self::assertStringNotContainsString(
                    "DB::connection('omi_seo_ai')",
                    $source,
                    $file->getPathname(),
                );
                self::assertStringNotContainsString(
                    "protected \$connection = 'omi_seo_ai'",
                    $source,
                    $file->getPathname(),
                );
            }
        }
    }
}
