<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscMonthlyDashboardService;
use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMonthlyPeriod;
use PHPUnit\Framework\TestCase;

final class GscMonthlyDashboardTest extends TestCase
{
    public function test_period_bounds_use_calendar_month(): void
    {
        [$start, $end] = GscMonthlyPeriod::bounds('2026-08');

        self::assertSame('2026-08-01', $start);
        self::assertSame('2026-08-31', $end);
    }

    public function test_aggregate_queries_and_chart_from_daily_rows(): void
    {
        $service = new GscMonthlyDashboardService(new GscPerformanceAggregationService());

        $rows = [
            ['date' => '2026-07-01', 'normalized_query' => 'alpha', 'query' => 'alpha', 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 5.0],
            ['date' => '2026-07-02', 'normalized_query' => 'alpha', 'query' => 'alpha', 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 6.0],
            ['date' => '2026-07-01', 'normalized_query' => 'beta', 'query' => 'beta', 'clicks' => 1, 'impressions' => 20, 'ctr' => 0.05, 'position' => 12.0],
        ];

        $queries = $service->aggregateQueries($rows);
        self::assertCount(2, $queries);
        self::assertSame('alpha', $queries[0]['query']);
        self::assertSame(15, $queries[0]['clicks']);

        $chart = $service->buildChart($rows, '2026-07-01', '2026-07-02', 'clicks');
        self::assertSame('monthly', $chart['mode']);
        self::assertSame(['2026-07-01', '2026-07-02'], $chart['labels']);
        self::assertSame([11, 5], $chart['current']);
        self::assertSame([], $chart['previous']);
    }

    public function test_performance_hub_page_has_month_url_state(): void
    {
        $path = dirname(__DIR__, 2).'/src/Filament/Pages/SeoPerformanceHub.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("#[Url(as: 'month')]", $source);
        self::assertStringContainsString('GscMonthlyPeriod::normalize', $source);
        self::assertStringContainsString('openGscMcpDrawer', $source);
    }
}
