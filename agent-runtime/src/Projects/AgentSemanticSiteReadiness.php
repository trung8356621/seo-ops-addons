<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Projects;

/**
 * Site-selection gate for completed semantic keyword grouping.
 *
 * Absence of a binding preserves existing site authorization.
 */
interface AgentSemanticSiteReadiness
{
    public function isReady(int $siteId): bool;

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    public function filterReadySiteIds(array $siteIds): array;
}
