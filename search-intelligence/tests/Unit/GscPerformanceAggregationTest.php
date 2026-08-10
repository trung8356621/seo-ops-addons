<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use PHPUnit\Framework\TestCase;

final class GscPerformanceAggregationTest extends TestCase
{
    private GscPerformanceAggregationService $agg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agg = new GscPerformanceAggregationService;
    }

    public function test_ctr_is_total_clicks_over_total_impressions(): void
    {
        $result = $this->agg->aggregate([
            ['clicks' => 3, 'impressions' => 30, 'position' => 4.0],
            ['clicks' => 7, 'impressions' => 70, 'position' => 8.0],
        ]);

        self::assertSame(10, $result['clicks']);
        self::assertSame(100, $result['impressions']);
        self::assertSame(0.1, $result['ctr']);
    }

    public function test_position_is_impression_weighted(): void
    {
        $result = $this->agg->aggregate([
            ['clicks' => 1, 'impressions' => 10, 'position' => 4.0],
            ['clicks' => 1, 'impressions' => 90, 'position' => 8.0],
        ]);

        self::assertSame(7.6, $result['position']);
    }

    public function test_zero_impressions_yields_null_ctr_and_position(): void
    {
        $result = $this->agg->aggregate([
            ['clicks' => 0, 'impressions' => 0, 'position' => 0],
        ]);

        self::assertNull($result['ctr']);
        self::assertNull($result['position']);
    }

    public function test_baseline_zero_flag_in_compare_periods(): void
    {
        $current = $this->agg->aggregate([['clicks' => 5, 'impressions' => 50, 'position' => 6.0]]);
        $baseline = $this->agg->aggregate([]);

        $comparison = $this->agg->comparePeriods($current, $baseline);
        self::assertTrue($comparison['baseline_zero']);
        self::assertNull($comparison['impressions_growth_pct']);
    }
}
