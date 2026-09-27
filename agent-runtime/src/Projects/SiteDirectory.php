<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Projects;

interface SiteDirectory
{
    /**
     * Navigation labels only. This is not SEO Access data.
     *
     * @return list<array{id: int, domain: string}>
     */
    public function listActiveSites(?int $userId = null): array;

    /**
     * Check if a site exists, is active, and is visible to the given user.
     */
    public function isSiteVisible(int $siteId, ?int $userId = null): bool;
}
