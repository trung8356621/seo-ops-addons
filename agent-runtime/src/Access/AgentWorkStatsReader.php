<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

interface AgentWorkStatsReader
{
    /**
     * @return array<string, int|float|string|null>
     */
    public function summarize(AgentActorAccessContext $context, string $kind): array;
}
