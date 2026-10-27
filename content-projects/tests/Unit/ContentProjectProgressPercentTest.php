<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProgressReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchProgressReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPercent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectProgressPercentTest extends TestCase
{
    public function test_skipped_excluded_from_denominator_completed_run(): void
    {
        $math = ContentProjectProgressPercent::compute(30, 24, 0, 6, true);

        self::assertSame(30, $math['snapshot_total']);
        self::assertSame(6, $math['skipped']);
        self::assertSame(24, $math['eligible_total']);
        self::assertSame(24, $math['processed']);
        self::assertSame(100, $math['percent']);
        self::assertFalse($math['no_eligible_work']);
    }

    public function test_failed_counts_as_processed_not_succeeded(): void
    {
        $math = ContentProjectProgressPercent::compute(30, 22, 2, 6, true);

        self::assertSame(24, $math['eligible_total']);
        self::assertSame(24, $math['processed']);
        self::assertSame(100, $math['percent']);
        self::assertSame(22, $math['succeeded']);
        self::assertSame(2, $math['failed']);
        self::assertFalse($math['no_eligible_work']);
    }

    public function test_zero_eligible_terminal_is_no_work_not_divide_by_zero(): void
    {
        $math = ContentProjectProgressPercent::compute(6, 0, 0, 6, true);

        self::assertSame(0, $math['eligible_total']);
        self::assertSame(100, $math['percent']);
        self::assertSame(0, $math['raw_percent']);
        self::assertTrue($math['no_eligible_work']);

        $mathActive = ContentProjectProgressPercent::compute(6, 0, 0, 6, false);
        self::assertSame(0, $mathActive['percent']);
        self::assertSame(0, $mathActive['raw_percent']);
    }

    public function test_in_progress_pending_keeps_percent_below_100(): void
    {
        $math = ContentProjectProgressPercent::compute(30, 10, 0, 6, false);

        self::assertSame(24, $math['eligible_total']);
        self::assertSame(10, $math['processed']);
        self::assertSame(41, $math['percent']); // floor(10/24*100)
    }

    public function test_project_and_global_read_models_use_shared_math(): void
    {
        $project = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectProgressReadModel::class))->getFileName(),
        );
        $global = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectProgressPercent::compute', $project);
        self::assertStringContainsString('ContentProjectProgressPercent::compute', $global);
        self::assertStringContainsString('eligible_total', $project);
        self::assertStringContainsString('no_eligible_work', $project);
        self::assertStringContainsString('eligible_total', $global);
        self::assertStringContainsString('no_eligible_work', $global);
    }
}
