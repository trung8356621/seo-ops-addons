<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

/**
 * Centralized GSC Search Performance thresholds + MCP/Planning caps.
 * No Settings UI in v1 — defaults + config('seo-content-ai.gsc_intelligence.*').
 */
final class GscIntelligencePolicy
{
    public const MAX_TOP_QUERIES = 30;

    public const MAX_TOP_PAGES = 30;

    public const MAX_RISING = 20;

    public const MAX_FALLING = 20;

    public const MAX_CTR_OPPORTUNITIES = 20;

    public const MAX_NEAR_PAGE_ONE = 20;

    public const MAX_CONTENT_DECAY = 20;

    public const MAX_CANNIBALIZATION = 15;

    public const MAX_PLANNING_SIGNALS = 30;

    public const MAX_AI_CONTEXT_LINES = 40;

    public const MAX_KI_INGEST_PER_SYNC = 50;

    /** Minimum impressions (current) before CTR / near-page-one / strong signals fire. */
    public static function minImpressionsForSignal(): int
    {
        return self::configInt('opportunity.min_impressions', 100);
    }

    /** Minimum baseline impressions before falling / decay classification. */
    public static function minImpressionsForComparison(): int
    {
        return max(50, (int) floor(self::minImpressionsForSignal() / 2));
    }

    public static function minImpressionsGrowthPct(): float
    {
        return self::configFloat('opportunity.min_impressions_growth_pct', 0.25);
    }

    public static function nearPageOneMaxPosition(): float
    {
        return self::configFloat('opportunity.near_page_one_max_position', 15.0);
    }

    public static function nearPageOneMinPosition(): float
    {
        return 8.0;
    }

    public static function lowCtrGapMin(): float
    {
        return self::configFloat('opportunity.low_ctr_gap_min', 0.02);
    }

    public static function decayClicksDropPct(): float
    {
        return self::configFloat('opportunity.decay_clicks_drop_pct', 0.30);
    }

    /** Position worsening (current − previous) to count as falling. Lower number = better. */
    public static function positionWorsenMin(): float
    {
        return 2.0;
    }

    public static function cannibalizationMinPages(): int
    {
        return self::configInt('cannibalization.min_competing_pages', 2);
    }

    public static function cannibalizationMinImpressionsPerPage(): int
    {
        return self::configInt('cannibalization.min_impressions_per_page', 10);
    }

    private static function configInt(string $key, int $default): int
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

    private static function configFloat(string $key, float $default): float
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
