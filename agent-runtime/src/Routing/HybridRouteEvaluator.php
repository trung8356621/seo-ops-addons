<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

interface HybridRouteEvaluator
{
    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function evaluateHybrid(string $query, array $document): array;
}
