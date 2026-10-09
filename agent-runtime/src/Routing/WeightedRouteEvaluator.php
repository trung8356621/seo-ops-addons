<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

interface WeightedRouteEvaluator
{
    /** @param list<array<string, mixed>> $groups */
    public function evaluate(string $query, array $groups): WeightedEvaluation;
}
