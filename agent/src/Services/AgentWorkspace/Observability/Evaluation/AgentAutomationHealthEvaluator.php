<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation;

/**
 * Automation health — recommends pause, does not auto-pause.
 */
final class AgentAutomationHealthEvaluator
{
    /**
     * @param  array<string, mixed>  $stats
     * @return array{score: float, scores: array<string, float>, recommendations: list<string>, violations: list<string>}
     */
    public function evaluate(array $stats): array
    {
        $scores = [];
        $recs = [];
        $violations = [];

        $failStreak = (int) ($stats['failure_streak'] ?? 0);
        $scores['failure_streak'] = $failStreak >= 5 ? 0.0 : ($failStreak >= 3 ? 0.5 : 1.0);
        if ($failStreak >= 5) {
            $recs[] = 'recommend_pause';
            $violations[] = 'failure_streak';
        }

        $noChange = (int) ($stats['no_change_streak'] ?? 0);
        $scores['no_change'] = $noChange >= 20 ? 0.4 : 1.0;

        $spam = (int) ($stats['notification_spam'] ?? 0);
        $scores['spam'] = $spam > 0 ? 0.0 : 1.0;
        if ($spam > 0) {
            $violations[] = 'notification_spam';
            $recs[] = 'review_notification_policy';
        }

        $scores['quota_skips'] = ((int) ($stats['quota_skips'] ?? 0) > 10) ? 0.5 : 1.0;
        $scores['permission_loss'] = ((int) ($stats['permission_loss'] ?? 0) > 0) ? 0.0 : 1.0;
        if (($stats['permission_loss'] ?? 0) > 0) {
            $violations[] = 'permission_lost';
        }
        $scores['stale_definition'] = ((int) ($stats['stale_definition'] ?? 0) > 0) ? 0.3 : 1.0;
        $scores['approval_backlog'] = ((int) ($stats['approval_backlog'] ?? 0) > 20) ? 0.4 : 1.0;
        $scores['schedule_drift'] = ((int) ($stats['schedule_drift_minutes'] ?? 0) > 60) ? 0.5 : 1.0;

        return [
            'score' => round(array_sum($scores) / max(1, count($scores)), 4),
            'scores' => $scores,
            'recommendations' => $recs,
            'violations' => $violations,
            'auto_pause' => false,
        ];
    }
}
