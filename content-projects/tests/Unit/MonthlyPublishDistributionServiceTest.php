<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Carbon\Carbon;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Publishing\MonthlyPublishDistributionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MonthlyPublishDistributionServiceTest extends TestCase
{
    private MonthlyPublishDistributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MonthlyPublishDistributionService;
    }

    public function test_thirty_items_thirty_days_one_per_day(): void
    {
        $counts = $this->service->computeDayCounts(30, 30);
        self::assertCount(30, $counts);
        self::assertSame(30, array_sum($counts));
        self::assertSame(array_fill(0, 30, 1), $counts);
    }

    public function test_sixty_items_thirty_days_two_per_day(): void
    {
        $counts = $this->service->computeDayCounts(60, 30);
        self::assertSame(60, array_sum($counts));
        self::assertSame(array_fill(0, 30, 2), $counts);
    }

    public function test_forty_five_items_thirty_days_mixed_load(): void
    {
        $counts = $this->service->computeDayCounts(45, 30);
        self::assertSame(45, array_sum($counts));
        self::assertSame(15, count(array_filter($counts, static fn (int $c): bool => $c === 2)));
        self::assertSame(15, count(array_filter($counts, static fn (int $c): bool => $c === 1)));
    }

    public function test_fifteen_items_thirty_days_spread_not_consecutive(): void
    {
        $counts = $this->service->computeDayCounts(15, 30);
        self::assertSame(15, array_sum($counts));
        $usedDays = [];
        foreach ($counts as $idx => $count) {
            if ($count > 0) {
                $usedDays[] = $idx;
            }
        }
        self::assertCount(15, $usedDays);
        self::assertNotSame(range(0, 14), $usedDays);
        $gaps = [];
        for ($i = 1; $i < count($usedDays); $i++) {
            $gaps[] = $usedDays[$i] - $usedDays[$i - 1];
        }
        self::assertGreaterThan(1, min($gaps));
    }

    public function test_spacing_five_minutes_between_slots(): void
    {
        $tz = 'Asia/Ho_Chi_Minh';
        $day = Carbon::parse('2026-09-01 00:00:00', $tz);
        $slots = $this->service->assignTimesForDay($day, 3, '09:00', '17:00', 5, [], $tz);

        self::assertCount(3, $slots);
        self::assertSame('09:00', $slots[0]->copy()->timezone($tz)->format('H:i'));
        self::assertSame('09:05', $slots[1]->copy()->timezone($tz)->format('H:i'));
        self::assertSame('09:10', $slots[2]->copy()->timezone($tz)->format('H:i'));
    }

    public function test_spacing_ten_minutes_between_slots(): void
    {
        $tz = 'Asia/Ho_Chi_Minh';
        $day = Carbon::parse('2026-09-01 00:00:00', $tz);
        $slots = $this->service->assignTimesForDay($day, 2, '09:00', '17:00', 10, [], $tz);

        self::assertCount(2, $slots);
        self::assertSame('09:00', $slots[0]->copy()->timezone($tz)->format('H:i'));
        self::assertSame('09:10', $slots[1]->copy()->timezone($tz)->format('H:i'));
    }

    public function test_existing_nine_am_forces_next_at_least_nine_oh_five(): void
    {
        $tz = 'Asia/Ho_Chi_Minh';
        $day = Carbon::parse('2026-09-01 00:00:00', $tz);
        $existing = [Carbon::parse('2026-09-01 09:00:00', $tz)];

        $slots = $this->service->assignTimesForDay($day, 1, '09:00', '17:00', 5, $existing, $tz);

        self::assertCount(1, $slots);
        self::assertSame('09:05', $slots[0]->copy()->timezone($tz)->format('H:i'));
    }

    public function test_past_month_window_throws_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

        try {
            $this->service->resolveProjectMonthWindow(Carbon::parse('2026-08-01'), 'UTC');
            self::fail('Expected RuntimeException for past month.');
        } catch (RuntimeException $e) {
            self::assertSame('Publishing window has already ended.', $e->getMessage());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_allocate_full_month_density(): void
    {
        $tz = 'UTC';
        $start = Carbon::parse('2026-09-01 00:00:00', $tz);
        $end = Carbon::parse('2026-09-30 23:59:59', $tz);

        $result = $this->service->allocate($start, $end, 30, [
            'day_start' => '09:00',
            'day_end' => '17:00',
            'min_spacing_minutes' => 5,
        ], [], $tz);

        self::assertCount(30, $result['slots']);
        self::assertSame(0, $result['unscheduled_count']);
        self::assertSame(1.0, $result['density_approx']);
    }

    public function test_allocate_respects_existing_anchor_spacing(): void
    {
        $tz = 'UTC';
        $start = Carbon::parse('2026-09-01 00:00:00', $tz);
        $end = Carbon::parse('2026-09-01 23:59:59', $tz);
        $existing = [Carbon::parse('2026-09-01 09:00:00', $tz)->utc()->toIso8601String()];

        $result = $this->service->allocate($start, $end, 2, [
            'day_start' => '09:00',
            'day_end' => '17:00',
            'min_spacing_minutes' => 5,
        ], $existing, $tz);

        self::assertCount(2, $result['slots']);
        $times = array_map(
            static fn (Carbon $c): string => $c->copy()->timezone($tz)->format('H:i'),
            $result['slots'],
        );
        self::assertContains('09:05', $times);
    }

    public function test_balanced_allocation_fills_around_existing_load(): void
    {
        $existing = array_fill(0, 30, 0);
        for ($i = 0; $i < 10; $i++) {
            $existing[$i] = 1;
        }

        $counts = $this->service->computeDayCountsBalanced(20, 30, $existing);

        self::assertSame(20, array_sum($counts));
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(0, $counts[$i], "day {$i} already loaded should not get new items first");
        }
        self::assertSame(20, array_sum(array_slice($counts, 10)));
    }

    public function test_ninety_items_thirty_days_no_month_cap(): void
    {
        $counts = $this->service->computeDayCounts(90, 30);
        self::assertSame(90, array_sum($counts));
        self::assertSame(array_fill(0, 30, 3), $counts);
    }
}
