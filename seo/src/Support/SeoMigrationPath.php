<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Core\Database\MigrationPathLocator;

/**
 * Compat: tests/tooling still resolve migrations relative to SeoContentAi.
 * Phase 2 moved files into addons/{slug}/database/migrations.
 */
final class SeoMigrationPath
{
    public static function find(string $basename): string
    {
        $found = MigrationPathLocator::find($basename, dirname(__DIR__, 4));
        if ($found === null) {
            throw new \RuntimeException("Migration not found after Phase 2 move: {$basename}");
        }

        return $found;
    }

    /**
     * Directory that historically held all SEO migrations (now empty).
     */
    public static function legacyDirectory(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    }
}
