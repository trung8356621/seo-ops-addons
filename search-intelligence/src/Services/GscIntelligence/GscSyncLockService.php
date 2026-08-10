<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;

/**
 * Business locks cho GSC sync theo property_ref.
 */
class GscSyncLockService
{
    private const DEFAULT_TTL_SECONDS = 600;

    public function __construct(
        private readonly ContentProjectBusinessLock $businessLock,
    ) {}

    public function syncKey(string $propertyRef): string
    {
        return 'gsc-sync:'.trim($propertyRef);
    }

    public function acquire(string $propertyRef, int $waitSeconds = 0): ?string
    {
        return $this->businessLock->acquire($this->syncKey($propertyRef), $waitSeconds, $this->ttlConfig());
    }

    public function release(string $propertyRef, string $ownerToken): bool
    {
        return $this->businessLock->release($this->syncKey($propertyRef), $ownerToken);
    }

    /**
     * @template T
     *
     * @param  callable(?string $ownerToken): T  $callback
     * @return T
     */
    public function withSyncLock(string $propertyRef, callable $callback, int $waitSeconds = 0): mixed
    {
        return $this->businessLock->withLock($this->syncKey($propertyRef), $callback, $waitSeconds, $this->ttlConfig());
    }

    private function ttlConfig(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_TTL_SECONDS;
        }

        try {
            return (int) config('seo-content-ai.gsc_intelligence.lock.ttl_seconds', self::DEFAULT_TTL_SECONDS);
        } catch (\Throwable) {
            return self::DEFAULT_TTL_SECONDS;
        }
    }
}
