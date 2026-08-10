<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Cutover;

/**
 * Per-site cutover modes — never dual automatic writers.
 */
final class SiteSyncCutoverModes
{
    public const LEGACY_ACTIVE = 'legacy_active';

    public const V2_SHADOW = 'v2_shadow';

    public const V2_ACTIVE = 'v2_active';

    /** @var list<string> */
    public const ALL = [
        self::LEGACY_ACTIVE,
        self::V2_SHADOW,
        self::V2_ACTIVE,
    ];

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function allowedTransitions(bool $emergencyOverride = false): array
    {
        $base = [
            [self::LEGACY_ACTIVE, self::V2_SHADOW],
            [self::V2_SHADOW, self::V2_ACTIVE],
            [self::V2_SHADOW, self::LEGACY_ACTIVE],
            [self::V2_ACTIVE, self::LEGACY_ACTIVE],
        ];
        if ($emergencyOverride) {
            $base[] = [self::LEGACY_ACTIVE, self::V2_ACTIVE];
        }

        return $base;
    }

    public static function canTransition(string $from, string $to, bool $emergencyOverride = false): bool
    {
        foreach (self::allowedTransitions($emergencyOverride) as [$a, $b]) {
            if ($a === $from && $b === $to) {
                return true;
            }
        }

        return false;
    }
}
