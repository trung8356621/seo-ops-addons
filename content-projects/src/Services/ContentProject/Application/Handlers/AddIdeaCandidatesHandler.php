<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddIdeaCandidatesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateDraftPlannerService;

final class AddIdeaCandidatesHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly IdeaCandidateDraftPlannerService $planner,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AddIdeaCandidatesCommand) {
            throw new InvalidArgumentException('Expected AddIdeaCandidatesCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot add idea candidates to archived project.',
                    $projectId,
                );
            }

            if (! $project->isDraftPlanning()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::SUGGESTIONS_PLANNING_DRAFT_ONLY,
                    'Add idea candidates to a Draft project.',
                    $projectId,
                );
            }

            $summary = $this->planner->addToDraft(
                $project,
                $command->action,
                $command->keywordIds,
                $command->articleIds,
                $actor->actorId,
            );

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::IDEA_CANDIDATES_ADDED,
                sprintf(
                    'Added: %d · Duplicates skipped: %d · Ineligible: %d',
                    (int) ($summary['added'] ?? 0),
                    (int) ($summary['duplicate_skipped'] ?? 0),
                    (int) ($summary['ineligible'] ?? 0),
                ),
                $projectId,
                $summary['task_ids'] ?? [],
                metadata: $summary,
            );
        });
    }
}
