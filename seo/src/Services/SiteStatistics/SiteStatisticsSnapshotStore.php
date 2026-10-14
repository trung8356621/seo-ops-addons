<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteStatistics;

use Illuminate\Support\Facades\Cache;

/**
 * Persistent per-site statistics snapshot.
 *
 * Uses the default cache store (database in this app): survives PHP restarts,
 * no Redis. Snapshot keys have no TTL. Replacement is a single cache put
 * after a successful rebuild, guarded by a per-site generation counter.
 */
final class SiteStatisticsSnapshotStore
{
    public const SCHEMA_VERSION = 1;

    public function get(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }

        $payload = Cache::get($this->snapshotKey($siteId));
        if (! is_array($payload) || (int) ($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return null;
        }

        if ((int) ($payload['site_id'] ?? 0) !== $siteId) {
            return null;
        }

        return $payload;
    }

    public function generation(int $siteId): int
    {
        return max(0, (int) Cache::get($this->generationKey($siteId), 0));
    }

    public function bumpGeneration(int $siteId): int
    {
        $key = $this->generationKey($siteId);
        if (! Cache::has($key)) {
            Cache::forever($key, 0);
        }

        return (int) Cache::increment($key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(int $siteId, array $payload): void
    {
        Cache::forever($this->snapshotKey($siteId), $payload);
    }

    public function snapshotKey(int $siteId): string
    {
        return 'seo.site_statistics.v1.snapshot.'.$siteId;
    }

    public function generationKey(int $siteId): string
    {
        return 'seo.site_statistics.v1.generation.'.$siteId;
    }

    public function pendingKey(int $siteId): string
    {
        return 'seo.site_statistics.v1.pending.'.$siteId;
    }

    public function lockKey(int $siteId): string
    {
        return 'seo.site_statistics.v1.lock.'.$siteId;
    }
}
