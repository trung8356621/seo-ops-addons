<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

/**
 * Resolve max_delay_time from automation action settings snapshot.
 */
final class ProductReviewDelaySettings
{
    public const KEY = 'max_delay_time';

    public const LEGACY_KEY = 'delay_max_after_minutes';

    public const DEFAULT_MINUTES = 5;

    public const MIN_MINUTES = 0;

    public const MAX_MINUTES = 1440;

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function resolveMaxDelayMinutes(array $settings, ?int $fallback = self::DEFAULT_MINUTES): int
    {
        $raw = $settings[self::KEY] ?? $settings[self::LEGACY_KEY] ?? $fallback;
        if ($raw === null || $raw === '') {
            return max(self::MIN_MINUTES, (int) ($fallback ?? self::DEFAULT_MINUTES));
        }

        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int) $raw));
    }

    /**
     * Pick delay once: 0 → immediate; N → random 60..(N*60) seconds.
     */
    public static function pickDelaySeconds(int $maxDelayMinutes): int
    {
        if ($maxDelayMinutes <= 0) {
            return 0;
        }

        $minSeconds = 60;
        $maxSeconds = max($minSeconds, $maxDelayMinutes * 60);

        return random_int($minSeconds, $maxSeconds);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalizeSettings(array $settings, mixed $maxDelayTime = null): array
    {
        $out = $settings;
        if ($maxDelayTime !== null && $maxDelayTime !== '') {
            $out[self::KEY] = self::resolveMaxDelayMinutes([self::KEY => $maxDelayTime], self::DEFAULT_MINUTES);
        } elseif (! array_key_exists(self::KEY, $out) && array_key_exists(self::LEGACY_KEY, $out)) {
            $out[self::KEY] = self::resolveMaxDelayMinutes($out, self::DEFAULT_MINUTES);
        }

        unset($out[self::LEGACY_KEY]);

        if (! array_key_exists(self::KEY, $out)) {
            $out[self::KEY] = self::DEFAULT_MINUTES;
        } else {
            $out[self::KEY] = self::resolveMaxDelayMinutes($out, self::DEFAULT_MINUTES);
        }

        return $out;
    }
}
