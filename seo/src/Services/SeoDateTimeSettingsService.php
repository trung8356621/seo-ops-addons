<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use App\Models\WpOption;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Canonical SaaS Date & Time settings (wp_options on core DB).
 * Storage/execution timezone stays UTC — these values are display/parse only.
 */
final class SeoDateTimeSettingsService
{
    public const OPTION_KEY = 'seo_datetime_settings';

    public const KEY_TIMEZONE = 'timezone';

    public const KEY_PRESET = 'preset';

    public const PRESET_VI = 'vi';

    public const PRESET_EN = 'en';

    public const DEFAULT_TIMEZONE = 'Asia/Ho_Chi_Minh';

    public const DEFAULT_PRESET = self::PRESET_VI;

    private const CACHE_KEY = 'seo_datetime_settings.v1';

    /** @var array{timezone: string, preset: string}|null */
    private ?array $inMemorySettings = null;

    public static function withDefaults(): self
    {
        $service = new self();
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    /**
     * @return array{timezone: string, preset: string}
     */
    public function defaultSettings(): array
    {
        return [
            self::KEY_TIMEZONE => self::DEFAULT_TIMEZONE,
            self::KEY_PRESET => self::DEFAULT_PRESET,
        ];
    }

    /**
     * @return array{timezone: string, preset: string}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->normalize($this->inMemorySettings);
        }

        if (function_exists('cache')) {
            try {
                /** @var array{timezone?: string, preset?: string}|null $cached */
                $cached = cache()->get(self::CACHE_KEY);
                if (is_array($cached)) {
                    return $this->normalize($cached);
                }
            } catch (\Throwable) {
                // Pure PHPUnit / cache unbound — fall through.
            }
        }

        $stored = [];
        try {
            $raw = WpOption::get(self::OPTION_KEY, []);
            if (is_array($raw)) {
                $stored = $raw;
            }
        } catch (\Throwable) {
            $stored = [];
        }

        $normalized = $this->normalize($stored);

        if (function_exists('cache')) {
            try {
                cache()->forever(self::CACHE_KEY, $normalized);
            } catch (\Throwable) {
                // ignore
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{timezone: string, preset: string}
     */
    public function save(array $input): array
    {
        $normalized = $this->normalize($input);
        $this->assertValid($normalized);

        if ($this->inMemorySettings !== null) {
            $this->inMemorySettings = $normalized;

            return $normalized;
        }

        WpOption::set(self::OPTION_KEY, $normalized);
        $this->invalidateCache();
        SystemDateTime::clearRuntimeCache();

        return $normalized;
    }

    public function invalidateCache(): void
    {
        if (function_exists('cache')) {
            try {
                cache()->forget(self::CACHE_KEY);
            } catch (\Throwable) {
                // ignore
            }
        }

        SystemDateTime::clearRuntimeCache();
    }

    /**
     * @return list<string>
     */
    public static function curatedTimezones(): array
    {
        return [
            'UTC',
            'Asia/Ho_Chi_Minh',
            'Asia/Bangkok',
            'Asia/Singapore',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Asia/Jakarta',
            'Asia/Kolkata',
            'Europe/London',
            'Europe/Paris',
            'Europe/Berlin',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'Australia/Sydney',
        ];
    }

    public static function isValidTimezone(string $timezone): bool
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return false;
        }

        try {
            new DateTimeZone($timezone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isValidPreset(string $preset): bool
    {
        return in_array($preset, [self::PRESET_VI, self::PRESET_EN], true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{timezone: string, preset: string}
     */
    private function normalize(array $input): array
    {
        $defaults = $this->defaultSettings();
        $timezone = trim((string) ($input[self::KEY_TIMEZONE] ?? $defaults[self::KEY_TIMEZONE]));
        $preset = strtolower(trim((string) ($input[self::KEY_PRESET] ?? $defaults[self::KEY_PRESET])));

        if (! self::isValidTimezone($timezone)) {
            $timezone = $defaults[self::KEY_TIMEZONE];
        }
        if (! self::isValidPreset($preset)) {
            $preset = $defaults[self::KEY_PRESET];
        }

        return [
            self::KEY_TIMEZONE => $timezone,
            self::KEY_PRESET => $preset,
        ];
    }

    /**
     * @param  array{timezone: string, preset: string}  $settings
     */
    private function assertValid(array $settings): void
    {
        if (! self::isValidTimezone($settings[self::KEY_TIMEZONE])) {
            throw new InvalidArgumentException('Invalid IANA timezone.');
        }
        if (! self::isValidPreset($settings[self::KEY_PRESET])) {
            throw new InvalidArgumentException('Preset must be vi or en.');
        }
    }
}
