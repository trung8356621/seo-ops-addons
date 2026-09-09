<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Support\SeedingTargetCalculator;
use PHPUnit\Framework\TestCase;

final class SeedingTargetCalculatorTest extends TestCase
{
    public function test_ceil_division_not_round(): void
    {
        $calc = new SeedingTargetCalculator;
        self::assertSame(3, $calc->requiredPerUser(20, 7));
        self::assertSame(4, $calc->requiredPerUser(20, 6));
        self::assertSame(1, $calc->requiredPerUser(5, 10));
        self::assertSame(20, $calc->requiredPerUser(20, 1));
    }

    public function test_snapshot_fields(): void
    {
        $calc = new SeedingTargetCalculator;
        $snap = $calc->snapshot(20, 7);
        self::assertSame(20, $snap['max_comments_target']);
        self::assertSame(7, $snap['member_count_at_share']);
        self::assertSame(3, $snap['required_comments_per_user']);
    }

    public function test_member_count_floor_is_one(): void
    {
        $calc = new SeedingTargetCalculator;
        $snap = $calc->snapshot(20, 0);
        self::assertSame(1, $snap['member_count_at_share']);
        self::assertSame(20, $snap['required_comments_per_user']);
    }
}
