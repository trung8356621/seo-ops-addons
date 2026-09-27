<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

interface SeoAccessCredential
{
    /**
     * Server-only permanent bearer. Null when the operator has not configured one.
     */
    public function bearer(): ?string;
}
