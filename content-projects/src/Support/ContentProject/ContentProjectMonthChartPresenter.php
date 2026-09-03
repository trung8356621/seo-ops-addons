<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Compact list-page chart presentation over {@see ContentProjectMonthlyWorkloadService::forMonth()}.
 * Aggregation stays in the service — this class only shapes UI totals, shares, and donut CSS.
 */
final class ContentProjectMonthChartPresenter
{
    public const DOMAIN_VISIBLE = 5;

    public const WRITER_VISIBLE = 6;

    /** Soft accents for ranked domain segments (#1 uses primary emerald). */
    private const DOMAIN_COLORS = [
        '#10b981', // emerald-500 — primary
        '#3b82f6', // blue-500
        '#8b5cf6', // violet-500
        '#f59e0b', // amber-500
        '#06b6d4', // cyan-500
        '#64748b', // slate-500 — overflow / more
    ];

    /**
     * @param  array{
     *     month?: string,
     *     month_label?: string,
     *     by_domain?: list<array{site_id?: int, domain?: string, active_count?: int, archived_count?: int, total_count?: int}>,
     *     domain_empty?: bool,
     *     domain_max?: int
     * }  $workload
     * @return array{
     *     month: string,
     *     month_label: string,
     *     total: int,
     *     empty: bool,
     *     max: int,
     *     donut_gradient: string,
     *     rows: list<array{
     *         site_id: int,
     *         domain: string,
     *         active_count: int,
     *         archived_count: int,
     *         total_count: int,
     *         count: int,
     *         share_pct: float,
     *         share_pct_label: int,
     *         color: string,
     *         rank: int
     *     }>,
     *     visible_rows: list<array<string, mixed>>,
     *     more_count: int
     * }
     */
    public function presentDomain(array $workload): array
    {
        $rawRows = is_array($workload['by_domain'] ?? null) ? $workload['by_domain'] : [];
        $total = 0;
        foreach ($rawRows as $row) {
            $total += max(0, (int) ($row['total_count'] ?? 0));
        }

        $rows = [];
        $rank = 0;
        foreach ($rawRows as $row) {
            $count = max(0, (int) ($row['total_count'] ?? 0));
            $rank++;
            $share = self::sharePercent($count, $total);
            $rows[] = [
                'site_id' => (int) ($row['site_id'] ?? 0),
                'domain' => (string) ($row['domain'] ?? ''),
                'active_count' => max(0, (int) ($row['active_count'] ?? 0)),
                'archived_count' => max(0, (int) ($row['archived_count'] ?? 0)),
                'total_count' => $count,
                'count' => $count,
                'share_pct' => $share,
                'share_pct_label' => (int) round($share),
                'color' => self::DOMAIN_COLORS[min($rank - 1, count(self::DOMAIN_COLORS) - 1)],
                'rank' => $rank,
            ];
        }

        $nonZeroRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row['total_count'] ?? 0) > 0,
        ));
        $donutRows = array_slice($nonZeroRows, 0, self::DOMAIN_VISIBLE);
        $visible = $rows;
        $moreCount = max(0, count($nonZeroRows) - count($donutRows));

        return [
            'month' => (string) ($workload['month'] ?? ''),
            'month_label' => (string) ($workload['month_label'] ?? ''),
            'total' => $total,
            'empty' => (bool) ($workload['domain_empty'] ?? $rows === []),
            'max' => max(1, (int) ($workload['domain_max'] ?? 1)),
            'donut_gradient' => self::conicGradientFromRows($donutRows, $total, $moreCount, $nonZeroRows),
            'rows' => $rows,
            'visible_rows' => $visible,
            'more_count' => $moreCount,
        ];
    }

    /**
     * @param  array{
     *     month?: string,
     *     month_label?: string,
     *     default_capacity?: int,
     *     team_capacity?: int,
     *     by_writer?: list<array{user_id?: int, name?: string, active_count?: int, archived_count?: int, total_count?: int, capacity?: int, remaining?: int}>,
     *     writer_empty?: bool,
     *     writer_max?: int
     * }  $workload
     * @return array{
     *     month: string,
     *     month_label: string,
     *     default_capacity: int,
     *     total: int,
     *     writer_count: int,
     *     team_capacity: int,
     *     overall_progress_pct: int,
     *     empty: bool,
     *     max: int,
     *     donut_gradient: string,
     *     rows: list<array{
     *         user_id: int,
     *         name: string,
     *         initials: string,
     *         active_count: int,
     *         archived_count: int,
     *         total_count: int,
     *         count: int,
     *         capacity: int,
     *         remaining: int,
     *         progress_pct: int,
     *         capacity_zero: bool,
     *         over_capacity: bool,
     *         rank: int
     *     }>,
     *     visible_rows: list<array<string, mixed>>,
     *     more_count: int
     * }
     */
    public function presentWriter(array $workload): array
    {
        $defaultCapacity = max(0, (int) ($workload['default_capacity'] ?? 0));
        $rawRows = is_array($workload['by_writer'] ?? null) ? $workload['by_writer'] : [];

        $rows = [];
        $assigned = 0;
        $rowsCapacitySum = 0;
        $rank = 0;
        foreach ($rawRows as $row) {
            $count = max(0, (int) ($row['total_count'] ?? 0));
            if ($count <= 0) {
                continue;
            }
            $rank++;
            $rowCapacity = max(0, (int) ($row['capacity'] ?? $defaultCapacity));
            $name = (string) ($row['name'] ?? '');
            $assigned += $count;
            $rowsCapacitySum += $rowCapacity;
            $capacityZero = $rowCapacity === 0;
            $over = $capacityZero ? $count > 0 : $count > $rowCapacity;
            $progressPct = $capacityZero
                ? ($count > 0 ? 100 : 0)
                : self::percent($count, $rowCapacity);
            $rows[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'name' => $name,
                'initials' => self::initials($name),
                'active_count' => max(0, (int) ($row['active_count'] ?? 0)),
                'archived_count' => max(0, (int) ($row['archived_count'] ?? 0)),
                'total_count' => $count,
                'count' => $count,
                'capacity' => $rowCapacity,
                'remaining' => (int) ($row['remaining'] ?? ($rowCapacity - $count)),
                'progress_pct' => $progressPct,
                'capacity_zero' => $capacityZero,
                'over_capacity' => $over,
                'rank' => $rank,
            ];
        }

        $writerCount = count($rows);
        $teamCapacity = isset($workload['team_capacity'])
            ? max(0, (int) $workload['team_capacity'])
            : $rowsCapacitySum;
        $overallPct = self::percent($assigned, $teamCapacity);
        $visible = array_slice($rows, 0, self::WRITER_VISIBLE);
        $moreCount = max(0, count($rows) - count($visible));

        return [
            'month' => (string) ($workload['month'] ?? ''),
            'month_label' => (string) ($workload['month_label'] ?? ''),
            'default_capacity' => $defaultCapacity,
            'total' => $assigned,
            'writer_count' => $writerCount,
            'team_capacity' => $teamCapacity,
            'overall_progress_pct' => $overallPct,
            'empty' => (bool) ($workload['writer_empty'] ?? $rows === []),
            'max' => max(1, (int) ($workload['writer_max'] ?? max($defaultCapacity, 1))),
            'donut_gradient' => self::progressConicGradient($overallPct),
            'rows' => $rows,
            'visible_rows' => $visible,
            'more_count' => $moreCount,
        ];
    }

    public static function percent(int $part, int $whole): int
    {
        if ($whole <= 0) {
            return 0;
        }

        return (int) round(($part / $whole) * 100);
    }

    public static function sharePercent(int $part, int $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return round(($part / $whole) * 1000) / 10;
    }

    public static function initials(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return '?';
        }

        $parts = explode(' ', $name);
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = mb_substr($parts[count($parts) - 1], 0, 1);

        return mb_strtoupper($first.$last);
    }

    /**
     * @param  list<array{count?: int, total_count?: int, color?: string}>  $visible
     * @param  list<array{count?: int, total_count?: int, color?: string}>  $allRows
     */
    private static function conicGradientFromRows(array $visible, int $total, int $moreCount, array $allRows): string
    {
        if ($total <= 0) {
            return 'conic-gradient(#e5e7eb 0% 100%)';
        }

        $parts = [];
        $cursor = 0.0;
        foreach ($visible as $row) {
            $count = max(0, (int) ($row['total_count'] ?? $row['count'] ?? 0));
            if ($count <= 0) {
                continue;
            }
            $pct = ($count / $total) * 100;
            $start = $cursor;
            $cursor += $pct;
            $color = (string) ($row['color'] ?? self::DOMAIN_COLORS[0]);
            $parts[] = $color.' '.$start.'% '.$cursor.'%';
        }

        if ($moreCount > 0) {
            $hidden = 0;
            foreach (array_slice($allRows, count($visible)) as $row) {
                $hidden += max(0, (int) ($row['total_count'] ?? $row['count'] ?? 0));
            }
            if ($hidden > 0) {
                $pct = ($hidden / $total) * 100;
                $start = $cursor;
                $cursor += $pct;
                $parts[] = self::DOMAIN_COLORS[count(self::DOMAIN_COLORS) - 1].' '.$start.'% '.$cursor.'%';
            }
        }

        if ($parts === []) {
            return 'conic-gradient(#e5e7eb 0% 100%)';
        }

        return 'conic-gradient('.implode(', ', $parts).')';
    }

    private static function progressConicGradient(int $pct): string
    {
        $pct = max(0, min(100, $pct));
        $fill = '#10b981';
        $track = '#e5e7eb';

        if ($pct <= 0) {
            return 'conic-gradient('.$track.' 0% 100%)';
        }

        if ($pct >= 100) {
            return 'conic-gradient('.$fill.' 0% 100%)';
        }

        return 'conic-gradient('.$fill.' 0% '.$pct.'%, '.$track.' '.$pct.'% 100%)';
    }
}
