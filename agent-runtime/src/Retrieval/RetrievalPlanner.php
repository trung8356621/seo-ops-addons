<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

final class RetrievalPlanner
{
    public const DEFAULT_NEED_THRESHOLD = 0.5;

    public function __construct(
        private readonly float $needThreshold = self::DEFAULT_NEED_THRESHOLD,
    ) {}

    public function plan(RetrievalDecision $decision, AgentProjectScope $scope): RetrievalPlan
    {
        if ($scope->isGlobal()) {
            return new RetrievalPlan($scope, [], true, $decision->requiresParameterExtraction);
        }

        $steps = [];
        foreach (SeoAccessCapabilityCatalog::resources() as $resource) {
            $score = $decision->needs[$resource] ?? 0.0;
            if ($score < $this->needThreshold) {
                continue;
            }
            $steps[] = $this->stepFor($resource, $decision);
        }

        return new RetrievalPlan(
            $scope,
            $steps,
            false,
            $decision->requiresParameterExtraction,
        );
    }

    private function stepFor(string $resource, RetrievalDecision $decision): RetrievalStep
    {
        $query = [];
        $topicRef = null;
        if ($resource === 'gsc') {
            $period = $decision->parameters['period'] ?? '';
            if (preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
                $query['period'] = $period;
            }
        }
        if ($resource === 'keywords') {
            $topic = $decision->parameters['topic_ref'] ?? '';
            if (preg_match('/^topic:[1-9]\d*$/', $topic) === 1) {
                $topicRef = $topic;
            }
        }

        return new RetrievalStep($resource, $query, $topicRef);
    }
}
