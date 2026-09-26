<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Carbon\Carbon;
use Omnichannel\Addons\Seo\Services\Context\ContextFreshness;

/**
 * Compatibility facade — delegates to ContextFreshness.
 *
 * @deprecated Prefer ContextFreshness for new Context-layer code.
 */
final class MonthlyMcpFreshness
{
    public const STALE_HOURS = ContextFreshness::STALE_HOURS;

    public static function isNewer(?string $sourceUpdatedAt, ?string $snapshotUpdatedAt): bool
    {
        return ContextFreshness::isNewer($sourceUpdatedAt, $snapshotUpdatedAt);
    }

    public static function isSourceStale(?string $sourceUpdatedAt, int $hours = self::STALE_HOURS): bool
    {
        return ContextFreshness::isSourceStale($sourceUpdatedAt, $hours);
    }

    public static function relative(?string $value): ?string
    {
        return ContextFreshness::relative($value);
    }

    public static function parse(?string $value): ?Carbon
    {
        return ContextFreshness::parse($value);
    }

    /**
     * @param  list<?string>  $candidates
     */
    public static function maxIso(array $candidates): ?string
    {
        return ContextFreshness::maxIso($candidates);
    }
}
