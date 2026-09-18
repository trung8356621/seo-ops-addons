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
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterAlgorithm;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;

/**
 * Site-scoped Topic recluster. Unique + without-overlapping per site_id
 * so a second dispatch cannot run concurrently for the same site.
 *
 * Serializes requestedAlgorithmVersion from the dispatching process.
 * Worker refuses when its loaded TopicReclusterAlgorithm::VERSION differs
 * (stale long-lived queue:work after deploy). First deploy still needs
 * `php artisan queue:restart` so the guard code itself is loaded.
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

    public function handle(TopicReclusterService $recluster): void
    {
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
}
