<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityMaturity;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscExpectedCtrModel;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityDetectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use PHPUnit\Framework\TestCase;

final class GscOpportunityDetectionTest extends TestCase
{
    private GscOpportunityDetectionService $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new GscOpportunityDetectionService(
            new GscPerformanceAggregationService,
            new GscExpectedCtrModel,
        );
        $this->detector->resetFingerprints();
    }

    public function test_high_impression_low_ctr_detected(): void
    {
        $rows = [['clicks' => 5, 'impressions' => 500, 'ctr' => 0.01, 'position' => 8.0]];
        $opps = $this->detector->detect($rows, [], [
            'normalized_query' => 'dịch vụ seo',
            'first_seen_date' => date('Y-m-d', strtotime('-90 days')),
        ]);

        $types = array_column($opps, 'type');
        self::assertContains(GscOpportunityType::HighImpressionLowCtr->value, $types);
    }

    public function test_near_page_one_detected(): void
    {
        $rows = [['clicks' => 20, 'impressions' => 300, 'ctr' => 0.06, 'position' => 9.5]];
        $opps = $this->detector->detect($rows, [], [
            'normalized_query' => 'seo audit',
            'first_seen_date' => date('Y-m-d', strtotime('-90 days')),
        ]);

        $types = array_column($opps, 'type');
        self::assertContains(GscOpportunityType::NearPageOne->value, $types);
    }

    public function test_maturity_new_for_recent_first_seen(): void
    {
        $rows = [['clicks' => 5, 'impressions' => 500, 'ctr' => 0.01, 'position' => 8.0]];
        $opps = $this->detector->detect($rows, [], [
            'normalized_query' => 'dịch vụ seo',
            'first_seen_date' => date('Y-m-d'),
        ]);

        self::assertNotEmpty($opps);
        self::assertSame(GscOpportunityMaturity::New->value, $opps[0]['maturity']);
    }

    public function test_fingerprint_deduplicates_repeat_detection(): void
    {
        $rows = [['clicks' => 5, 'impressions' => 500, 'ctr' => 0.01, 'position' => 8.0]];
        $context = [
            'normalized_query' => 'dịch vụ seo',
            'first_seen_date' => date('Y-m-d', strtotime('-90 days')),
        ];

        $first = $this->detector->detect($rows, [], $context);
        $second = $this->detector->detect($rows, [], $context);

        self::assertNotEmpty($first);
        self::assertSame([], $second);
    }
}
