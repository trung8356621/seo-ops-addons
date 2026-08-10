<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedDefinition;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReturnToContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use InvalidArgumentException;

/**
 * Publishing Queue → Content Project working set. Blocked after Published.
 */
final class ReturnToContentProjectHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectPublishingQueueService $queueService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ReturnToContentProjectCommand) {
            throw new InvalidArgumentException('Expected ReturnToContentProjectCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            if (! SeoAccessControl::canManageContentProjectWorkflow()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Content Manager cannot return items from Publishing Queue.',
                );
            }

            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }
            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            $tasks = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->whereIn('id', $itemIds)
                ->whereNotNull('publishing_queued_at')
                ->whereNull('archived_at')
                ->get();

            $eligible = [];
            foreach ($tasks as $task) {
                $row = [
                    'lifecycle' => '',
                    'queue_status' => (string) ($task->publish_queue_status ?? 'none'),
                    'publish_published_at' => $task->publish_published_at?->toIso8601String(),
                ];
                if (ContentProjectPublishedDefinition::matches($row)) {
                    continue;
                }
                $eligible[] = (int) $task->getKey();
            }

            if ($eligible === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No returnable items (Published cannot return via this action).',
                    $projectId,
                );
            }

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $eligible,
                    $this->buildFingerprint($command->name(), $projectId, ['item_ids' => $eligible]),
                    ['action' => 'return_to_content_project', 'items' => $eligible],
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $eligible): ContentProjectActionResult {
                    $affected = $this->queueService->returnToContentProject($project, $eligible);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_RETURNED_TO_CONTENT_PROJECT,
                        'Returned to Content Project.',
                        $projectId,
                        $eligible,
                        metadata: [
                            'affected_count' => $affected,
                            'wordpress_called' => false,
                        ],
                    );
                },
            );
        });
    }
}
