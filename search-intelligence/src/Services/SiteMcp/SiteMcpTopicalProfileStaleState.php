<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SiteMcp;

use Illuminate\Support\Facades\Cache;

/**
 * Soft dirty flag: keyword clusters changed; Site MCP topical snapshot is stale until rebuild.
 */
final class SiteMcpTopicalProfileStaleState
{
    public static function cacheKey(int $siteId): string
    {
        return 'site_mcp_topical_profile_stale:'.$siteId;
    }

    public static function mark(int $siteId, string $reason = 'clusters_changed'): void
    {
        if ($siteId <= 0) {
            return;
        }

        Cache::put(self::cacheKey($siteId), [
            'stale' => true,
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

    public static function isStale(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $cached = Cache::get(self::cacheKey($siteId));

        return is_array($cached) && (bool) ($cached['stale'] ?? false);
    }

    /**
     * @return array{stale: bool, reason: string, marked_at: string}|null
     */
    public static function payload(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }

        $cached = Cache::get(self::cacheKey($siteId));
        if (! is_array($cached) || ! (bool) ($cached['stale'] ?? false)) {
            return null;
        }

        return [
            'stale' => true,
            'reason' => (string) ($cached['reason'] ?? ''),
            'marked_at' => (string) ($cached['marked_at'] ?? ''),
        ];
    }
}
