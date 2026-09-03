<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\FillSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionPlannerService;

final class FillSeoAuditSuggestionsHandler extends AbstractPublishingHandler
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
        if (! $command instanceof FillSeoAuditSuggestionsCommand) {
            throw new InvalidArgumentException('Expected FillSeoAuditSuggestionsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot fill suggestions on archived project.',
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

            $summary = $this->planner->fillSuggestions(
                $project,
                $site,
                $command->filters,
                $command->limit,
                $actor->actorId,
            );

            $requested = (int) ($summary['requested'] ?? $command->limit);
            $added = (int) ($summary['added'] ?? 0);
            $unavailable = (int) ($summary['unavailable'] ?? max(0, $requested - $added));

            $message = sprintf(
                '%d requested · %d added · %d unavailable under current filters · %d already planned skipped · %d rejected skipped',
                $requested,
                $added,
                $unavailable,
                (int) ($summary['already_planned'] ?? 0),
                (int) ($summary['dismissed_skipped'] ?? 0),
            );

            $metadata = array_merge($summary, [
                'added' => $added,
                'requested' => $requested,
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

            if ($added < $requested) {
                return ContentProjectActionResult::ok(
                    ContentProjectActionCodes::SUGGESTIONS_ADDED,
                    $message,
                    $projectId,
                    $summary['task_ids'] ?? [],
                    warnings: ['partial_fill'],
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
