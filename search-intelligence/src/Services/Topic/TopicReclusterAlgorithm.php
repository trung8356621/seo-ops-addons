<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Explicit Topic recluster algorithm identity for stale-worker protection.
 *
 * Bump VERSION whenever seed source, attach/discover/identity, or persist freeze
 * rules change in a way that would produce different Topic topology.
 *
 * After bumping: run `php artisan queue:restart` (or restart long-lived queue:work)
 * so workers load the new constant before the next UI Recluster.
 */
final class TopicReclusterAlgorithm
{
    /**
     * Phase 2A curated Domain Link List + verified product_cat seeds;
     * Phase 2B specificity attach / discovery≥3 / discovered identity;
     * manual Topics frozen from global recluster (no auto reconcile).
     */
    public const VERSION = 'topic-recluster-v2b-2026-09-18';

    public const STALE_WORKER_MESSAGE = 'Topic worker is running an outdated algorithm version. Restart the queue worker and retry.';

    public static function matches(string $requested): bool
    {
        return $requested === self::VERSION;
    }
}
