<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoPerformanceDashboardService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoPerformanceHubService;
use Tests\TestCase;

final class SeoPerformanceHubServiceTest extends TestCase
{
    public function test_quick_wins_returns_empty_without_gsc_data(): void
    {
        $service = new SeoPerformanceHubService(
            new KeywordPersistenceService(new KeywordMetaRepository()),
        );

        $rows = $service->getQuickWinQueries(null);

        $this->assertSame([], $rows);
    }

    public function test_gsc_query_distribution_returns_zero_buckets_without_data(): void
    {
        $service = new SeoPerformanceHubService(
            new KeywordPersistenceService(new KeywordMetaRepository()),
        );

        $distribution = $service->getGscQueryDistribution(null);

        $this->assertSame([
            'top_3' => 0,
            'top_4_10' => 0,
            'top_11_20' => 0,
            'top_21_50' => 0,
            'top_51_100' => 0,
        ], $distribution);
    }

    public function test_gsc_performance_chart_empty_without_snapshot(): void
    {
        $service = new SeoPerformanceHubService(
            new KeywordPersistenceService(new KeywordMetaRepository()),
        );

        $chart = $service->getGscPerformanceChart(null, 'clicks');

        $this->assertFalse($chart['has_data']);
        $this->assertSame('empty', $chart['status']);
    }

    public function test_gsc_performance_chart_position_metric_is_lower_better(): void
    {
        $service = new SeoPerformanceHubService(
            new KeywordPersistenceService(new KeywordMetaRepository()),
        );

        $chart = $service->getGscPerformanceChart(null, 'position');
        $this->assertTrue($chart['is_lower_better']);
    }
}
