<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;

final class ReclusterSiteTopicsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $siteId,
    ) {}

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
