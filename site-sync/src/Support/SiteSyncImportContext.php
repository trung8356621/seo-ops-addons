<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Support;

/**
 * Marks Site Sync batch reconcile / WP import path so editor-only side effects can defer.
 */
final class SiteSyncImportContext
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
