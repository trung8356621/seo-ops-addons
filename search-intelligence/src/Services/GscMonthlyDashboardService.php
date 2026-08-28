<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscDailyMetric;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMonthlyPeriod;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/** Monthly GSC dashboard — aggregates persisted daily facts for a calendar month. */
final class GscMonthlyDashboardService
{
    public function __construct(
        private readonly GscPerformanceAggregationService $aggregation,
    ) {}

    /**
     * @return array{
     *     period_key: string,
     *     period_label: string,
     *     period_start: string,
     *     period_end: string,
     *     has_data: bool,
     *     has_property: bool,
     *     last_synced_at: ?string,
     *     kpis: array<string, mixed>,
     *     queries: list<array<string, mixed>>,
     *     chart: array<string, mixed>,
     *     month_options: list<array{key: string, label: string, has_data: bool}>,
     * }
     */
    public function buildState(
        ?int $siteId,
        string $periodKey,
        string $chartMetric = 'clicks',
    ): array {
        $periodKey = GscMonthlyPeriod::normalize($periodKey);
        [$periodStart, $periodEnd] = GscMonthlyPeriod::bounds($periodKey);

        if ($siteId === null || $siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return $this->emptyState($periodKey, $periodStart, $periodEnd, false);
        }

        $property = $this->resolveProperty($siteId);
        if ($property === null) {
            return $this->emptyState($periodKey, $periodStart, $periodEnd, false);
        }

        $rows = $this->factsInRange((int) $property->id, $periodStart, $periodEnd);
        $hasData = $rows !== [];
        $monthOptions = GscMonthlyPeriod::mergeOptions($this->monthsWithData((int) $property->id));

        if (! $hasData) {
            return array_merge($this->emptyState($periodKey, $periodStart, $periodEnd, true), [
                'month_options' => $monthOptions,
                'last_synced_at' => $this->resolveLastSyncedAt($property, $periodStart, $periodEnd),
            ]);
        }

        $totals = $this->aggregation->aggregate($rows);
        $queries = $this->aggregateQueries($rows);
        $chart = $this->buildChart($rows, $periodStart, $periodEnd, $chartMetric);

        return [
            'period_key' => $periodKey,
            'period_label' => GscMonthlyPeriod::label($periodKey),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'has_data' => true,
            'has_property' => true,
            'last_synced_at' => $this->resolveLastSyncedAt($property, $periodStart, $periodEnd),
            'kpis' => [
                'total_clicks' => (int) ($totals['clicks'] ?? 0),
                'total_impressions' => (int) ($totals['impressions'] ?? 0),
                'avg_ctr' => isset($totals['ctr']) ? round((float) $totals['ctr'] * 100, 2) : 0.0,
                'avg_position' => isset($totals['position']) ? round((float) $totals['position'], 1) : null,
                'total_queries' => count($queries),
                'has_data' => true,
            ],
            'queries' => $queries,
            'chart' => $chart,
            'month_options' => $monthOptions,
        ];
    }

    /**
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>
     */
    public function aggregateQueries(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $query = trim((string) ($row['normalized_query'] ?? $row['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $grouped[$query][] = $row;
        }

        $queries = [];
        foreach ($grouped as $query => $groupRows) {
            $agg = $this->aggregation->aggregate($groupRows);
            $queries[] = [
                'query' => $query,
                'clicks' => (int) ($agg['clicks'] ?? 0),
                'impressions' => (int) ($agg['impressions'] ?? 0),
                'ctr' => isset($agg['ctr']) ? round((float) $agg['ctr'] * 100, 2) : 0.0,
                'position' => isset($agg['position']) ? round((float) $agg['position'], 1) : null,
            ];
        }

        usort($queries, static fn (array $a, array $b): int => ($b['impressions'] <=> $a['impressions']) ?: ($b['clicks'] <=> $a['clicks']));

        return $queries;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function buildChart(array $rows, string $periodStart, string $periodEnd, string $metric): array
    {
        $allowedMetrics = ['clicks', 'impressions', 'ctr', 'position'];
        if (! in_array($metric, $allowedMetrics, true)) {
            $metric = 'clicks';
        }

        $byDate = [];
        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $byDate[$date][] = $row;
        }

        $labels = [];
        $values = [];
        $cursor = new \DateTimeImmutable($periodStart);
        $end = new \DateTimeImmutable($periodEnd);

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $labels[] = $date;
            $dayRows = $byDate[$date] ?? [];
            if ($dayRows === []) {
                $values[] = 0;
            } else {
                $agg = $this->aggregation->aggregate($dayRows);
                $values[] = match ($metric) {
                    'clicks' => (int) ($agg['clicks'] ?? 0),
                    'impressions' => (int) ($agg['impressions'] ?? 0),
                    'ctr' => isset($agg['ctr']) ? round((float) $agg['ctr'] * 100, 2) : 0.0,
                    'position' => isset($agg['position']) ? round((float) $agg['position'], 1) : 0.0,
                    default => 0,
                };
            }
            $cursor = $cursor->modify('+1 day');
        }

        $hasAnyDay = $byDate !== [];

        return [
            'has_data' => $hasAnyDay,
            'status' => $hasAnyDay ? 'ok' : 'empty',
            'mode' => 'monthly',
            'labels' => $labels,
            'current' => $values,
            'previous' => [],
            'metric' => $metric,
            'is_lower_better' => $metric === 'position',
            'current_label' => GscMonthlyPeriod::label(substr($periodStart, 0, 7)),
            'previous_label' => '',
            'period_days' => count($labels),
            'current_start' => $periodStart,
            'current_end' => $periodEnd,
        ];
    }

    /**
     * @return list<string>
     */
    public function monthsWithData(int $propertyId): array
    {
        if ($propertyId <= 0) {
            return [];
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_daily_metrics')) {
                return [];
            }

            return SeoGscDailyMetric::query()
                ->where('property_id', $propertyId)
                ->selectRaw('DATE_FORMAT(metric_date, "%Y-%m") as month_key')
                ->groupBy('month_key')
                ->orderByDesc('month_key')
                ->pluck('month_key')
                ->map(static fn (mixed $key): string => (string) $key)
                ->filter(static fn (string $key): bool => preg_match('/^\d{4}-\d{2}$/', $key) === 1)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveProperty(int $siteId): ?SeoGscProperty
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_properties')) {
                return null;
            }

            return SeoGscProperty::query()
                ->where('site_id', $siteId)
                ->whereNull('archived_at')
                ->where('status', '!=', GscPropertyStatus::Archived->value)
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function factsInRange(int $propertyId, string $from, string $to): array
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_daily_metrics')) {
                return [];
            }

            return SeoGscDailyMetric::query()
                ->where('property_id', $propertyId)
                ->whereBetween('metric_date', [$from, $to])
                ->get()
                ->map(static function (SeoGscDailyMetric $m): array {
                    return [
                        'date' => $m->metric_date?->format('Y-m-d') ?? (string) $m->metric_date,
                        'query' => (string) ($m->query ?? ''),
                        'normalized_query' => (string) ($m->normalized_query ?? $m->query ?? ''),
                        'page' => (string) ($m->page ?? ''),
                        'normalized_page' => (string) ($m->normalized_page ?? $m->page ?? ''),
                        'clicks' => (int) $m->clicks,
                        'impressions' => (int) $m->impressions,
                        'ctr' => (float) $m->ctr,
                        'position' => $m->position !== null ? (float) $m->position : null,
                    ];
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveLastSyncedAt(SeoGscProperty $property, string $from, string $to): ?string
    {
        if ($property->last_synced_at !== null) {
            return $property->last_synced_at->toIso8601String();
        }

        try {
            $maxDate = SeoGscDailyMetric::query()
                ->where('property_id', (int) $property->id)
                ->whereBetween('metric_date', [$from, $to])
                ->max('updated_at');

            if ($maxDate !== null) {
                return (string) $maxDate;
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(string $periodKey, string $periodStart, string $periodEnd, bool $hasProperty): array
    {
        return [
            'period_key' => $periodKey,
            'period_label' => GscMonthlyPeriod::label($periodKey),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'has_data' => false,
            'has_property' => $hasProperty,
            'last_synced_at' => null,
            'kpis' => [
                'total_clicks' => 0,
                'total_impressions' => 0,
                'avg_ctr' => 0.0,
                'avg_position' => null,
                'total_queries' => 0,
                'has_data' => false,
            ],
            'queries' => [],
            'chart' => [
                'has_data' => false,
                'status' => 'empty',
                'mode' => 'monthly',
                'labels' => [],
                'current' => [],
                'previous' => [],
                'metric' => 'clicks',
                'is_lower_better' => false,
                'current_label' => GscMonthlyPeriod::label($periodKey),
                'previous_label' => '',
                'period_days' => 0,
                'current_start' => $periodStart,
                'current_end' => $periodEnd,
            ],
            'month_options' => GscMonthlyPeriod::mergeOptions([]),
        ];
    }
}
