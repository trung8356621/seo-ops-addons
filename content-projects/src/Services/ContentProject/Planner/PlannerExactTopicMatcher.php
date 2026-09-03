<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner;

/**
 * Exact Topic/Cluster name lookup for Planner plan clone (no fuzzy).
 */
interface PlannerExactTopicMatcher
{
    /**
     * @return list<array{cluster_ref: string, cluster_name: string, mcp_share: float}>
     */
    public function findExactNormalizedNameMatches(int $siteId, string $normalizedName): array;
}
