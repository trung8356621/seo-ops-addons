<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

use Illuminate\Support\Facades\Cache;

/**
 * Extension enabled/status/health — Cache is the runtime authority.
 *
 * The former DB compatibility branch for extension state rows is permanently retired.
 */
final class ExtensionStateStore
{
    private const CACHE_PREFIX = 'seo_extension_state:';

    public function isEnabled(string $id): bool
    {
        $cached = Cache::get(self::CACHE_PREFIX.'enabled:'.$id);

        return $cached !== null ? (bool) $cached : true;
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        Cache::forever(self::CACHE_PREFIX.'enabled:'.$id, $enabled);
    }

    public function getStatus(string $id): string
    {
        $cached = Cache::get(self::CACHE_PREFIX.'status:'.$id);

        return is_string($cached) ? $cached : 'healthy';
    }

    /**
     * @param  array<string, mixed>  $health
     */
    public function setHealth(string $id, array $health): void
    {
        $status = (string) ($health['status'] ?? (($health['ok'] ?? true) ? 'healthy' : 'error'));
        Cache::forever(self::CACHE_PREFIX.'status:'.$id, $status);
        Cache::forever(self::CACHE_PREFIX.'health:'.$id, $health);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHealthPayload(string $id): ?array
    {
        $cached = Cache::get(self::CACHE_PREFIX.'health:'.$id);

        return is_array($cached) ? $cached : null;
    }
}
