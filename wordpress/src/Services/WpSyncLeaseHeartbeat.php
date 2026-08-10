<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncLeaseService;

/**
 * Process-local heartbeat binder — WordPressGateway (và chỗ khác) gọi touch() trong lúc sync dài.
 */
final class WpSyncLeaseHeartbeat
{
    private static ?int $jobId = null;

    private static ?ArticleWpSyncLeaseService $lease = null;

    private static float $lastBeatAt = 0.0;

    private static int $minIntervalSeconds = 15;

    public static function bind(SeoArticleWpSyncJob $job, ArticleWpSyncLeaseService $lease, int $minIntervalSeconds = 15): void
    {
        self::$jobId = (int) $job->id;
        self::$lease = $lease;
        self::$lastBeatAt = 0.0;
        self::$minIntervalSeconds = max(5, $minIntervalSeconds);
    }

    public static function clear(): void
    {
        self::$jobId = null;
        self::$lease = null;
        self::$lastBeatAt = 0.0;
    }

    public static function touch(bool $force = false): void
    {
        if (self::$jobId === null || self::$lease === null) {
            return;
        }

        $now = microtime(true);
        if (! $force && self::$lastBeatAt > 0 && ($now - self::$lastBeatAt) < self::$minIntervalSeconds) {
            return;
        }

        self::$lastBeatAt = $now;
        self::$lease->heartbeat(self::$jobId);
    }
}
