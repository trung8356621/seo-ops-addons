<?php

declare(strict_types=1);

/**
 * Phase 5 — GSC Intelligence runtime defaults.
 * Keys consumed by GscProviderResolver / GscSync* / GscOpportunityDetectionService / GscQueryCannibalizationDetector.
 * Live Google Search Analytics adapter out of scope.
 */
return [
    'providers' => [
        'enabled' => ['manual_import', 'fake_local'],
    ],
    'lock' => [
        'ttl_seconds' => 600,
    ],
    'sync' => [
        'data_delay_days' => 3,
        'incremental_overlap_days' => 2,
        'max_days_per_chunk' => 28,
    ],
    'brand' => [
        'terms' => [],
    ],
    'opportunity' => [
        'min_impressions' => 100,
        'near_page_one_max_position' => 15.0,
        'low_ctr_gap_min' => 0.02,
        'decay_clicks_drop_pct' => 0.30,
        'min_impressions_growth_pct' => 0.25,
        'maturity' => [
            'new_days' => 14,
            'early_days' => 60,
        ],
    ],
    'cannibalization' => [
        'min_competing_pages' => 2,
        'min_impressions_per_page' => 10,
    ],
];
