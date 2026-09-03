<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionPlannerService;

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

            $siteId = (int) $command->siteId;
            if ($siteId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Working site is required.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertCanAccessSite($siteId, $actor);

            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Working site is required.',
                    $projectId,
                    metadata: ['site_id' => $siteId],
                );
            }

            $summary = $this->planner->addToDraftProject($project, $site, $command->rows, $actor->actorId);

            $added = (int) ($summary['added'] ?? 0);
            $message = sprintf(
                'Added: %d · Already planned: %d · Dismissed skipped: %d · Ineligible: %d',
                $added,
                (int) ($summary['already_planned'] ?? 0),
                (int) ($summary['dismissed_skipped'] ?? 0),
                (int) ($summary['ineligible'] ?? 0),
            );

            $metadata = array_merge($summary, [
                'added' => $added,
                'site_id' => $siteId,
            ]);

            if ($added <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::SUGGESTIONS_NONE_ADDED,
                    $message,
                    $projectId,
                    metadata: $metadata,
                );
            }

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::SUGGESTIONS_ADDED,
                $message,
                $projectId,
                $summary['task_ids'] ?? [],
                metadata: $metadata,
            );
        });
    }
}
