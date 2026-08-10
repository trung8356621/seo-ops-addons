<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation;

final class AgentGroundingEvaluator
{
    /**
     * @param  array<string, mixed>  $observed
     * @return array{score: float, scores: array<string, float>, violations: list<string>}
     */
    public function evaluate(array $observed): array
    {
        $scores = [];
        $violations = [];

        $scores['scope_isolation'] = (($observed['cross_site'] ?? false) === true) ? 0.0 : 1.0;
        if ($scores['scope_isolation'] < 1.0) {
            $violations[] = 'cross_site_knowledge';
        }

        $scores['citation_valid'] = (($observed['citation_valid'] ?? true) === true) ? 1.0 : 0.0;
        if ($scores['citation_valid'] < 1.0) {
            $violations[] = 'fabricated_citation';
        }

        $scores['conflict_surfaced'] = (($observed['has_conflict'] ?? false) === false
            || ($observed['conflict_surfaced'] ?? false) === true) ? 1.0 : 0.0;

        $scores['stale_warning'] = (($observed['stale'] ?? false) === false
            || ($observed['stale_warned'] ?? false) === true) ? 1.0 : 0.0;

        $scores['budget'] = (($observed['budget_ok'] ?? true) === true) ? 1.0 : 0.0;

        return [
            'score' => round(array_sum($scores) / max(1, count($scores)), 4),
            'scores' => $scores,
            'violations' => $violations,
        ];
    }
}
