<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationScheduleResolver;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AgentAutomationScheduleTest extends TestCase
{
    private DefaultAgentAutomationScheduleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DefaultAgentAutomationScheduleResolver(15);
    }

    public function test_hourly_respects_minimum_interval(): void
    {
        $ok = $this->resolver->resolve([
            'frequency' => 'custom_interval',
            'interval_minutes' => 5,
            'timezone' => 'UTC',
            'time' => '09:00',
        ]);
        self::assertFalse($ok['ok']);
        self::assertContains('interval_too_frequent', $ok['errors']);
    }

    public function test_daily_timezone_next_runs(): void
    {
        $from = new DateTimeImmutable('2026-07-28 01:00:00', new DateTimeZone('UTC'));
        $result = $this->resolver->resolve([
            'frequency' => 'daily',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'time' => '09:00',
        ], $from);
        self::assertTrue($result['ok']);
        self::assertCount(3, $result['preview_occurrences']);
        self::assertNotNull($result['next_run_at']);
    }

    public function test_weekly_requires_days(): void
    {
        $result = $this->resolver->resolve([
            'frequency' => 'weekly',
            'timezone' => 'UTC',
            'time' => '10:00',
        ]);
        self::assertFalse($result['ok']);
        self::assertContains('days_of_week_required', $result['errors']);
    }

    public function test_weekly_ok(): void
    {
        $from = new DateTimeImmutable('2026-07-28 00:00:00', new DateTimeZone('UTC')); // Tuesday
        $result = $this->resolver->resolve([
            'frequency' => 'weekly',
            'timezone' => 'UTC',
            'time' => '10:00',
            'days_of_week' => [1, 3], // Mon, Wed
        ], $from);
        self::assertTrue($result['ok']);
        self::assertNotEmpty($result['preview_occurrences']);
    }

    public function test_monthly_overflow_last_valid_day(): void
    {
        $from = new DateTimeImmutable('2026-01-31 12:00:00', new DateTimeZone('UTC'));
        $result = $this->resolver->resolve([
            'frequency' => 'monthly',
            'timezone' => 'UTC',
            'time' => '09:00',
            'day_of_month' => 31,
            'month_overflow' => 'last_valid_day',
        ], $from);
        self::assertTrue($result['ok']);
        self::assertNotEmpty($result['preview_occurrences']);
    }

    public function test_end_date_stops_occurrences(): void
    {
        $from = new DateTimeImmutable('2026-07-28 00:00:00', new DateTimeZone('UTC'));
        $result = $this->resolver->resolve([
            'frequency' => 'daily',
            'timezone' => 'UTC',
            'time' => '09:00',
            'end_at' => '2026-07-28T08:00:00+00:00',
        ], $from);
        self::assertTrue($result['ok']);
        self::assertSame([], $result['preview_occurrences']);
        self::assertContains('no_occurrences_before_end', $result['warnings']);
    }

    public function test_invalid_timezone(): void
    {
        $result = $this->resolver->resolve([
            'frequency' => 'daily',
            'timezone' => 'Not/AZone',
            'time' => '09:00',
        ]);
        self::assertFalse($result['ok']);
        self::assertContains('invalid_timezone', $result['errors']);
    }

    public function test_raw_cron_rejected(): void
    {
        $result = $this->resolver->resolve([
            'frequency' => 'daily',
            'timezone' => 'UTC',
            'time' => '09:00',
            'cron' => '0 * * * *',
        ]);
        self::assertFalse($result['ok']);
        self::assertContains('raw_cron_not_supported', $result['errors']);
    }

    public function test_quiet_hours_normalized(): void
    {
        $result = $this->resolver->resolve([
            'frequency' => 'daily',
            'timezone' => 'UTC',
            'time' => '09:00',
            'quiet_hours' => [
                'start' => '22:00',
                'end' => '07:00',
                'policy' => 'delay_notification',
            ],
        ]);
        self::assertTrue($result['ok']);
        self::assertSame('delay_notification', $result['normalized']['quiet_hours']['policy']);
    }

    public function test_next_three_preview_runs(): void
    {
        $from = new DateTimeImmutable('2026-07-28 00:00:00', new DateTimeZone('UTC'));
        $result = $this->resolver->resolve([
            'frequency' => 'custom_interval',
            'interval_minutes' => 30,
            'timezone' => 'UTC',
            'time' => '00:00',
        ], $from);
        self::assertTrue($result['ok']);
        self::assertCount(3, $result['preview_occurrences']);
    }
}
