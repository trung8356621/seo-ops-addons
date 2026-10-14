<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

interface AgentWorkStatsReader
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(AgentActorAccessContext $context, string $kind, WorkStatsWindow $window): array;
}
