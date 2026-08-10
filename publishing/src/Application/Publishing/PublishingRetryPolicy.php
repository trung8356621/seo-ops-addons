<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use Carbon\CarbonInterface;

/**
 * Backoff: attempt1 fail → +5m, attempt2 → +15m, attempt3 → +30m, then exhausted.
 * Max 4 attempts total (1 initial + 3 retries).
 */
final class PublishingRetryPolicy
{
    public const MAX_ATTEMPTS = PublishOperationKeyFactory::MAX_ATTEMPTS;

    public const LEASE_MINUTES = 5;

    /** Minutes after delivery_dispatched_at without publisher_started_at → DELIVERY_WORKER_STALLED */
    public const AWAITING_WORKER_MINUTES = 10;

    /** @var array<int, int> attempt_number_that_just_failed => delay minutes until next */
    private const BACKOFF_MINUTES_AFTER_ATTEMPT = [
        1 => 5,
        2 => 15,
        3 => 30,
    ];

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    public function leaseExpiresAt(?CarbonInterface $from = null): CarbonInterface
    {
        return ($from ?? now('UTC'))->copy()->utc()->addMinutes(self::LEASE_MINUTES);
    }

    public function canRetry(int $attemptCount): bool
    {
        return $attemptCount < self::MAX_ATTEMPTS;
    }

    public function nextRetryAt(int $attemptCountJustFailed, ?CarbonInterface $override = null): ?CarbonInterface
    {
        if ($override !== null) {
            return $override;
        }

        if (! $this->canRetry($attemptCountJustFailed)) {
            return null;
        }

        $minutes = self::BACKOFF_MINUTES_AFTER_ATTEMPT[$attemptCountJustFailed] ?? null;
        if ($minutes === null) {
            return null;
        }

        return now('UTC')->addMinutes($minutes);
    }
}
