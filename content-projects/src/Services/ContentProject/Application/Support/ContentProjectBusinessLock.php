<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Business locks — owner token, per-op TTL, không forceRelease lock của owner khác.
 */
final class ContentProjectBusinessLock
{
    private const TTL_GENERATE = 600;

    private const TTL_ARCHIVE = 300;

    private const TTL_RESTORE = 180;

    private const TTL_SCHEDULE = 180;

    private const TTL_PUBLISH = 300;

    /**
     * @template T
     *
     * @param  callable(?string $ownerToken): T  $callback
     * @return T
     */
    public function withLock(string $key, callable $callback, int $waitSeconds = 5, ?int $ttlOverride = null): mixed
    {
        $ownerToken = $this->acquire($key, $waitSeconds, $ttlOverride);
        if ($ownerToken === null) {
            throw new RuntimeException('operation.locked: Business lock busy: '.$key);
        }

        try {
            return $callback($ownerToken);
        } finally {
            $this->release($key, $ownerToken);
        }
    }

    public function acquire(string $key, int $waitSeconds = 5, ?int $ttlOverride = null): ?string
    {
        $normalizedKey = $this->normalize($key);
        $ttl = $ttlOverride ?? $this->ttlForKey($key);
        $ownerToken = (string) Str::uuid();
        $deadline = microtime(true) + max(0, $waitSeconds);

        do {
            if (Cache::add($normalizedKey, $ownerToken, $ttl)) {
                return $ownerToken;
            }

            if ($waitSeconds <= 0) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function release(string $key, string $ownerToken): bool
    {
        $normalizedKey = $this->normalize($key);
        $current = Cache::get($normalizedKey);

        if (! is_string($current) || ! hash_equals($current, $ownerToken)) {
            return false;
        }

        Cache::forget($normalizedKey);

        return true;
    }

    public function refresh(string $key, string $ownerToken, int $ttl): bool
    {
        $normalizedKey = $this->normalize($key);
        $current = Cache::get($normalizedKey);

        if (! is_string($current) || ! hash_equals($current, $ownerToken)) {
            return false;
        }

        Cache::put($normalizedKey, $ownerToken, $ttl);

        return true;
    }

    public function projectGenerate(int $projectId): string
    {
        return "project:{$projectId}:generate";
    }

    public function projectArchive(int $projectId): string
    {
        return "project:{$projectId}:archive";
    }

    public function projectRestore(int $projectId): string
    {
        return "project:{$projectId}:restore";
    }

    public function projectSchedule(int $projectId): string
    {
        return "project:{$projectId}:schedule";
    }

    public function itemPublish(int $itemId): string
    {
        return "item:{$itemId}:publish";
    }

    private function ttlForKey(string $key): int
    {
        if (str_contains($key, ':generate')) {
            return self::TTL_GENERATE;
        }

        if (str_contains($key, ':archive')) {
            return self::TTL_ARCHIVE;
        }

        if (str_contains($key, ':restore')) {
            return self::TTL_RESTORE;
        }

        if (str_contains($key, ':schedule')) {
            return self::TTL_SCHEDULE;
        }

        if (str_contains($key, ':publish')) {
            return self::TTL_PUBLISH;
        }

        return self::TTL_SCHEDULE;
    }

    private function normalize(string $key): string
    {
        return 'seo.cp.lock.'.trim($key);
    }
}
