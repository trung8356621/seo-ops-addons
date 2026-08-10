<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentFeedback;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentMetricEvent;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentReview;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentTrace;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentTraceSpan;
use Throwable;

/**
 * Retention prune — keeps aggregates by default.
 */
final class AgentRetentionService
{
    public function __construct(
        private readonly AgentGovernancePolicyService $governance = new AgentGovernancePolicyService,
    ) {}

    /**
     * @return array<string, int>
     */
    public function prune(bool $dryRun = true): array
    {
        $days = $this->governance->retentionDays();
        $counts = [
            'metric_events' => 0,
            'traces' => 0,
            'spans' => 0,
            'reviews' => 0,
            'feedback' => 0,
            'aggregates_kept' => 0,
        ];

        try {
            $eventCutoff = now()->subDays($days['metric_events_days']);
            $qEvents = SeoAgentMetricEvent::query()->where('occurred_at', '<', $eventCutoff);
            $counts['metric_events'] = (int) $qEvents->count();
            if (! $dryRun && $counts['metric_events'] > 0) {
                $qEvents->delete();
            }

            $traceCutoff = now()->subDays($days['traces_days']);
            $oldTraces = SeoAgentTrace::query()->where('started_at', '<', $traceCutoff);
            $counts['traces'] = (int) $oldTraces->count();
            $traceIds = $oldTraces->limit(5000)->pluck('trace_id')->all();
            if ($traceIds !== []) {
                $spanQ = SeoAgentTraceSpan::query()->whereIn('trace_id', $traceIds);
                $counts['spans'] = (int) $spanQ->count();
                if (! $dryRun) {
                    $spanQ->delete();
                    SeoAgentTrace::query()->whereIn('trace_id', $traceIds)->delete();
                }
            }

            $reviewCutoff = now()->subDays($days['reviews_days']);
            $qReviews = SeoAgentReview::query()->where('created_at', '<', $reviewCutoff)->where('status', '!=', 'open');
            $counts['reviews'] = (int) $qReviews->count();
            if (! $dryRun && $counts['reviews'] > 0) {
                $qReviews->delete();
            }

            $fbCutoff = now()->subDays($days['feedback_days']);
            $qFb = SeoAgentFeedback::query()->where('created_at', '<', $fbCutoff);
            $counts['feedback'] = (int) $qFb->count();
            if (! $dryRun && $counts['feedback'] > 0) {
                $qFb->delete();
            }

            // aggregates intentionally retained longer — count only
            $counts['aggregates_kept'] = 1;
        } catch (Throwable) {
        }

        return $counts;
    }
}
