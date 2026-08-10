<?php

declare(strict_types=1);

return [
    /**
     * Timing + slow SQL diagnostics for Article List GET.
     * Default off — zero overhead when false.
     */
    'diagnostics' => filter_var(env('SEO_ARTICLE_LIST_DIAGNOSTICS', false), FILTER_VALIDATE_BOOLEAN),

    /** Log SQL slower than this (ms) when diagnostics on. */
    'slow_sql_ms' => max(50, (int) env('SEO_ARTICLE_LIST_SLOW_SQL_MS', 300)),

    /** Short TTL for Sync Queue tab badge count (seconds). */
    'sync_queue_badge_cache_seconds' => max(0, (int) env('SEO_ARTICLE_LIST_QUEUE_BADGE_CACHE_SECONDS', 15)),
];
