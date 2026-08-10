<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscProjectItemPerformanceState;

/**
 * Derive Content Project item performance state from GSC period metrics.
 */
final class GscProjectItemPerformanceDeriver
{
    public const ALGORITHM_VERSION = '1.0.0';

    public function __construct(
        private readonly GscPerformanceAggregationService $aggregation,
    ) {}

    /**
     * @param  array{clicks?: int, impressions?: int, ctr?: ?float, position?: ?float}  $current
     * @param  array{clicks?: int, impressions?: int, ctr?: ?float, position?: ?float}  $baseline
     * @param  array<string, mixed>  $context  published?, needs_review?, decay_drop_pct?
     */
    public function derive(array $current, array $baseline = [], array $context = []): GscProjectItemPerformanceState
    {
        if (($context['published'] ?? true) === false) {
            return GscProjectItemPerformanceState::NotPublished;
        }

        $impressions = (int) ($current['impressions'] ?? 0);
        if ($impressions === 0) {
            return GscProjectItemPerformanceState::AwaitingData;
        }

        if (($context['needs_review'] ?? false) === true) {
            return GscProjectItemPerformanceState::NeedsReview;
        }

        $comparison = $this->aggregation->comparePeriods($current, $baseline);

        if ($comparison['baseline_zero'] === true) {
            return GscProjectItemPerformanceState::New;
        }

        $baselineClicks = (int) ($baseline['clicks'] ?? 0);
        $clicksDelta = (int) ($comparison['clicks_delta'] ?? 0);
        $decayThreshold = (float) ($context['decay_drop_pct'] ?? 0.30);

        if ($baselineClicks > 0 && $clicksDelta < 0 && abs($clicksDelta) / $baselineClicks >= $decayThreshold) {
            return GscProjectItemPerformanceState::Decaying;
        }

        $impressionsDelta = (int) ($comparison['impressions_delta'] ?? 0);
        if ($clicksDelta > 0 || $impressionsDelta > 0) {
            return GscProjectItemPerformanceState::Growing;
        }

        $position = $current['position'] ?? null;
        $ctr = $current['ctr'] ?? null;

        if ($position !== null && (float) $position <= 5.0 && $ctr !== null && (float) $ctr >= 0.05) {
            return GscProjectItemPerformanceState::Winning;
        }

        if ($impressions >= 100 && $ctr !== null && (float) $ctr < 0.02) {
            return GscProjectItemPerformanceState::Underperforming;
        }

        if ($clicksDelta === 0 && $impressionsDelta === 0) {
            return GscProjectItemPerformanceState::Stable;
        }

        return GscProjectItemPerformanceState::Unknown;
    }
}
