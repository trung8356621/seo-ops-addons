<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Carbon\Carbon;
use Omnichannel\Addons\Content\Support\SystemDateTime;

final class MonthlyMcpFreshness
{
    public const STALE_HOURS = 48;

    public static function isNewer(?string $sourceUpdatedAt, ?string $snapshotUpdatedAt): bool
    {
        $source = self::parse($sourceUpdatedAt);
        $snap = self::parse($snapshotUpdatedAt);
        if ($source === null || $snap === null) {
            return false;
        }

        return $source->gt($snap);
    }

    public static function isSourceStale(?string $sourceUpdatedAt, int $hours = self::STALE_HOURS): bool
    {
        $parsed = self::parse($sourceUpdatedAt);
        if ($parsed === null) {
            return true;
        }

        return $parsed->lt(now()->subHours($hours));
    }

    public static function relative(?string $value): ?string
    {
        return SystemDateTime::formatRelative($value);
    }

    public static function parse(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<?string>  $candidates
     */
    public static function maxIso(array $candidates): ?string
    {
        $best = null;
        foreach ($candidates as $candidate) {
            $parsed = self::parse(is_string($candidate) ? $candidate : null);
            if ($parsed === null) {
                continue;
            }
            if ($best === null || $parsed->gt($best)) {
                $best = $parsed;
            }
        }

        return $best?->toIso8601String();
    }
}
