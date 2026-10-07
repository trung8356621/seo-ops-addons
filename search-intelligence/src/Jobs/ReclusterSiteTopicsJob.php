<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingApplyService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingAnalysisService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterAlgorithm;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;

/**
 * Site-scoped Topic recluster.
 *
 * semantic_http: Analyze → internal plan → Apply on the SAME run (no user Preview).
 * legacy: existing recluster apply path.
 */
final class ReclusterSiteTopicsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Keep uniqueness while queued/running (matches UI lock TTL window). */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $siteId,
        public readonly string $requestedAlgorithmVersion = TopicReclusterAlgorithm::VERSION,
        public readonly string $rebuildMode = TopicGroupingRebuildMode::PRESERVE_EXISTING,
    ) {
        // Prefer seo queue (ahead of default backlog) so Topic recluster is not starved.
        $this->onQueue('seo');
    }

    public function uniqueId(): string
    {
        return 'topic-core-recluster:'.$this->siteId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('topic-core-recluster:'.$this->siteId))
                ->releaseAfter(30)
                ->expireAfter(3600),
        ];
    }

    public function handle(
        TopicReclusterService $recluster,
        TopicGroupingAnalysisService $analysis,
        TopicGroupingApplyService $apply,
    ): void {
        if (TopicGroupingProviderMode::isSemanticHttp()) {
            $this->handleSemanticAnalyzeAndApply($analysis, $apply);

            return;
        }

        if (! TopicReclusterAlgorithm::matches($this->requestedAlgorithmVersion)) {
            TopicReclusterUiState::markFailed(
                $this->siteId,
                TopicReclusterAlgorithm::STALE_WORKER_MESSAGE,
                [
                    'requested_algorithm_version' => $this->requestedAlgorithmVersion,
                    'worker_algorithm_version' => TopicReclusterAlgorithm::VERSION,
                ],
                'stale_worker_algorithm',
                $this->requestedAlgorithmVersion,
            );

            // Do not mutate Topics. Complete without throw so --tries does not re-run stale logic.
            return;
        }

        TopicReclusterUiState::markRunning($this->siteId, $this->requestedAlgorithmVersion);
        $result = $recluster->recluster($this->siteId);
        if ($result->ok) {
            TopicReclusterUiState::markSucceeded($this->siteId, $result->metrics, $this->requestedAlgorithmVersion);

            return;
        }

        TopicReclusterUiState::markFailed(
            $this->siteId,
            $result->error ?? 'recluster_failed',
            $result->metrics,
            'recluster_failed',
            $this->requestedAlgorithmVersion,
        );
    }

    private function handleSemanticAnalyzeAndApply(
        TopicGroupingAnalysisService $analysis,
        TopicGroupingApplyService $apply,
    ): void {
        $rebuildMode = TopicGroupingRebuildMode::normalize($this->rebuildMode);
        TopicReclusterUiState::markQueued(
            $this->siteId,
            TopicGroupingProviderMode::SEMANTIC_HTTP,
            $rebuildMode,
        );

        $run = $analysis->analyzeSite($this->siteId, $rebuildMode);
        if ($run->status !== TopicGroupingRunStatus::PROPOSAL_READY) {
            if ($run->status !== TopicGroupingRunStatus::FAILED) {
                TopicReclusterUiState::markFailed(
                    $this->siteId,
                    $run->error_message ?? 'analysis_failed',
                    ['run_id' => $run->id, 'rebuild_mode' => $rebuildMode],
                    $run->error_code ?? 'analysis_failed',
                    TopicGroupingProviderMode::SEMANTIC_HTTP,
                );
            }

            return;
        }

        // Internal plan build (plan_hash / stale guards). Not a user Preview step.
        $preview = $apply->preview((int) $run->id);
        if (! $preview->ok() || $preview->plan === null) {
            TopicReclusterUiState::markFailed(
                $this->siteId,
                $preview->errorMessage ?? 'plan_build_failed',
                [
                    'run_id' => (int) $run->id,
                    'rebuild_mode' => $rebuildMode,
                    'status' => $preview->status,
                ],
                $preview->errorCode ?? 'plan_build_failed',
                TopicGroupingProviderMode::SEMANTIC_HTTP,
            );

            return;
        }

        if ($preview->plan->isHardBlocked()) {
            TopicReclusterUiState::markFailed(
                $this->siteId,
                'Unresolved Topic-owned business state — Apply blocked',
                [
                    'run_id' => (int) $run->id,
                    'rebuild_mode' => $rebuildMode,
                    'counts' => $preview->plan->counts,
                ],
                'business_state_hard_block',
                TopicGroupingProviderMode::SEMANTIC_HTTP,
            );

            return;
        }

        // ApplyService marks applied / apply_failed / stale on UI + run.
        $apply->apply((int) $run->id, $preview->plan->planHash);
    }
}
