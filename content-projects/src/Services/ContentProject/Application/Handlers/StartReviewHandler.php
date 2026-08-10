<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectReviewRequested;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use InvalidArgumentException;

final class StartReviewHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly ContentProjectItemActionGuard $actionGuard = new ContentProjectItemActionGuard,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof StartReviewCommand) {
            throw new InvalidArgumentException('Expected StartReviewCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived.',
                    $projectId,
                );
            }

            $query = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->active()
                ->whereIn('status', [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_PENDING])
                ->with(['article']);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
                $query->whereIn('id', $itemIds);
            }

            $tasks = $query->get();
            $affectedIds = [];
            foreach ($tasks as $task) {
                $this->actionGuard->assertCan(
                    ContentProjectItemAction::StartReview,
                    $task,
                    $task->relationLoaded('article') ? $task->article : null,
                );
                $affectedIds[] = (int) $task->getKey();
            }

            if ($affectedIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'No items eligible for review.',
                    $projectId,
                );
            }

            SeoProjectTask::query()
                ->whereIn('id', $affectedIds)
                ->update(['status' => SeoProjectTask::STATUS_REVIEWING]);

            $this->domainEvents->dispatchAfterCommit(new ContentProjectReviewRequested($projectId, $affectedIds));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_REVIEW_STARTED,
                count($affectedIds).' item(s) moved to review.',
                $projectId,
                $affectedIds,
                metadata: ['affected_count' => count($affectedIds)],
            );
        });
    }
}
