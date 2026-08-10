<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscQueriesTableService;
use PHPUnit\Framework\TestCase;

final class GscQueriesTableServiceTest extends TestCase
{
    private GscQueriesTableService $service;

    /** @var list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}> */
    private array $queries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new GscQueriesTableService();
        $this->queries = [
            ['query' => 'alpha', 'clicks' => 1, 'impressions' => 10, 'ctr' => 10.0, 'position' => 1.0],
            ['query' => 'beta', 'clicks' => 2, 'impressions' => 20, 'ctr' => 10.0, 'position' => 3.4],
            ['query' => 'gamma', 'clicks' => 3, 'impressions' => 30, 'ctr' => 10.0, 'position' => 4.0],
            ['query' => 'delta', 'clicks' => 4, 'impressions' => 40, 'ctr' => 10.0, 'position' => 10.0],
            ['query' => 'epsilon', 'clicks' => 5, 'impressions' => 50, 'ctr' => 10.0, 'position' => 11.0],
            ['query' => 'zeta', 'clicks' => 6, 'impressions' => 60, 'ctr' => 10.0, 'position' => 20.0],
            ['query' => 'eta', 'clicks' => 7, 'impressions' => 70, 'ctr' => 10.0, 'position' => 21.0],
            ['query' => 'theta', 'clicks' => 8, 'impressions' => 80, 'ctr' => 10.0, 'position' => 50.0],
            ['query' => 'iota', 'clicks' => 9, 'impressions' => 90, 'ctr' => 10.0, 'position' => 51.0],
            ['query' => 'kappa', 'clicks' => 10, 'impressions' => 100, 'ctr' => 10.0, 'position' => 100.0],
            ['query' => 'null pos', 'clicks' => 0, 'impressions' => 5, 'ctr' => 0.0, 'position' => null],
            ['query' => 'out of range', 'clicks' => 0, 'impressions' => 5, 'ctr' => 0.0, 'position' => 101.0],
        ];
    }

    public function test_bucket_boundaries_use_rounded_positions(): void
    {
        $this->assertSame('1-3', $this->service->bucketKeyForPosition(1.0));
        $this->assertSame('1-3', $this->service->bucketKeyForPosition(3.4));
        $this->assertSame('4-10', $this->service->bucketKeyForPosition(3.6));
        $this->assertSame('4-10', $this->service->bucketKeyForPosition(10.0));
        $this->assertSame('11-20', $this->service->bucketKeyForPosition(11.0));
        $this->assertSame('21-50', $this->service->bucketKeyForPosition(50.0));
        $this->assertSame('51-100', $this->service->bucketKeyForPosition(100.0));
        $this->assertNull($this->service->bucketKeyForPosition(null));
        $this->assertNull($this->service->bucketKeyForPosition(101.0));
    }

    public function test_distribution_counts_full_dataset_not_filtered_page(): void
    {
        $distribution = $this->service->distributionFromQueries($this->queries);

        $this->assertSame(2, $distribution['top_3']);
        $this->assertSame(2, $distribution['top_4_10']);
        $this->assertSame(2, $distribution['top_11_20']);
        $this->assertSame(2, $distribution['top_21_50']);
        $this->assertSame(2, $distribution['top_51_100']);
    }

    public function test_position_bucket_filter_applies_before_pagination(): void
    {
        $state = $this->service->buildTableState(
            queries: $this->queries,
            positionBucket: '11-20',
            page: 1,
            perPage: 10,
        );

        $this->assertSame(2, $state['total_filtered']);
        $this->assertCount(2, $state['rows']);
        $this->assertSame('zeta', $state['rows'][0]['query']);
        $this->assertSame('epsilon', $state['rows'][1]['query']);
    }

    public function test_invalid_page_falls_back_to_last_page(): void
    {
        $state = $this->service->buildTableState(
            queries: $this->queries,
            page: 99,
            perPage: 10,
        );

        $this->assertSame(2, $state['pagination']['current_page']);
    }

    public function test_invalid_per_page_falls_back_to_default(): void
    {
        $this->assertSame(25, $this->service->normalizePerPage(999));
    }

    public function test_search_sort_filter_paginate_order(): void
    {
        $state = $this->service->buildTableState(
            queries: $this->queries,
            search: 'theta',
            sortBy: 'clicks',
            sortDir: 'desc',
            page: 1,
            perPage: 25,
        );

        $this->assertSame(1, $state['total_filtered']);
        $this->assertSame('theta', $state['rows'][0]['query']);
    }
}
