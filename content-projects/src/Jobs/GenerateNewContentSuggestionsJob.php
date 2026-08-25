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
 */
final class GenerateNewContentSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $plannerRunId,
        public readonly int $projectId,
        public readonly int $actorId,
    ) {}

    public function uniqueId(): string
    {
        return 'content-project-new-content:'.$this->projectId;
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        NewContentSuggestionPlannerService $planner,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $planner->executeQueuedRun($this->plannerRunId, $this->actorId);
    }
}
