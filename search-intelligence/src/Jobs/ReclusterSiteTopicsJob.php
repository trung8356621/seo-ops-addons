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
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingAnalysisService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterAlgorithm;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;

/**
 * Site-scoped Topic recluster OR semantic analyze-only (config-driven).
 *
 * semantic_http: queued → analyzing → proposal_ready|failed → STOP (no Topic mutation)
 * legacy: existing recluster apply path
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
    ): void {
        if (TopicGroupingProviderMode::isSemanticHttp()) {
            $this->handleSemanticAnalyze($analysis);

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

    private function handleSemanticAnalyze(TopicGroupingAnalysisService $analysis): void
    {
        TopicReclusterUiState::markQueued($this->siteId, TopicGroupingProviderMode::SEMANTIC_HTTP);
        $run = $analysis->analyzeSite(
            $this->siteId,
            TopicGroupingRebuildMode::normalize($this->rebuildMode),
        );
        if ($run->status === TopicGroupingRunStatus::PROPOSAL_READY) {
            return;
        }
        // analyzeSite already marks failed UI state; ensure status is failed if unexpected.
        if ($run->status !== TopicGroupingRunStatus::FAILED) {
            TopicReclusterUiState::markFailed(
                $this->siteId,
                $run->error_message ?? 'analysis_failed',
                ['run_id' => $run->id],
                $run->error_code ?? 'analysis_failed',
                TopicGroupingProviderMode::SEMANTIC_HTTP,
            );
        }
    }
}
