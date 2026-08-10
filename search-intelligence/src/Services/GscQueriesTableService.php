<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Illuminate\Pagination\LengthAwarePaginator;

final class GscQueriesTableService
{
    /** @var array<string, array{min: int, max: int, distribution_key: string}> */
    public const POSITION_BUCKETS = [
        '1-3' => ['min' => 1, 'max' => 3, 'distribution_key' => 'top_3'],
        '4-10' => ['min' => 4, 'max' => 10, 'distribution_key' => 'top_4_10'],
        '11-20' => ['min' => 11, 'max' => 20, 'distribution_key' => 'top_11_20'],
        '21-50' => ['min' => 21, 'max' => 50, 'distribution_key' => 'top_21_50'],
        '51-100' => ['min' => 51, 'max' => 100, 'distribution_key' => 'top_51_100'],
    ];

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    /**
     * @param  list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $queries
     * @return array<string, int>
     */
    public function distributionFromQueries(array $queries): array
    {
        $distribution = [
            'top_3' => 0,
            'top_4_10' => 0,
            'top_11_20' => 0,
            'top_21_50' => 0,
            'top_51_100' => 0,
        ];

        foreach ($queries as $row) {
            $bucket = $this->bucketKeyForPosition($row['position'] ?? null);
            if ($bucket === null) {
                continue;
            }

            $distribution[self::POSITION_BUCKETS[$bucket]['distribution_key']]++;
        }

        return $distribution;
    }

    public function normalizePositionBucket(?string $bucket): ?string
    {
        $bucket = trim((string) $bucket);

        return array_key_exists($bucket, self::POSITION_BUCKETS) ? $bucket : null;
    }

    public function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    public function bucketKeyForPosition(mixed $position): ?string
    {
        if (! is_numeric($position)) {
            return null;
        }

        $rounded = (int) round((float) $position);
        if ($rounded < 1 || $rounded > 100) {
            return null;
        }

        foreach (self::POSITION_BUCKETS as $bucketKey => $bounds) {
            if ($rounded >= $bounds['min'] && $rounded <= $bounds['max']) {
                return $bucketKey;
            }
        }

        return null;
    }

    public function matchesPositionBucket(mixed $position, ?string $bucket): bool
    {
        $bucket = $this->normalizePositionBucket($bucket);
        if ($bucket === null) {
            return true;
        }

        return $this->bucketKeyForPosition($position) === $bucket;
    }

    /**
     * @param  list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $queries
     * @return array{
     *     rows: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>,
     *     pagination: array<string, mixed>,
     *     total_filtered: int,
     *     total_source: int,
     * }
     */
    public function buildTableState(
        array $queries,
        string $search = '',
        ?string $positionBucket = null,
        string $sortBy = 'impressions',
        string $sortDir = 'desc',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): array {
        $positionBucket = $this->normalizePositionBucket($positionBucket);
        $perPage = $this->normalizePerPage($perPage);
        $page = max(1, $page);

        $filtered = $this->filterQueries($queries, $search, $positionBucket);
        $sorted = $this->sortQueries($filtered, $sortBy, $sortDir);
        $totalFiltered = count($sorted);
        $lastPage = max(1, (int) ceil($totalFiltered / $perPage));

        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($sorted, $offset, $perPage);

        $paginator = new LengthAwarePaginator(
            items: $pageRows,
            total: $totalFiltered,
            perPage: $perPage,
            currentPage: $page,
        );

        return [
            'rows' => $pageRows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalFiltered,
                'last_page' => $lastPage,
                'from' => $totalFiltered > 0 ? $offset + 1 : 0,
                'to' => $totalFiltered > 0 ? min($offset + $perPage, $totalFiltered) : 0,
                'page_numbers' => $this->buildPageNumbers($page, $lastPage),
            ],
            'total_filtered' => $totalFiltered,
            'total_source' => count($queries),
        ];
    }

    /**
     * @param  list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $queries
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>
     */
    private function filterQueries(array $queries, string $search, ?string $positionBucket): array
    {
        $needle = mb_strtolower(trim($search));

        return array_values(array_filter(
            $queries,
            static function (array $row) use ($needle, $positionBucket): bool {
                if ($needle !== '') {
                    $query = mb_strtolower((string) ($row['query'] ?? ''));
                    if (! str_contains($query, $needle)) {
                        return false;
                    }
                }

                return (new self())->matchesPositionBucket($row['position'] ?? null, $positionBucket);
            },
        ));
    }

    /**
     * @param  list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $queries
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>
     */
    private function sortQueries(array $queries, string $sortBy, string $sortDir): array
    {
        $allowedSort = ['query', 'clicks', 'impressions', 'ctr', 'position'];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'impressions';
        }

        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        usort($queries, static function (array $left, array $right) use ($sortBy, $sortDir): int {
            $leftValue = $left[$sortBy] ?? '';
            $rightValue = $right[$sortBy] ?? '';

            if (is_numeric($leftValue) && is_numeric($rightValue)) {
                $comparison = (float) $leftValue <=> (float) $rightValue;
            } else {
                $comparison = strcasecmp((string) $leftValue, (string) $rightValue);
            }

            return $sortDir === 'asc' ? $comparison : -$comparison;
        });

        return $queries;
    }

    /**
     * @return list<int|string>
     */
    private function buildPageNumbers(int $current, int $last): array
    {
        if ($last <= 7) {
            return range(1, $last);
        }

        $pages = [1];

        if ($current > 3) {
            $pages[] = '...';
        }

        $start = max(2, $current - 1);
        $end = min($last - 1, $current + 1);

        for ($page = $start; $page <= $end; $page++) {
            $pages[] = $page;
        }

        if ($current < $last - 2) {
            $pages[] = '...';
        }

        $pages[] = $last;

        return $pages;
    }
}
