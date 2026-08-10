<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

final class MetadataDomainSyncCache
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STALE_AFTER_SECONDS = 120;

    public static function cacheKey(int $userId, int $siteId): string
    {
        return 'seo_domain_meta_sync:'.$userId.':'.$siteId;
    }

    public static function fullItemsCacheKey(string $cacheKey): string
    {
        return $cacheKey.'_full_items';
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array{
     *     done: int,
     *     total: int,
     *     status: string,
     *     running: bool,
     *     message: ?string
     * }
     */
    public static function progressFromState(?array $state): array
    {
        if (! is_array($state) || ! is_array($state['refs'] ?? null)) {
            return [
                'done' => 0,
                'total' => 0,
                'status' => '',
                'running' => false,
                'message' => null,
            ];
        }

        $refsTotal = count($state['refs']);
        $offset = min($refsTotal, max(0, (int) ($state['offset'] ?? 0)));
        $status = (string) ($state['status'] ?? self::STATUS_RUNNING);

        return [
            'done' => $offset,
            'total' => $refsTotal,
            'status' => $status,
            'running' => self::isActivelyRunning($state),
            'message' => isset($state['message']) ? (string) $state['message'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public static function isActivelyRunning(?array $state): bool
    {
        if (! is_array($state) || ! is_array($state['refs'] ?? null)) {
            return false;
        }

        $status = (string) ($state['status'] ?? '');
        if ($status !== self::STATUS_RUNNING) {
            return false;
        }

        $total = count($state['refs']);
        $done = min($total, (int) ($state['offset'] ?? 0));

        if ($done >= $total) {
            return false;
        }

        $updatedAt = $state['updated_at'] ?? $state['started_at'] ?? null;
        if (! is_string($updatedAt) || $updatedAt === '') {
            return false;
        }

        try {
            $updated = \Illuminate\Support\Carbon::parse($updatedAt);
        } catch (\Throwable) {
            return false;
        }

        return $updated->greaterThanOrEqualTo(now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public static function isResumable(?array $state): bool
    {
        if (! is_array($state) || ! is_array($state['refs'] ?? null)) {
            return false;
        }

        if (self::isActivelyRunning($state)) {
            return false;
        }

        $status = (string) ($state['status'] ?? '');
        if (! in_array($status, [self::STATUS_RUNNING, self::STATUS_FAILED], true)) {
            return false;
        }

        $total = count($state['refs']);
        $done = (int) ($state['offset'] ?? 0);

        return $done > 0 && $done < $total;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function markResuming(array $state): array
    {
        $state['status'] = self::STATUS_RUNNING;
        $state['message'] = null;
        $state['updated_at'] = now()->toIso8601String();

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function touch(array $state): array
    {
        $state['updated_at'] = now()->toIso8601String();

        return $state;
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<string, mixed>
     */
    public static function initialState(array $prepared, array $refs): array
    {
        return [
            'status' => self::STATUS_RUNNING,
            'refs' => $refs,
            'offset' => 0,
            'total' => (int) ($prepared['total'] ?? count($refs)),
            'accumulated_synced' => [
                'article' => 0,
                'product' => 0,
                'category' => 0,
                'product_category' => 0,
                'other' => 0,
            ],
            'chunk_state' => [],
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'message' => null,
        ];
    }
}
