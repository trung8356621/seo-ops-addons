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
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;

/**
 * Site-scoped Topic recluster. Unique + without-overlapping per site_id
 * so a second dispatch cannot run concurrently for the same site.
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
        TopicReclusterUiState::markRunning($this->siteId);
        $result = $recluster->recluster($this->siteId);
        if ($result->ok) {
            TopicReclusterUiState::markCompleted($this->siteId, $result->metrics);

            return;
        }

        TopicReclusterUiState::markFailed(
            $this->siteId,
            $result->error ?? 'recluster_failed',
            $result->metrics,
        );
    }
}
