<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation;

final class AgentExecutionOutcomeEvaluator
{
    /**
     * @param  array<string, mixed>  $observed
     * @return array{score: float, scores: array<string, float>, violations: list<string>}
     */
    public function evaluate(array $observed): array
    {
        $scores = [];
        $violations = [];

        $scores['confirmation_applied'] = (($observed['requires_confirmation'] ?? false) === false
            || ($observed['confirmed'] ?? false) === true) ? 1.0 : 0.0;
        if ($scores['confirmation_applied'] < 1.0) {
            $violations[] = 'confirmation_missing';
        }

        $scores['idempotency'] = (($observed['idempotent_replay'] ?? false) === true
            || ($observed['duplicate_prevented'] ?? false) === true
            || ($observed['ok'] ?? false) === true) ? 1.0 : 0.5;

        $scores['outcome'] = (($observed['ok'] ?? false) === true) ? 1.0 : 0.0;
        $scores['preview_consistency'] = (($observed['preview_effects_match'] ?? true) === true) ? 1.0 : 0.0;

        return [
            'score' => round(array_sum($scores) / max(1, count($scores)), 4),
            'scores' => $scores,
            'violations' => $violations,
        ];
    }
}
