<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\DebugOverrideProjectItemLifecycleCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDebugLifecycleOverrideService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;
use Throwable;

final class DebugOverrideProjectItemLifecycleHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectDebugLifecycleOverrideService $overrideService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof DebugOverrideProjectItemLifecycleCommand) {
            throw new InvalidArgumentException('Expected DebugOverrideProjectItemLifecycleCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            if (! SeoAccessControl::canDebugContentProjectLifecycle()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Debug lifecycle override is disabled or not allowed for this actor.',
                    null,
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

            try {
                $result = $this->overrideService->apply(
                    $project,
                    $itemIds,
                    $command->toLifecycle,
                    $command->scheduledAt,
                    $command->note,
                );
            } catch (Throwable $e) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $e->getMessage(),
                    $projectId,
                );
            }

            if ($result['rejected'] !== []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Debug override rejected — fix items before retry.',
                    $projectId,
                    metadata: [
                        'rejected' => $result['rejected'],
                        'reason' => ContentProjectDebugLifecycleOverrideService::REASON,
                        'wordpress_called' => false,
                    ],
                );
            }

            if ($result['affected_ids'] === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'No items overridden.',
                    $projectId,
                );
            }

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_DEBUG_LIFECYCLE_OVERRIDDEN,
                'Debug lifecycle override applied (no WordPress call).',
                $projectId,
                $result['affected_ids'],
                metadata: [
                    'reason' => ContentProjectDebugLifecycleOverrideService::REASON,
                    'to_lifecycle' => $command->toLifecycle,
                    'transitions' => $result['transitions'],
                    'note' => $command->note,
                    'wordpress_called' => false,
                    'publisher_dispatched' => false,
                ],
            );
        });
    }
}
