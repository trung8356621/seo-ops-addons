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
    public function listActiveSites(): array;
}
