<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleTimeseriesTest extends TestCase
{
    public function test_normalize_date_timeseries_sorts_and_formats_rows(): void
    {
        $service = new GoogleSearchConsoleSyncService();
        $rows = $service->normalizeDateTimeseries([
            [
                'keys' => ['2026-06-02'],
                'clicks' => 4,
                'impressions' => 40,
                'ctr' => 0.1,
                'position' => 8.2,
            ],
            [
                'keys' => ['2026-06-01'],
                'clicks' => 2,
                'impressions' => 20,
                'ctr' => 0.05,
                'position' => 9.1,
            ],
        ]);

        $this->assertSame('2026-06-01', $rows[0]['date']);
        $this->assertSame(2, $rows[0]['clicks']);
        $this->assertSame(5.0, $rows[0]['ctr']);
        $this->assertSame(9.1, $rows[0]['position']);
        $this->assertSame('2026-06-02', $rows[1]['date']);
        $this->assertSame(8.2, $rows[1]['position']);
    }

    public function test_resolve_sync_period_returns_adjacent_current_and_previous_ranges(): void
    {
        $service = new GoogleSearchConsoleSyncService();
        $period = $service->resolveSyncPeriod();

        $this->assertArrayHasKey('current_start', $period);
        $this->assertArrayHasKey('current_end', $period);
        $this->assertArrayHasKey('previous_start', $period);
        $this->assertArrayHasKey('previous_end', $period);
        $this->assertLessThan($period['current_start'], $period['previous_end']);
    }
}
