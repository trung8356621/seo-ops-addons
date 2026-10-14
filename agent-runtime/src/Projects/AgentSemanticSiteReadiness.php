<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Projects;

/**
 * Semantic keyword-grouping readiness for Agent execution.
 *
 * Site catalogs stay authorization-scoped. Readiness is enforced when a
 * site-targeted operation is submitted. Absence of a binding preserves
 * existing site authorization.
 */
interface AgentSemanticSiteReadiness
{
    public function isReady(int $siteId): bool;

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    public function filterReadySiteIds(array $siteIds): array;

    public function isProcessing(int $siteId): bool;

    /**
     * Sites with an accepted grouping run that has not reached a terminal phase.
     *
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    public function filterProcessingSiteIds(array $siteIds): array;

    public function enforcementAvailable(): bool;

    /**
     * Accept one semantic keyword-group refresh for a site.
     *
     * Returns accepted, processing, ready, or unavailable.
     * A processing site is not dispatched again.
     */
    public function requestRefresh(int $siteId): string;
}
