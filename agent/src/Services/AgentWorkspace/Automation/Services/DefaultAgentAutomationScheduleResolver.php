<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationScheduleResolver;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DefaultAgentAutomationScheduleResolver implements AgentAutomationScheduleResolver
{
    public const FREQ_HOURLY = 'hourly';

    public const FREQ_DAILY = 'daily';

    public const FREQ_WEEKLY = 'weekly';

    public const FREQ_MONTHLY = 'monthly';

    public const FREQ_CUSTOM_INTERVAL = 'custom_interval';

    /** @var list<string> */
    private const ALLOWED_FREQ = [
        self::FREQ_HOURLY,
        self::FREQ_DAILY,
        self::FREQ_WEEKLY,
        self::FREQ_MONTHLY,
        self::FREQ_CUSTOM_INTERVAL,
    ];

    public function __construct(
        private readonly int $minimumIntervalMinutes = 15,
    ) {}

    public function minimumIntervalMinutes(): int
    {
        return $this->minimumIntervalMinutes;
    }

    public function resolve(array $trigger, ?DateTimeImmutable $fromUtc = null): array
    {
        $errors = [];
        $warnings = [];

        $timezone = trim((string) ($trigger['timezone'] ?? 'UTC'));
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['invalid_timezone']];
        }

        $frequency = trim((string) ($trigger['frequency'] ?? ''));
        if (! in_array($frequency, self::ALLOWED_FREQ, true)) {
            return ['ok' => false, 'errors' => ['unsupported_frequency']];
        }

        if (isset($trigger['cron']) && is_string($trigger['cron']) && $trigger['cron'] !== '') {
            return ['ok' => false, 'errors' => ['raw_cron_not_supported']];
        }

        $time = trim((string) ($trigger['time'] ?? '09:00'));
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['ok' => false, 'errors' => ['invalid_time']];
        }

        $intervalMinutes = null;
        if ($frequency === self::FREQ_CUSTOM_INTERVAL) {
            $intervalMinutes = (int) ($trigger['interval_minutes'] ?? $trigger['interval'] ?? 0);
            if ($intervalMinutes < $this->minimumIntervalMinutes) {
                return ['ok' => false, 'errors' => ['interval_too_frequent']];
            }
        }

        if ($frequency === self::FREQ_HOURLY) {
            $intervalMinutes = max($this->minimumIntervalMinutes, (int) ($trigger['interval_minutes'] ?? 60));
            if ($intervalMinutes < $this->minimumIntervalMinutes) {
                return ['ok' => false, 'errors' => ['interval_too_frequent']];
            }
        }

        $daysOfWeek = $this->normalizeDaysOfWeek($trigger['days_of_week'] ?? null);
        if ($frequency === self::FREQ_WEEKLY && $daysOfWeek === []) {
            return ['ok' => false, 'errors' => ['days_of_week_required']];
        }

        $dayOfMonth = isset($trigger['day_of_month']) ? (int) $trigger['day_of_month'] : 1;
        if ($frequency === self::FREQ_MONTHLY && ($dayOfMonth < 1 || $dayOfMonth > 31)) {
            return ['ok' => false, 'errors' => ['invalid_day_of_month']];
        }

        $monthOverflow = (string) ($trigger['month_overflow'] ?? 'last_valid_day');
        if (! in_array($monthOverflow, ['last_valid_day', 'skip'], true)) {
            return ['ok' => false, 'errors' => ['invalid_month_overflow']];
        }

        $fromUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $fromLocal = $fromUtc->setTimezone($tz);

        $startAt = $this->parseOptionalDate($trigger['start_at'] ?? null, $tz);
        $endAt = $this->parseOptionalDate($trigger['end_at'] ?? null, $tz);
        if ($startAt === false || $endAt === false) {
            return ['ok' => false, 'errors' => ['invalid_start_or_end']];
        }

        $quietHours = $this->normalizeQuietHours($trigger['quiet_hours'] ?? null, $errors);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $normalized = [
            'frequency' => $frequency,
            'timezone' => $timezone,
            'time' => $time,
            'days_of_week' => $daysOfWeek,
            'day_of_month' => $dayOfMonth,
            'interval_minutes' => $intervalMinutes,
            'month_overflow' => $monthOverflow,
            'start_at' => $startAt?->format(DATE_ATOM),
            'end_at' => $endAt?->format(DATE_ATOM),
            'quiet_hours' => $quietHours,
        ];

        $occurrences = [];
        $cursor = $fromLocal;
        if ($startAt !== null && $cursor < $startAt) {
            $cursor = $startAt;
        }

        for ($i = 0; $i < 3; $i++) {
            $next = $this->computeNext($cursor, $frequency, $time, $daysOfWeek, $dayOfMonth, $intervalMinutes, $monthOverflow, $tz);
            if ($next === null) {
                break;
            }
            if ($endAt !== null && $next > $endAt) {
                break;
            }
            $occurrences[] = $next->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            $cursor = $next->modify('+1 second');
        }

        if ($occurrences === [] && $endAt !== null) {
            $warnings[] = 'no_occurrences_before_end';
        }

        $nextRunAt = $occurrences[0] ?? null;

        return [
            'ok' => true,
            'normalized' => $normalized,
            'next_run_at' => $nextRunAt,
            'preview_occurrences' => $occurrences,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<int>  $daysOfWeek
     */
    private function computeNext(
        DateTimeImmutable $fromLocal,
        string $frequency,
        string $time,
        array $daysOfWeek,
        int $dayOfMonth,
        ?int $intervalMinutes,
        string $monthOverflow,
        DateTimeZone $tz,
    ): ?DateTimeImmutable {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return match ($frequency) {
            self::FREQ_HOURLY, self::FREQ_CUSTOM_INTERVAL => $this->nextInterval($fromLocal, max(1, (int) $intervalMinutes)),
            self::FREQ_DAILY => $this->nextDaily($fromLocal, $hour, $minute, $tz),
            self::FREQ_WEEKLY => $this->nextWeekly($fromLocal, $hour, $minute, $daysOfWeek, $tz),
            self::FREQ_MONTHLY => $this->nextMonthly($fromLocal, $hour, $minute, $dayOfMonth, $monthOverflow, $tz),
            default => null,
        };
    }

    private function nextInterval(DateTimeImmutable $from, int $minutes): DateTimeImmutable
    {
        $aligned = $from->setTime((int) $from->format('H'), (int) $from->format('i'), 0);
        if ($aligned <= $from) {
            return $aligned->add(new DateInterval('PT'.$minutes.'M'));
        }

        return $aligned;
    }

    private function nextDaily(DateTimeImmutable $from, int $hour, int $minute, DateTimeZone $tz): DateTimeImmutable
    {
        $candidate = $from->setTime($hour, $minute, 0);
        if ($candidate <= $from) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone($tz);
    }

    /**
     * @param  list<int>  $daysOfWeek  0=Sun … 6=Sat
     */
    private function nextWeekly(
        DateTimeImmutable $from,
        int $hour,
        int $minute,
        array $daysOfWeek,
        DateTimeZone $tz,
    ): DateTimeImmutable {
        sort($daysOfWeek);
        for ($i = 0; $i < 14; $i++) {
            $day = $from->modify('+'.$i.' day')->setTime($hour, $minute, 0);
            $dow = (int) $day->format('w');
            if (in_array($dow, $daysOfWeek, true) && $day > $from) {
                return $day->setTimezone($tz);
            }
        }

        return $from->modify('+7 day')->setTime($hour, $minute, 0);
    }

    private function nextMonthly(
        DateTimeImmutable $from,
        int $hour,
        int $minute,
        int $dayOfMonth,
        string $monthOverflow,
        DateTimeZone $tz,
    ): ?DateTimeImmutable {
        for ($offset = 0; $offset < 24; $offset++) {
            $base = $from->modify('first day of this month')->modify('+'.$offset.' month');
            $lastDay = (int) $base->format('t');
            if ($dayOfMonth > $lastDay) {
                if ($monthOverflow === 'skip') {
                    continue;
                }
                $useDay = $lastDay;
            } else {
                $useDay = $dayOfMonth;
            }
            $candidate = $base->setDate((int) $base->format('Y'), (int) $base->format('m'), $useDay)
                ->setTime($hour, $minute, 0);
            if ($candidate > $from) {
                return $candidate->setTimezone($tz);
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function normalizeDaysOfWeek(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $d) {
            $n = (int) $d;
            if ($n >= 0 && $n <= 6) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return DateTimeImmutable|null|false
     */
    private function parseOptionalDate(mixed $raw, DateTimeZone $tz): DateTimeImmutable|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw)) {
            return false;
        }
        try {
            return new DateTimeImmutable($raw, $tz);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function normalizeQuietHours(mixed $raw, array &$errors): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }
        if (! is_array($raw)) {
            $errors[] = 'invalid_quiet_hours';

            return null;
        }
        $start = trim((string) ($raw['start'] ?? ''));
        $end = trim((string) ($raw['end'] ?? ''));
        $policy = trim((string) ($raw['policy'] ?? 'delay_notification'));
        if (! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end)) {
            $errors[] = 'invalid_quiet_hours';

            return null;
        }
        if (! in_array($policy, ['delay_notification', 'skip_non_critical', 'ignore'], true)) {
            $errors[] = 'invalid_quiet_hours_policy';

            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'policy' => $policy,
        ];
    }
}
