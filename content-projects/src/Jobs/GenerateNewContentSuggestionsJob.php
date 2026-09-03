<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Jobs;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Async AI New Content planning. Actor is explicit — never resolve session user in queue runtime.
 * One logical planner run may span multiple job slices via automatic continuation.
 */
final class GenerateNewContentSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 960;

    public function __construct(
        public readonly int $plannerRunId,
        public readonly int $projectId,
        public readonly int $actorId,
    ) {}

    public function uniqueId(): string
    {
        // Per logical run — prevents duplicate concurrent slices; allows other projects.
        return 'content-project-new-content:run:'.$this->plannerRunId;
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        NewContentSuggestionPlannerService $planner,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $run = \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun::query()
            ->find($this->plannerRunId);
        if ($run === null) {
            return;
        }
        $priorStatus = (string) (($run->result_summary ?? [])['status'] ?? '');
        if ($priorStatus === \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun::STATUS_CANCELLED) {
            return;
        }

        $summary = $planner->executeQueuedRun($this->plannerRunId, $this->actorId);

        if (! empty($summary['needs_continuation'])) {
            $delay = max(1, (int) ($summary['continuation_delay_seconds'] ?? 2));
            $fresh = \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun::query()
                ->findOrFail($this->plannerRunId);
            if ((string) (($fresh->result_summary ?? [])['status'] ?? '')
                === \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun::STATUS_CANCELLED
            ) {
                return;
            }
            $queued = $planner->queueContinuation($fresh, $this->actorId, $delay);
            if (! empty($queued['queued'])) {
                self::dispatch($this->plannerRunId, $this->projectId, $this->actorId)
                    ->delay(now()->addSeconds((int) $queued['delay_seconds']));
            }
        }
    }
}
