<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\Cache;

/**
 * Soft dirty flag: keyword input changed; cluster assignments are stale until full recluster.
 */
final class TopicClusterDirtyState
{
    public static function cacheKey(int $siteId): string
    {
        return 'topic_cluster_needs_recluster:'.$siteId;
    }

    public static function mark(int $siteId, string $reason = 'keyword_edited'): void
    {
        if ($siteId <= 0) {
            return;
        }

        Cache::put(self::cacheKey($siteId), [
            'dirty' => true,
            'reason' => $reason,
            'marked_at' => now()->toIso8601String(),
        ], 86400 * 7);
    }

    public static function clear(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }

        Cache::forget(self::cacheKey($siteId));
    }

    public static function isDirty(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $cached = Cache::get(self::cacheKey($siteId));

        return is_array($cached) && (bool) ($cached['dirty'] ?? false);
    }

    /**
     * @return array{dirty: bool, reason: string, marked_at: string}|null
     */
    public static function payload(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }

        $cached = Cache::get(self::cacheKey($siteId));
        if (! is_array($cached) || ! (bool) ($cached['dirty'] ?? false)) {
            return null;
        }

        return [
            'dirty' => true,
            'reason' => (string) ($cached['reason'] ?? ''),
            'marked_at' => (string) ($cached['marked_at'] ?? ''),
        ];
    }
}
