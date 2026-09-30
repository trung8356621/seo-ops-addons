<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

final class RetrievalPlanner
{
    /** @deprecated Canonical planning is explicit and does not use score thresholds. */
    public const DEFAULT_NEED_THRESHOLD = 0.5;

    /** @param float|null $legacyNeedThreshold Ignored; retained for constructor compatibility. */
    public function __construct(?float $legacyNeedThreshold = null)
    {
        unset($legacyNeedThreshold);
    }

    public function plan(RetrievalDecision $decision, AgentProjectScope $scope): RetrievalPlan
    {
        if ($scope->isGlobal()) {
            return new RetrievalPlan($scope, [], true, $decision->requiresParameterExtraction);
        }

        $steps = [];
        foreach ($decision->modules as $resource) {
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
        $articleRef = null;
        if (in_array($resource, ['gsc', 'content_projects'], true)) {
            $period = $decision->parameters['period'] ?? '';
            if (preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
                $query['period'] = $period;
            }
        }
        if (in_array($resource, ['keywords', 'topics'], true)) {
            $topic = $decision->parameters['topic_ref'] ?? '';
            if (preg_match('/^topic:[1-9]\d*$/', $topic) === 1) {
                $topicRef = $topic;
            }
        }
        if ($resource === 'articles') {
            $ref = $decision->parameters['article_ref'] ?? '';
            if (is_string($ref) && preg_match('/^article:[1-9]\d*$/', $ref) === 1) {
                $articleRef = $ref;
            }
            $task = $decision->parameters['task'] ?? '';
            if (is_string($task) && $task !== '') {
                $query['task'] = $task;
            }
            if (isset($decision->parameters['limit_max'])) {
                $query['limit'] = (string) $decision->parameters['limit_max'];
            }
        }

        return new RetrievalStep($resource, $query, $topicRef, $articleRef);
    }
}
