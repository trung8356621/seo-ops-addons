<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use InvalidArgumentException;

final class CancelProjectItemPublishingHandler extends AbstractPublishingHandler
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
        if (! $command instanceof CancelProjectItemPublishingCommand) {
            throw new InvalidArgumentException('Expected CancelProjectItemPublishingCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
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

            $fingerprint = $this->buildFingerprint($command->name(), $projectId, [
                'item_ids' => $itemIds,
            ]);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $itemIds,
                    $fingerprint,
                    [
                        'action' => 'cancel_publish',
                        'items' => array_map(
                            static fn (int $id): array => ['item_id' => $id, 'status' => 'cancelled'],
                            $itemIds,
                        ),
                    ],
                );
            }

            $confirmationFailure = $this->assertConfirmationToken(
                $command->confirmationToken ?? $actor->confirmationToken,
                $fingerprint,
                required: $this->requiresConfirmation($actor, $command->confirmationToken ?? $actor->confirmationToken),
                projectId: $projectId,
            );
            if ($confirmationFailure instanceof ContentProjectActionResult) {
                return $confirmationFailure;
            }

            $result = $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $itemIds): ContentProjectActionResult {
                    $affected = $this->queueService->cancelPublish($project, $itemIds);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_PUBLISH_CANCELLED,
                        "{$affected} item(s) publish cancelled.",
                        $projectId,
                        $itemIds,
                        metadata: ['affected_count' => $affected],
                    );
                },
            );

            if ($result->success) {
                $this->consumeConfirmationToken($command->confirmationToken ?? $actor->confirmationToken);
            }

            return $result;
        });
    }
}
