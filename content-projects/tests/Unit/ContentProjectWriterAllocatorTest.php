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
    public function test_max_execution_project_items_is_centralized_at_30(): void
    {
        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
        self::assertSame(
            ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
            ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
        );
    }

    public function test_62_items_three_users_fair_21_21_20(): void
    {
        $result = ContentProjectWriterAllocator::allocate(range(1, 62), [11, 22, 33]);

        self::assertSame(0, $result['unallocated_count']);
        self::assertSame([21, 21, 20], array_column($result['allocations'], 'item_count'));
        self::assertSame([11, 22, 33], array_column($result['allocations'], 'user_id'));
        self::assertSame([1, 1, 1], array_column($result['allocations'], 'project_count'));
    }

    public function test_64_items_three_users_fair_22_21_21(): void
    {
        $result = ContentProjectWriterAllocator::allocate(range(1, 64), [1, 2, 3]);

        self::assertSame([22, 21, 21], array_column($result['allocations'], 'item_count'));
    }

    public function test_62_items_five_users_fair_13_13_12_12_12(): void
    {
        $result = ContentProjectWriterAllocator::allocate(range(1, 62), [1, 2, 3, 4, 5]);

        self::assertSame([13, 13, 12, 12, 12], array_column($result['allocations'], 'item_count'));
    }

    public function test_fair_counts_helper_is_deterministic(): void
    {
        self::assertSame([21, 21, 20], ContentProjectWriterAllocator::fairCounts(62, 3));
        self::assertSame([22, 21, 21], ContentProjectWriterAllocator::fairCounts(64, 3));
        self::assertSame([13, 13, 12, 12, 12], ContentProjectWriterAllocator::fairCounts(62, 5));
        self::assertSame([31, 31], ContentProjectWriterAllocator::fairCounts(62, 2));
    }

    public function test_one_user_can_exceed_30_and_is_chunked(): void
    {
        $result = ContentProjectWriterAllocator::allocate(range(1, 31), [7]);

        self::assertSame(31, $result['allocations'][0]['item_count']);
        self::assertSame(2, $result['allocations'][0]['project_count']);
        self::assertSame(
            [30, 1],
            array_map('count', $result['allocations'][0]['project_chunks']),
        );
    }

    public function test_chunk_61_is_30_30_1(): void
    {
        $chunks = ContentProjectWriterAllocator::chunkByMaxItems(range(1, 61));

        self::assertSame([30, 30, 1], array_map('count', $chunks));
    }

    public function test_two_users_62_each_get_31_chunked_to_30_1(): void
    {
        $result = ContentProjectWriterAllocator::allocate(range(1, 62), [8, 9]);

        self::assertSame([31, 31], array_column($result['allocations'], 'item_count'));
        self::assertSame([2, 2], array_column($result['allocations'], 'project_count'));
        self::assertSame(
            [30, 1],
            array_map('count', $result['allocations'][0]['project_chunks']),
        );
        self::assertSame(
            [30, 1],
            array_map('count', $result['allocations'][1]['project_chunks']),
        );
    }

    public function test_empty_selection_allocates_nothing(): void
    {
        $result = ContentProjectWriterAllocator::allocate(range(1, 10), []);

        self::assertSame([], $result['allocations']);
        self::assertSame(10, $result['unallocated_count']);
    }

    public function test_workload_service_is_display_only_not_hard_cap(): void
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
        self::assertStringNotContainsString('remainingByUserId', $src);
        self::assertStringNotContainsString("'full'", $src);
        self::assertStringNotContainsString('auth()->id()', $src);
    }

    public function test_lang_uses_month_total_not_hard_cap_fraction(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');

        self::assertStringContainsString("'draft_split_existing' => ':count existing'", $en);
        self::assertStringContainsString("'draft_split_result' => '→ :count this month'", $en);
        self::assertStringContainsString("'draft_split_projects_hint'", $en);
        self::assertStringContainsString("'draft_split_existing' => ':count hiện có'", $vi);
        self::assertStringContainsString("'draft_split_result' => '→ :count tháng này'", $vi);
        self::assertStringContainsString("'draft_split_projects_hint'", $vi);
    }
}
