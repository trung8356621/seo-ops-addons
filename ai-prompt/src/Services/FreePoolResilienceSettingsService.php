<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\WpOption;
use InvalidArgumentException;

/**
 * AI Center → Health → Free Pool Resilience settings.
 */
final class FreePoolResilienceSettingsService
{
    public const OPTION_KEY = 'ai_free_pool_resilience_settings';

    public const KEY_FAILURE_WINDOW_MINUTES = 'free_pool_failure_window_minutes';

    public const KEY_HARD_LOCK_RATIO_PERCENT = 'free_pool_hard_lock_ratio_percent';

    public const KEY_MIN_DISTINCT_FAILURE_MODELS = 'free_pool_min_distinct_failure_models';

    public const KEY_FIRST_COOLDOWN_MINUTES = 'free_model_first_cooldown_minutes';

    public const KEY_REPEAT_COOLDOWN_MINUTES = 'free_model_repeat_cooldown_minutes';

    public const KEY_QUARANTINE_FAILURE_THRESHOLD = 'free_model_quarantine_failure_threshold';

    public const KEY_QUARANTINE_HOURS = 'free_model_quarantine_hours';

    public const KEY_MAX_QUARANTINE_HOURS = 'free_model_max_quarantine_hours';

    /**
     * @deprecated Catalog TTL lives in {@see AiModelCatalogSettingsService}.
     * Kept for one-release read-compat / migration only.
     */
    public const KEY_CATALOG_FRESHNESS_HOURS = 'free_pool_catalog_freshness_hours';

    /**
     * @deprecated Forced sync debounce lives in {@see AiModelCatalogSettingsService}.
     */
    public const KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES = 'free_pool_forced_sync_min_interval_minutes';

    public const KEY_FIRST_PROBE_MINUTES = 'free_pool_first_probe_minutes';

    public const KEY_PROBE_BACKOFF_MULTIPLIER = 'free_pool_probe_backoff_multiplier';

    public const KEY_MAX_PROBE_HOURS = 'free_pool_max_probe_hours';

    public const KEY_SUCCESS_PROBES_TO_UNLOCK = 'free_pool_success_probes_to_unlock';

    public const KEY_HARD_LOCK_WHEN_ALL_ATTEMPTED_FAIL = 'free_pool_hard_lock_when_all_attempted_fail';

    /**
     * @return array<string, int|float|bool>
     */
    public function get(int $userId = 0): array
    {
        $bag = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($bag)) {
            return $this->defaults();
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
     * @return array<string, int|float|bool>
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
     * @return array<string, int|float|bool>
     */
    public function defaults(): array
    {
        return [
            self::KEY_FAILURE_WINDOW_MINUTES => 10,
            self::KEY_HARD_LOCK_RATIO_PERCENT => 70,
            self::KEY_MIN_DISTINCT_FAILURE_MODELS => 4,
            self::KEY_FIRST_COOLDOWN_MINUTES => 15,
            self::KEY_REPEAT_COOLDOWN_MINUTES => 60,
            self::KEY_QUARANTINE_FAILURE_THRESHOLD => 3,
            self::KEY_QUARANTINE_HOURS => 6,
            self::KEY_MAX_QUARANTINE_HOURS => 24,
            self::KEY_FIRST_PROBE_MINUTES => 30,
            self::KEY_PROBE_BACKOFF_MULTIPLIER => 2.0,
            self::KEY_MAX_PROBE_HOURS => 12,
            self::KEY_SUCCESS_PROBES_TO_UNLOCK => 1,
            self::KEY_HARD_LOCK_WHEN_ALL_ATTEMPTED_FAIL => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, int|float|bool>
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
        $float = static function (mixed $v, float $default, float $min, float $max): float {
            $n = is_numeric($v) ? (float) $v : $default;
            if ($n < $min || $n > $max) {
                throw new InvalidArgumentException("Value {$n} out of range [{$min}, {$max}].");
            }

            return $n;
        };

        return [
            self::KEY_FAILURE_WINDOW_MINUTES => $int(
                $settings[self::KEY_FAILURE_WINDOW_MINUTES] ?? $d[self::KEY_FAILURE_WINDOW_MINUTES],
                (int) $d[self::KEY_FAILURE_WINDOW_MINUTES],
                1,
                24 * 60,
            ),
            self::KEY_HARD_LOCK_RATIO_PERCENT => $int(
                $settings[self::KEY_HARD_LOCK_RATIO_PERCENT] ?? $d[self::KEY_HARD_LOCK_RATIO_PERCENT],
                (int) $d[self::KEY_HARD_LOCK_RATIO_PERCENT],
                1,
                100,
            ),
            self::KEY_MIN_DISTINCT_FAILURE_MODELS => $int(
                $settings[self::KEY_MIN_DISTINCT_FAILURE_MODELS] ?? $d[self::KEY_MIN_DISTINCT_FAILURE_MODELS],
                (int) $d[self::KEY_MIN_DISTINCT_FAILURE_MODELS],
                1,
                100,
            ),
            self::KEY_FIRST_COOLDOWN_MINUTES => $int(
                $settings[self::KEY_FIRST_COOLDOWN_MINUTES] ?? $d[self::KEY_FIRST_COOLDOWN_MINUTES],
                (int) $d[self::KEY_FIRST_COOLDOWN_MINUTES],
                1,
                24 * 60,
            ),
            self::KEY_REPEAT_COOLDOWN_MINUTES => $int(
                $settings[self::KEY_REPEAT_COOLDOWN_MINUTES] ?? $d[self::KEY_REPEAT_COOLDOWN_MINUTES],
                (int) $d[self::KEY_REPEAT_COOLDOWN_MINUTES],
                1,
                7 * 24 * 60,
            ),
            self::KEY_QUARANTINE_FAILURE_THRESHOLD => $int(
                $settings[self::KEY_QUARANTINE_FAILURE_THRESHOLD] ?? $d[self::KEY_QUARANTINE_FAILURE_THRESHOLD],
                (int) $d[self::KEY_QUARANTINE_FAILURE_THRESHOLD],
                1,
                50,
            ),
            self::KEY_QUARANTINE_HOURS => $int(
                $settings[self::KEY_QUARANTINE_HOURS] ?? $d[self::KEY_QUARANTINE_HOURS],
                (int) $d[self::KEY_QUARANTINE_HOURS],
                1,
                168,
            ),
            self::KEY_MAX_QUARANTINE_HOURS => $int(
                $settings[self::KEY_MAX_QUARANTINE_HOURS] ?? $d[self::KEY_MAX_QUARANTINE_HOURS],
                (int) $d[self::KEY_MAX_QUARANTINE_HOURS],
                1,
                720,
            ),
            self::KEY_FIRST_PROBE_MINUTES => $int(
                $settings[self::KEY_FIRST_PROBE_MINUTES] ?? $d[self::KEY_FIRST_PROBE_MINUTES],
                (int) $d[self::KEY_FIRST_PROBE_MINUTES],
                1,
                24 * 60,
            ),
            self::KEY_PROBE_BACKOFF_MULTIPLIER => $float(
                $settings[self::KEY_PROBE_BACKOFF_MULTIPLIER] ?? $d[self::KEY_PROBE_BACKOFF_MULTIPLIER],
                (float) $d[self::KEY_PROBE_BACKOFF_MULTIPLIER],
                1.0,
                10.0,
            ),
            self::KEY_MAX_PROBE_HOURS => $int(
                $settings[self::KEY_MAX_PROBE_HOURS] ?? $d[self::KEY_MAX_PROBE_HOURS],
                (int) $d[self::KEY_MAX_PROBE_HOURS],
                1,
                168,
            ),
            self::KEY_SUCCESS_PROBES_TO_UNLOCK => $int(
                $settings[self::KEY_SUCCESS_PROBES_TO_UNLOCK] ?? $d[self::KEY_SUCCESS_PROBES_TO_UNLOCK],
                (int) $d[self::KEY_SUCCESS_PROBES_TO_UNLOCK],
                1,
                10,
            ),
            self::KEY_HARD_LOCK_WHEN_ALL_ATTEMPTED_FAIL => (bool) (
                $settings[self::KEY_HARD_LOCK_WHEN_ALL_ATTEMPTED_FAIL]
                ?? $d[self::KEY_HARD_LOCK_WHEN_ALL_ATTEMPTED_FAIL]
            ),
        ];
    }
}
