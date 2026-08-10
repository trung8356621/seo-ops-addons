<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

final class SeoQueueContext
{
    private static int $wpSyncDepth = 0;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function runWpSyncFromQueue(callable $callback): mixed
    {
        self::$wpSyncDepth++;

        try {
            return $callback();
        } finally {
            self::$wpSyncDepth = max(0, self::$wpSyncDepth - 1);
        }
    }

    public static function isWpSyncFromQueue(): bool
    {
        return self::$wpSyncDepth > 0;
    }
}
