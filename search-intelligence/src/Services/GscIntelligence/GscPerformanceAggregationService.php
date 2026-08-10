<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

/**
 * Aggregate GSC metrics — CTR = sum(clicks)/sum(impressions); weighted position.
 */
final class GscPerformanceAggregationService
{
    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{clicks: int, impressions: int, ctr: ?float, position: ?float}
     */
    public function aggregate(array $rows): array
    {
        $clicks = 0;
        $impressions = 0;
        $positionWeightedSum = 0.0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowClicks = (int) ($row['clicks'] ?? 0);
            $rowImpressions = (int) ($row['impressions'] ?? 0);
            $clicks += $rowClicks;
            $impressions += $rowImpressions;

            if ($rowImpressions > 0 && isset($row['position']) && is_numeric($row['position'])) {
                $positionWeightedSum += ((float) $row['position']) * $rowImpressions;
            }
        }

        if ($impressions === 0) {
            return [
                'clicks' => $clicks,
                'impressions' => 0,
                'ctr' => null,
                'position' => null,
                'algorithm_version' => self::ALGORITHM_VERSION,
            ];
        }

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => round($clicks / $impressions, 6),
            'position' => round($positionWeightedSum / $impressions, 4),
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int, ctr: ?float, position: ?float}  $current
     * @param  array{clicks: int, impressions: int, ctr: ?float, position: ?float}  $baseline
     * @return array<string, mixed>
     */
    public function comparePeriods(array $current, array $baseline): array
    {
        $baselineZero = ((int) ($baseline['impressions'] ?? 0)) === 0;
        $currentImpressions = (int) ($current['impressions'] ?? 0);

        $clicksDelta = (int) ($current['clicks'] ?? 0) - (int) ($baseline['clicks'] ?? 0);
        $impressionsDelta = $currentImpressions - (int) ($baseline['impressions'] ?? 0);

        $ctrDelta = null;
        if ($current['ctr'] !== null && $baseline['ctr'] !== null && ! $baselineZero) {
            $ctrDelta = round((float) $current['ctr'] - (float) $baseline['ctr'], 6);
        }

        $positionDelta = null;
        if ($current['position'] !== null && $baseline['position'] !== null && ! $baselineZero) {
            $positionDelta = round((float) $current['position'] - (float) $baseline['position'], 4);
        }

        $impressionsGrowthPct = null;
        if (! $baselineZero && (int) ($baseline['impressions'] ?? 0) > 0) {
            $impressionsGrowthPct = round($impressionsDelta / (int) $baseline['impressions'], 4);
        } elseif ($baselineZero && $currentImpressions > 0) {
            $impressionsGrowthPct = null;
        }

        return [
            'baseline_zero' => $baselineZero,
            'clicks_delta' => $clicksDelta,
            'impressions_delta' => $impressionsDelta,
            'ctr_delta' => $ctrDelta,
            'position_delta' => $positionDelta,
            'impressions_growth_pct' => $impressionsGrowthPct,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }
}
