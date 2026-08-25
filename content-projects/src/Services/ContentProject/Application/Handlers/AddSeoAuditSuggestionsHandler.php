<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionPlannerService;
use InvalidArgumentException;

final class AddSeoAuditSuggestionsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoAuditSuggestionPlannerService $planner,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AddSeoAuditSuggestionsCommand) {
            throw new InvalidArgumentException('Expected AddSeoAuditSuggestionsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot add suggestions to archived project.',
                    $projectId,
                );
            }

            if (! $project->isDraftPlanning()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::SUGGESTIONS_PLANNING_DRAFT_ONLY,
                    'Add suggestions to a Draft project.',
                    $projectId,
                );
            }

            $summary = $this->planner->addToDraftProject($project, $command->rows, $actor->actorId);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::SUGGESTIONS_ADDED,
                sprintf(
                    'Added: %d · Already planned: %d · Dismissed skipped: %d · Ineligible: %d',
                    (int) $summary['added'],
                    (int) $summary['already_planned'],
                    (int) $summary['dismissed_skipped'],
                    (int) $summary['ineligible'],
                ),
                $projectId,
                $summary['task_ids'] ?? [],
                metadata: $summary,
            );
        });
    }
}
