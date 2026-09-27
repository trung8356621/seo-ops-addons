<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

final class RetrievalExecutor
{
    public function __construct(
        private readonly RetrievalPlanner $planner,
        private readonly SeoAccessExecutor $seoAccess,
    ) {}

    public function execute(RetrievalDecision $decision, AgentProjectScope $scope): RetrievalBundle
    {
        $plan = $this->planner->plan($decision, $scope);
        if ($plan->globalUnsupported) {
            return RetrievalBundle::unsupportedGlobal($scope);
        }

        return $this->seoAccess->execute($plan);
    }
}
