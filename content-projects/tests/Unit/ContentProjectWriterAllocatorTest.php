<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAllocator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectWriterAllocatorTest extends TestCase
{
    public function test_max_writer_monthly_items_is_centralized_at_30(): void
    {
        self::assertSame(30, ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS);
        self::assertSame(
            ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
            ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
        );
    }

    public function test_62_items_three_empty_users_fill_30_30_2(): void
    {
        $result = ContentProjectWriterAllocator::allocate(
            range(1, 62),
            [11, 22, 33],
            [11 => 30, 22 => 30, 33 => 30],
        );

        self::assertSame(0, $result['unallocated_count']);
        self::assertSame(
            [30, 30, 2],
            array_column($result['allocations'], 'item_count'),
        );
        self::assertSame([11, 22, 33], array_column($result['allocations'], 'user_id'));
        self::assertSame(range(1, 30), $result['allocations'][0]['task_ids']);
        self::assertSame(range(31, 60), $result['allocations'][1]['task_ids']);
        self::assertSame([61, 62], $result['allocations'][2]['task_ids']);
    }

    public function test_existing_workload_is_respected_deterministically(): void
    {
        // A=20/30 → 10, B=5/30 → 25, C=0/30 → 30; 40 items → 10/25/5
        $result = ContentProjectWriterAllocator::allocate(
            range(1, 40),
            [1, 2, 3],
            [1 => 10, 2 => 25, 3 => 30],
        );

        self::assertSame(0, $result['unallocated_count']);
        self::assertSame(
            [10, 25, 5],
            array_column($result['allocations'], 'item_count'),
        );
        self::assertSame([1, 2, 3], array_column($result['allocations'], 'user_id'));
    }

    public function test_full_user_is_skipped_and_never_exceeds_remaining(): void
    {
        $result = ContentProjectWriterAllocator::allocate(
            range(1, 12),
            [7, 8, 9],
            [7 => 0, 8 => 5, 9 => 30],
        );

        self::assertSame(0, $result['unallocated_count']);
        self::assertSame([8, 9], array_column($result['allocations'], 'user_id'));
        self::assertSame([5, 7], array_column($result['allocations'], 'item_count'));
        self::assertLessThanOrEqual(30, max(array_column($result['allocations'], 'item_count')));
    }

    public function test_insufficient_selected_capacity_reports_shortfall(): void
    {
        $result = ContentProjectWriterAllocator::allocate(
            range(1, 50),
            [1, 2],
            [1 => 10, 2 => 25],
        );

        self::assertSame(15, $result['unallocated_count']);
        self::assertCount(15, $result['unallocated_task_ids']);
        self::assertSame([10, 25], array_column($result['allocations'], 'item_count'));
    }

    public function test_selection_order_is_deterministic_not_random(): void
    {
        $first = ContentProjectWriterAllocator::allocate(
            range(1, 40),
            [3, 1, 2],
            [1 => 30, 2 => 30, 3 => 30],
        );
        $second = ContentProjectWriterAllocator::allocate(
            range(1, 40),
            [3, 1, 2],
            [1 => 30, 2 => 30, 3 => 30],
        );

        self::assertSame([3, 1], array_column($first['allocations'], 'user_id'));
        self::assertSame([30, 10], array_column($first['allocations'], 'item_count'));
        self::assertSame($first, $second);
    }

    public function test_empty_selection_allocates_nothing(): void
    {
        $result = ContentProjectWriterAllocator::allocate(range(1, 10), [], [1 => 30]);

        self::assertSame([], $result['allocations']);
        self::assertSame(10, $result['unallocated_count']);
    }

    public function test_capacity_service_excludes_draft_and_system_user(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringContainsString('KIND_MONTHLY', $src);
        self::assertStringContainsString('SeoOpsSystemUser::isSystemUserId', $src);
        self::assertStringContainsString("whereNull('p.archived_at')", $src);
        self::assertStringContainsString('COUNT(t.id)', $src);
        self::assertStringNotContainsString('auth()->id()', $src);
    }

    public function test_insufficient_copy_lives_in_lang(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');

        self::assertStringContainsString("'draft_split_insufficient'", $en);
        self::assertStringContainsString("'draft_split_insufficient'", $vi);
        self::assertStringContainsString('Cần thêm :count chỗ phân công', $vi);
        self::assertStringContainsString('draft_split_writers_heading', $vi);
        self::assertStringContainsString('Phân bổ dự kiến', $vi);
    }
}
