<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Canonical user-visible datetime formatter for SaaS.
 * Storage / queue / API machine timestamps stay UTC.
 */
final class SystemDateTime
{
    /** @var array{timezone: string, preset: string}|null */
    private static ?array $runtimeOverride = null;

    /** @var array{timezone: string, preset: string}|null */
    private static ?array $resolvedCache = null;

    public static function clearRuntimeCache(): void
    {
        self::$resolvedCache = null;
    }

    /**
     * @param  array{timezone?: string, preset?: string}|null  $config
     */
    public static function useConfig(?array $config): void
    {
        if ($config === null) {
            self::$runtimeOverride = null;
            self::$resolvedCache = null;

            return;
        }

        $tz = trim((string) ($config['timezone'] ?? SeoDateTimeSettingsService::DEFAULT_TIMEZONE));
        $preset = strtolower(trim((string) ($config['preset'] ?? SeoDateTimeSettingsService::DEFAULT_PRESET)));
        if (! SeoDateTimeSettingsService::isValidTimezone($tz)) {
            $tz = SeoDateTimeSettingsService::DEFAULT_TIMEZONE;
        }
        if (! SeoDateTimeSettingsService::isValidPreset($preset)) {
            $preset = SeoDateTimeSettingsService::DEFAULT_PRESET;
        }

        self::$runtimeOverride = [
            'timezone' => $tz,
            'preset' => $preset,
        ];
        self::$resolvedCache = self::$runtimeOverride;
    }

    public static function timezone(): string
    {
        return self::settings()['timezone'];
    }

    public static function preset(): string
    {
        return self::settings()['preset'];
    }

    public static function locale(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'en-US' : 'vi-VN';
    }

    public static function carbonLocale(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'en' : 'vi';
    }

    public static function phpDateFormat(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'F j, Y' : 'd/m/Y';
    }

    public static function phpTimeFormat(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'g:i A' : 'H:i';
    }

    public static function phpTimePreciseFormat(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'g:i:s A' : 'H:i:s';
    }

    public static function phpDateTimeFormat(): string
    {
        return self::phpDateFormat().' '.self::phpTimeFormat();
    }

    public static function phpDateTimePreciseFormat(): string
    {
        return self::phpDateFormat().' '.self::phpTimePreciseFormat();
    }

    public static function firstDayOfWeek(): int
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 0 : 1;
    }

    public static function hourCycle(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'h12' : 'h23';
    }

    public static function jsDateFormat(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'MMMM d, yyyy' : 'dd/MM/yyyy';
    }

    public static function jsTimeFormat(): string
    {
        return self::preset() === SeoDateTimeSettingsService::PRESET_EN ? 'h:mm a' : 'HH:mm';
    }

    public static function currentSystemTime(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    public static function toSystemTimezone(mixed $value): ?Carbon
    {
        $utc = self::toUtc($value);
        if ($utc === null) {
            return null;
        }

        return $utc->copy()->timezone(self::timezone());
    }

    public static function toUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof CarbonInterface) {
                return $value->copy()->utc();
            }
            if ($value instanceof DateTimeInterface) {
                return Carbon::instance($value)->utc();
            }
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return Carbon::createFromTimestamp((int) $value, 'UTC');
            }
            if (! is_string($value)) {
                return null;
            }

            return Carbon::parse(trim($value))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse user-facing datetime input in System timezone → UTC.
     *
     * Accepts: datetime-local (Y-m-d\TH:i), VI (d/m/Y H:i), EN (F j, Y g:i A), ISO-8601.
     */
    public static function parseSystemInputToUtc(?string $value): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Datetime input is required.');
        }

        $raw = trim($value);
        $tz = self::timezone();

        try {
            // ISO with Z / explicit offset — treat as absolute instant.
            if (preg_match('/[Zz]$|[+-]\d{2}:?\d{2}$/', $raw) === 1) {
                return Carbon::parse($raw)->utc();
            }

            // datetime-local
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $raw) === 1) {
                return Carbon::parse($raw, $tz)->utc();
            }

            // Vietnamese d/m/Y H:i
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m) === 1) {
                $local = Carbon::create(
                    (int) $m[3],
                    (int) $m[2],
                    (int) $m[1],
                    isset($m[4]) ? (int) $m[4] : 0,
                    isset($m[5]) ? (int) $m[5] : 0,
                    isset($m[6]) ? (int) $m[6] : 0,
                    $tz,
                );
                if ($local === false) {
                    throw new InvalidArgumentException('Invalid Vietnamese datetime.');
                }

                return $local->utc();
            }

            return Carbon::parse($raw, $tz)->utc();
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Invalid datetime input: '.$raw, 0, $e);
        }
    }

    public static function formatDate(mixed $value): ?string
    {
        $local = self::toSystemTimezone($value);

        return $local?->format(self::phpDateFormat());
    }

    public static function formatTime(mixed $value): ?string
    {
        $local = self::toSystemTimezone($value);

        return $local?->format(self::phpTimeFormat());
    }

    public static function formatDateTime(mixed $value): ?string
    {
        $local = self::toSystemTimezone($value);
        if ($local === null) {
            return null;
        }

        return $local->format(self::phpDateTimeFormat());
    }

    public static function formatDateTimePrecise(mixed $value): ?string
    {
        $local = self::toSystemTimezone($value);
        if ($local === null) {
            return null;
        }

        return $local->format(self::phpDateTimePreciseFormat());
    }

    /**
     * Two-line schedule label (date + time) for Publishing Queue.
     *
     * @return array{date: string, time: string, display: string}|null
     */
    public static function formatScheduleParts(mixed $value): ?array
    {
        $local = self::toSystemTimezone($value);
        if ($local === null) {
            return null;
        }

        $date = $local->format(self::phpDateFormat());
        $time = $local->format(self::phpTimeFormat());

        return [
            'date' => $date,
            'time' => $time,
            'display' => $date."\n".$time,
        ];
    }

    public static function formatRelative(mixed $value, ?CarbonInterface $now = null): ?string
    {
        $utc = self::toUtc($value);
        if ($utc === null) {
            return null;
        }

        $reference = $now !== null ? $now->copy()->utc() : Carbon::now('UTC');

        return $utc->copy()
            ->locale(self::carbonLocale())
            ->diffForHumans($reference);
    }

    public static function formatForInput(mixed $value): ?string
    {
        $local = self::toSystemTimezone($value);

        return $local?->format('Y-m-d\TH:i');
    }

    public static function formatUtcDebug(mixed $value): ?string
    {
        $utc = self::toUtc($value);

        return $utc?->format('Y-m-d H:i');
    }

    /**
     * Editor schedule label (weekday + date + time) — preset-aware.
     */
    public static function formatScheduleLabel(CarbonInterface $dateTime): string
    {
        $dt = $dateTime->copy()->timezone(self::timezone());

        if (self::preset() === SeoDateTimeSettingsService::PRESET_EN) {
            return $dt->format('D j, Y \\a\\t g:i A');
        }

        $weekdayMap = [
            0 => 'CN',
            1 => 'Th2',
            2 => 'Th3',
            3 => 'Th4',
            4 => 'Th5',
            5 => 'Th6',
            6 => 'Th7',
        ];
        $weekday = $weekdayMap[(int) $dt->dayOfWeek] ?? 'Th';

        return sprintf(
            '%s %d, %d at %02d:%02d',
            $weekday,
            (int) $dt->day,
            (int) $dt->year,
            (int) $dt->hour,
            (int) $dt->minute,
        );
    }

    public static function offsetLabel(?string $timezone = null, ?CarbonInterface $at = null): string
    {
        $tzName = $timezone ?? self::timezone();
        $moment = $at !== null ? $at->copy()->timezone($tzName) : Carbon::now($tzName);
        $offsetSeconds = $moment->getOffset();
        $sign = $offsetSeconds >= 0 ? '+' : '−';
        $abs = abs($offsetSeconds);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);

        return sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
    }

    public static function timezoneChip(?string $timezone = null): string
    {
        $tz = $timezone ?? self::timezone();

        return $tz.' · '.self::offsetLabel($tz);
    }

    public static function timezoneOptionLabel(string $timezone): string
    {
        return $timezone.' — '.self::offsetLabel($timezone);
    }

    /**
     * @return array<string, string> value => label
     */
    public static function timezoneSelectOptions(): array
    {
        $options = [];
        foreach (SeoDateTimeSettingsService::curatedTimezones() as $tz) {
            $options[$tz] = self::timezoneOptionLabel($tz);
        }

        $current = self::timezone();
        if (! isset($options[$current]) && SeoDateTimeSettingsService::isValidTimezone($current)) {
            $options[$current] = self::timezoneOptionLabel($current);
        }

        return $options;
    }

    /**
     * Frontend shared config payload.
     *
     * @return array{
     *     timezone: string,
     *     preset: string,
     *     locale: string,
     *     date_format: string,
     *     time_format: string,
     *     hour_cycle: string,
     *     first_day_of_week: int,
     *     offset_label: string,
     *     timezone_chip: string
     * }
     */
    public static function frontendConfig(): array
    {
        return [
            'timezone' => self::timezone(),
            'preset' => self::preset(),
            'locale' => self::locale(),
            'date_format' => self::jsDateFormat(),
            'time_format' => self::jsTimeFormat(),
            'hour_cycle' => self::hourCycle(),
            'first_day_of_week' => self::firstDayOfWeek(),
            'offset_label' => self::offsetLabel(),
            'timezone_chip' => self::timezoneChip(),
        ];
    }

    /**
     * Live preview block for Settings form.
     *
     * @return array{system: string, timezone_line: string, utc: string}
     */
    public static function previewSnapshot(?string $timezone = null, ?string $preset = null): array
    {
        $prev = self::$runtimeOverride;
        self::useConfig([
            'timezone' => $timezone ?? self::timezone(),
            'preset' => $preset ?? self::preset(),
        ]);

        try {
            $now = self::currentSystemTime();
            $utc = $now->copy()->utc();

            return [
                'system' => $now->format(self::phpDateTimeFormat()),
                'timezone_line' => self::timezoneOptionLabel(self::timezone()),
                'utc' => $utc->format(self::phpDateFormat().' '.self::phpTimeFormat()).' UTC',
            ];
        } finally {
            self::useConfig($prev);
        }
    }

    /**
     * @return array{timezone: string, preset: string}
     */
    private static function settings(): array
    {
        if (self::$runtimeOverride !== null) {
            return self::$runtimeOverride;
        }
        if (self::$resolvedCache !== null) {
            return self::$resolvedCache;
        }

        try {
            $settings = app(SeoDateTimeSettingsService::class)->getSettings();
        } catch (\Throwable) {
            // Fallback when container unbound (pure unit tests).
            $configured = trim((string) (function_exists('config')
                ? config('seo-content-ai.display_timezone', SeoDateTimeSettingsService::DEFAULT_TIMEZONE)
                : SeoDateTimeSettingsService::DEFAULT_TIMEZONE));
            $settings = [
                'timezone' => $configured !== '' && SeoDateTimeSettingsService::isValidTimezone($configured)
                    ? $configured
                    : SeoDateTimeSettingsService::DEFAULT_TIMEZONE,
                'preset' => SeoDateTimeSettingsService::DEFAULT_PRESET,
            ];
        }

        self::$resolvedCache = $settings;

        return $settings;
    }
}
