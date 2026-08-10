<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityMaturity;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;

/**
 * Detect GSC opportunities — fingerprint dedup, maturity windows, config thresholds.
 */
final class GscOpportunityDetectionService
{
    public const ALGORITHM_VERSION = '1.0.0';

    /** @var list<array<string, mixed>> */
    private array $seenFingerprints = [];

    public function __construct(
        private readonly GscPerformanceAggregationService $aggregation,
        private readonly GscExpectedCtrModel $expectedCtr,
    ) {}

    public function resetFingerprints(): void
    {
        $this->seenFingerprints = [];
    }

    /**
     * @param  list<array<string, mixed>>  $currentRows
     * @param  list<array<string, mixed>>  $baselineRows
     * @param  array<string, mixed>  $context  normalized_query, keyword_ref?, first_seen_date?
     * @return list<array<string, mixed>>
     */
    public function detect(array $currentRows, array $baselineRows = [], array $context = []): array
    {
        $opportunities = [];
        $current = $this->aggregation->aggregate($currentRows);
        $baseline = $this->aggregation->aggregate($baselineRows);
        $comparison = $this->aggregation->comparePeriods($current, $baseline);

        $normalizedQuery = (string) ($context['normalized_query'] ?? '');
        $keywordRef = $context['keyword_ref'] ?? null;
        $firstSeenDate = (string) ($context['first_seen_date'] ?? date('Y-m-d'));
        $maturity = $this->resolveMaturity($firstSeenDate);

        $minImpressions = $this->configInt('opportunity.min_impressions', 100);
        $nearPageOneMax = $this->configFloat('opportunity.near_page_one_max_position', 15.0);
        $lowCtrGapMin = $this->configFloat('opportunity.low_ctr_gap_min', 0.02);
        $decayDropPct = $this->configFloat('opportunity.decay_clicks_drop_pct', 0.30);
        $growthPctMin = $this->configFloat('opportunity.min_impressions_growth_pct', 0.25);

        if (($current['impressions'] ?? 0) >= $minImpressions) {
            $ctrGap = $this->expectedCtr->ctrGap($current['ctr'], $current['position']);
            if ($ctrGap !== null && $ctrGap >= $lowCtrGapMin) {
                $opportunities[] = $this->buildOpportunity(
                    GscOpportunityType::HighImpressionLowCtr,
                    $normalizedQuery,
                    $keywordRef,
                    $maturity,
                    [
                        'impressions' => $current['impressions'],
                        'ctr' => $current['ctr'],
                        'expected_ctr' => $this->expectedCtr->expectedCtr($current['position']),
                        'ctr_gap' => $ctrGap,
                    ],
                );
            }
        }

        if ($current['position'] !== null
            && $current['position'] <= $nearPageOneMax
            && ($current['impressions'] ?? 0) >= $minImpressions) {
            $opportunities[] = $this->buildOpportunity(
                GscOpportunityType::NearPageOne,
                $normalizedQuery,
                $keywordRef,
                $maturity,
                [
                    'position' => $current['position'],
                    'impressions' => $current['impressions'],
                ],
            );
        }

        if ($comparison['baseline_zero'] === false
            && (int) ($baseline['clicks'] ?? 0) > 0
            && (int) ($current['clicks'] ?? 0) < (int) ($baseline['clicks'] ?? 0)) {
            $dropPct = ((int) $baseline['clicks'] - (int) $current['clicks']) / (int) $baseline['clicks'];
            if ($dropPct >= $decayDropPct) {
                $opportunities[] = $this->buildOpportunity(
                    GscOpportunityType::ContentDecay,
                    $normalizedQuery,
                    $keywordRef,
                    $maturity,
                    [
                        'clicks_delta' => $comparison['clicks_delta'],
                        'drop_pct' => round($dropPct, 4),
                    ],
                );
            }
        }

        if ($comparison['impressions_growth_pct'] !== null && $comparison['impressions_growth_pct'] >= $growthPctMin) {
            $opportunities[] = $this->buildOpportunity(
                GscOpportunityType::ImpressionGrowth,
                $normalizedQuery,
                $keywordRef,
                $maturity,
                [
                    'impressions_growth_pct' => $comparison['impressions_growth_pct'],
                    'impressions_delta' => $comparison['impressions_delta'],
                ],
            );
        }

        if ($keywordRef === null && $normalizedQuery !== '' && ($current['impressions'] ?? 0) >= $minImpressions) {
            $opportunities[] = $this->buildOpportunity(
                GscOpportunityType::UnmappedQuery,
                $normalizedQuery,
                null,
                $maturity,
                ['impressions' => $current['impressions']],
            );
        }

        return array_values(array_filter(
            $opportunities,
            fn (array $opp): bool => $this->registerFingerprint($opp),
        ));
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function buildOpportunity(
        GscOpportunityType $type,
        string $normalizedQuery,
        mixed $keywordRef,
        GscOpportunityMaturity $maturity,
        array $evidence,
    ): array {
        return [
            'type' => $type->value,
            'normalized_query' => $normalizedQuery,
            'keyword_ref' => $keywordRef,
            'maturity' => $maturity->value,
            'evidence' => $evidence,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'fingerprint' => $this->fingerprint($type, $normalizedQuery, $keywordRef),
        ];
    }

    private function fingerprint(GscOpportunityType $type, string $normalizedQuery, mixed $keywordRef): string
    {
        return hash('sha256', implode(':', [
            self::ALGORITHM_VERSION,
            $type->value,
            $normalizedQuery,
            (string) ($keywordRef ?? ''),
        ]));
    }

    /**
     * @param  array<string, mixed>  $opportunity
     */
    private function registerFingerprint(array $opportunity): bool
    {
        $fp = (string) ($opportunity['fingerprint'] ?? '');
        if ($fp === '' || isset($this->seenFingerprints[$fp])) {
            return false;
        }

        $this->seenFingerprints[$fp] = true;

        return true;
    }

    private function resolveMaturity(string $firstSeenDate): GscOpportunityMaturity
    {
        try {
            $firstSeen = new \DateTimeImmutable($firstSeenDate);
            $days = (int) (new \DateTimeImmutable('today'))->diff($firstSeen)->days;
        } catch (\Throwable) {
            return GscOpportunityMaturity::Early;
        }

        $newDays = $this->configInt('opportunity.maturity.new_days', 14);
        $earlyDays = $this->configInt('opportunity.maturity.early_days', 60);

        if ($days <= $newDays) {
            return GscOpportunityMaturity::New;
        }

        if ($days <= $earlyDays) {
            return GscOpportunityMaturity::Early;
        }

        return GscOpportunityMaturity::Mature;
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (int) config('seo-content-ai.gsc_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function configFloat(string $key, float $default): float
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (float) config('seo-content-ai.gsc_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
