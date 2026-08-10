<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscProjectItemPerformanceState;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscProjectItemPerformanceDeriver;
use PHPUnit\Framework\TestCase;

final class GscContentProjectPerformanceTest extends TestCase
{
    private GscProjectItemPerformanceDeriver $deriver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deriver = new GscProjectItemPerformanceDeriver(new GscPerformanceAggregationService);
    }

    public function test_not_published_when_unpublished(): void
    {
        $state = $this->deriver->derive(
            ['clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 5.0],
            [],
            ['published' => false],
        );

        self::assertSame(GscProjectItemPerformanceState::NotPublished, $state);
    }

    public function test_awaiting_data_when_zero_impressions(): void
    {
        $state = $this->deriver->derive(['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null]);
        self::assertSame(GscProjectItemPerformanceState::AwaitingData, $state);
    }

    public function test_new_when_baseline_zero(): void
    {
        $state = $this->deriver->derive(
            ['clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 8.0],
            ['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null],
        );

        self::assertSame(GscProjectItemPerformanceState::New, $state);
    }

    public function test_decaying_when_clicks_drop(): void
    {
        $state = $this->deriver->derive(
            ['clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 10.0],
            ['clicks' => 20, 'impressions' => 200, 'ctr' => 0.1, 'position' => 8.0],
        );

        self::assertSame(GscProjectItemPerformanceState::Decaying, $state);
    }

    public function test_winning_when_strong_position_and_ctr(): void
    {
        $state = $this->deriver->derive(
            ['clicks' => 20, 'impressions' => 200, 'ctr' => 0.1, 'position' => 3.5],
            ['clicks' => 20, 'impressions' => 200, 'ctr' => 0.1, 'position' => 3.5],
        );

        self::assertSame(GscProjectItemPerformanceState::Winning, $state);
    }
}
