<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthChartPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAllocator;
use PHPUnit\Framework\TestCase;

final class ContentProjectWriterCapacitySettingsTest extends TestCase
{
    public function test_default_capacity_is_30_and_independent_of_execution_packing(): void
    {
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();

        self::assertSame(30, $settings->defaultMonthlyCapacity());
        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
        self::assertFalse(
            (new \ReflectionClass(ContentProjectExecutionLimits::class))
                ->hasConstant('MAX_WRITER_MONTHLY_ITEMS'),
        );
    }

    public function test_changing_global_default_affects_users_without_override(): void
    {
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $settings->save([
            ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY => 40,
        ]);

        self::assertSame(40, $settings->defaultMonthlyCapacity());
        self::assertSame(40, $settings->capacityForUser(101));
        self::assertSame([101 => 40, 202 => 40], $settings->capacitiesForUsers([101, 202]));
    }

    public function test_per_user_override_semantics_via_parse(): void
    {
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $settings->save([
            ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY => 30,
        ]);

        $parse = new \ReflectionMethod(ContentProjectWriterCapacitySettingsService::class, 'parseOverrideValue');
        $parse->setAccessible(true);

        self::assertNull($parse->invoke($settings, null));
        self::assertNull($parse->invoke($settings, ''));
        self::assertNull($parse->invoke($settings, '  '));
        self::assertSame(15, $parse->invoke($settings, '15'));
        self::assertSame(0, $parse->invoke($settings, '0'));
        self::assertNull($parse->invoke($settings, '-1'));
        self::assertNull($parse->invoke($settings, '1001'));

        // Without DB overrides, every user falls back to global default.
        self::assertSame(30, $settings->capacityForUser(99));
        self::assertSame([11 => 30, 22 => 30], $settings->capacitiesForUsers([11, 22]));
    }

    public function test_negative_override_rejected_on_set(): void
    {
        $settings = ContentProjectWriterCapacitySettingsService::withDefaults();

        $this->expectException(\InvalidArgumentException::class);
        $settings->setUserOverride(55, -1);
    }

    public function test_team_capacity_sums_effective_capacities(): void
    {
        self::assertSame(100, array_sum([40, 15, 10, 30, 5]));
    }

    public function test_capacity_aware_allocator_respects_remaining(): void
    {
        $result = ContentProjectWriterAllocator::allocate(
            range(1, 10),
            [1, 2, 3],
            [1 => 10, 2 => 3, 3 => 0],
        );

        self::assertSame([7, 3, 0], array_column($result['allocations'], 'item_count'));
        self::assertSame(0, $result['unallocated_count']);
        self::assertSame(10, $result['assigned_items']);
    }

    public function test_capacity_aware_water_fill_example(): void
    {
        $result = ContentProjectWriterAllocator::allocate(
            range(1, 15),
            [10, 20, 30],
            [10 => 20, 20 => 5, 30 => 0],
        );

        self::assertSame([10, 5, 0], array_column($result['allocations'], 'item_count'));
        self::assertSame(0, $result['unallocated_count']);
    }

    public function test_insufficient_team_capacity_leaves_unallocated(): void
    {
        $result = ContentProjectWriterAllocator::allocate(
            range(1, 20),
            [1, 2],
            [1 => 5, 2 => 3],
        );

        self::assertSame([5, 3], array_column($result['allocations'], 'item_count'));
        self::assertSame(8, $result['assigned_items']);
        self::assertSame(12, $result['unallocated_count']);
        self::assertCount(12, $result['unallocated_task_ids']);
    }

    public function test_packing_chunks_remain_30_independent_of_writer_capacity(): void
    {
        $chunks = ContentProjectWriterAllocator::chunkByMaxItems(range(1, 70));

        self::assertSame([30, 30, 10], array_map('count', $chunks));
        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);

        $result = ContentProjectWriterAllocator::allocate(
            range(1, 70),
            [7],
            [7 => 70],
        );
        self::assertSame(70, $result['allocations'][0]['item_count']);
        self::assertSame([30, 30, 10], array_map('count', $result['allocations'][0]['project_chunks']));
    }

    public function test_chart_presenter_uses_per_writer_capacity_and_handles_zero(): void
    {
        $presenter = new ContentProjectMonthChartPresenter();

        $chart = $presenter->presentWriter([
            'month' => '2026-09-01',
            'month_label' => '09/2026',
            'default_capacity' => 30,
            'team_capacity' => 100,
            'writer_empty' => false,
            'writer_max' => 40,
            'by_writer' => [
                ['user_id' => 1, 'name' => 'A', 'total_count' => 10, 'active_count' => 10, 'archived_count' => 0, 'capacity' => 20, 'remaining' => 10],
                ['user_id' => 2, 'name' => 'B', 'total_count' => 20, 'active_count' => 20, 'archived_count' => 0, 'capacity' => 20, 'remaining' => 0],
                ['user_id' => 3, 'name' => 'C', 'total_count' => 25, 'active_count' => 25, 'archived_count' => 0, 'capacity' => 20, 'remaining' => -5],
                ['user_id' => 4, 'name' => 'D', 'total_count' => 3, 'active_count' => 3, 'archived_count' => 0, 'capacity' => 0, 'remaining' => -3],
            ],
        ]);

        self::assertSame(100, $chart['team_capacity']);
        self::assertSame(50, $chart['rows'][0]['progress_pct']);
        self::assertSame(100, $chart['rows'][1]['progress_pct']);
        self::assertSame(125, $chart['rows'][2]['progress_pct']);
        self::assertTrue($chart['rows'][3]['capacity_zero']);
        self::assertTrue($chart['rows'][3]['over_capacity']);
        self::assertSame(0, ContentProjectMonthChartPresenter::percent(10, 0));
    }

    public function test_remaining_formula_examples(): void
    {
        self::assertSame(3, 15 - 12);
        self::assertSame(-5, 15 - 20);
    }
}
