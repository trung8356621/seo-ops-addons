<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\WpOption;
use InvalidArgumentException;

/**
 * AI Center → Resilience → Model Catalog settings.
 *
 * Generic catalog TTL / sync policy for every sync-capable AI connection.
 * Free Pool must reuse these keys — it must not own a competing catalog TTL.
 */
final class AiModelCatalogSettingsService
{
    public const OPTION_KEY = 'ai_model_catalog_settings';

    public const KEY_AUTO_REFRESH_ENABLED = 'model_catalog_auto_refresh_enabled';

    public const KEY_FRESHNESS_HOURS = 'model_catalog_freshness_hours';

    public const KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES = 'model_catalog_forced_sync_min_interval_minutes';

    public const KEY_KEEP_LAST_KNOWN_GOOD = 'model_catalog_keep_last_known_good';

    public const KEY_SYNC_ON_STRONG_STALE_ERROR = 'model_catalog_sync_on_strong_stale_error';

    public const KEY_SYNC_LOCK_MINUTES = 'model_catalog_sync_lock_minutes';

    /**
     * @return array<string, int|bool>
     */
    public function get(int $userId = 0): array
    {
        $bag = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($bag)) {
            return $this->defaultsWithLegacyFreePoolFallback($userId);
        }

        $scoped = is_array($bag[(string) $userId] ?? null)
            ? $bag[(string) $userId]
            : (is_array($bag['global'] ?? null) ? $bag['global'] : $bag);

        try {
            return $this->normalize($scoped);
        } catch (InvalidArgumentException) {
            return $this->defaults();
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, int|bool>
     */
    public function save(int $userId, array $settings): array
    {
        $normalized = $this->normalize($settings);
        $bag = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($bag)) {
            $bag = [];
        }
        $bag[(string) max(0, $userId)] = $normalized;
        WpOption::set(self::OPTION_KEY, $bag);

        return $normalized;
    }

    /**
     * @return array<string, int|bool>
     */
    public function defaults(): array
    {
        return [
            self::KEY_AUTO_REFRESH_ENABLED => true,
            self::KEY_FRESHNESS_HOURS => 6,
            self::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES => 30,
            self::KEY_KEEP_LAST_KNOWN_GOOD => true,
            self::KEY_SYNC_ON_STRONG_STALE_ERROR => true,
            self::KEY_SYNC_LOCK_MINUTES => 5,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, int|bool>
     */
    public function normalize(array $settings): array
    {
        $d = $this->defaults();
        $int = static function (mixed $v, int $default, int $min, int $max): int {
            $n = is_numeric($v) ? (int) $v : $default;
            if ($n < $min || $n > $max) {
                throw new InvalidArgumentException("Value {$n} out of range [{$min}, {$max}].");
            }

            return $n;
        };

        return [
            self::KEY_AUTO_REFRESH_ENABLED => (bool) (
                $settings[self::KEY_AUTO_REFRESH_ENABLED] ?? $d[self::KEY_AUTO_REFRESH_ENABLED]
            ),
            self::KEY_FRESHNESS_HOURS => $int(
                $settings[self::KEY_FRESHNESS_HOURS] ?? $d[self::KEY_FRESHNESS_HOURS],
                (int) $d[self::KEY_FRESHNESS_HOURS],
                1,
                168,
            ),
            self::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES => $int(
                $settings[self::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES]
                    ?? $d[self::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES],
                (int) $d[self::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES],
                1,
                24 * 60,
            ),
            self::KEY_KEEP_LAST_KNOWN_GOOD => (bool) (
                $settings[self::KEY_KEEP_LAST_KNOWN_GOOD] ?? $d[self::KEY_KEEP_LAST_KNOWN_GOOD]
            ),
            self::KEY_SYNC_ON_STRONG_STALE_ERROR => (bool) (
                $settings[self::KEY_SYNC_ON_STRONG_STALE_ERROR]
                    ?? $d[self::KEY_SYNC_ON_STRONG_STALE_ERROR]
            ),
            self::KEY_SYNC_LOCK_MINUTES => $int(
                $settings[self::KEY_SYNC_LOCK_MINUTES] ?? $d[self::KEY_SYNC_LOCK_MINUTES],
                (int) $d[self::KEY_SYNC_LOCK_MINUTES],
                1,
                60,
            ),
        ];
    }

    /**
     * One-time read-compat: migrate Free Pool duplicate catalog TTL into generic defaults.
     *
     * @return array<string, int|bool>
     */
    private function defaultsWithLegacyFreePoolFallback(int $userId): array
    {
        $defaults = $this->defaults();
        try {
            $legacy = (new FreePoolResilienceSettingsService())->get($userId);
            if (isset($legacy[FreePoolResilienceSettingsService::KEY_CATALOG_FRESHNESS_HOURS])) {
                $defaults[self::KEY_FRESHNESS_HOURS] = (int) $legacy[
                    FreePoolResilienceSettingsService::KEY_CATALOG_FRESHNESS_HOURS
                ];
            }
            if (isset($legacy[FreePoolResilienceSettingsService::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES])) {
                $defaults[self::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES] = (int) $legacy[
                    FreePoolResilienceSettingsService::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES
                ];
            }
        } catch (\Throwable) {
        }

        return $defaults;
    }
}
