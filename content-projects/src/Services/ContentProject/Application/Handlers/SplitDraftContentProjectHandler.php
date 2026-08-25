<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use InvalidArgumentException;

final class SplitDraftContentProjectHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SplitDraftContentProjectService $splitter,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SplitDraftContentProjectCommand) {
            throw new InvalidArgumentException('Expected SplitDraftContentProjectCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $draft = $this->resolveProject($command->projectRef);
            $projectId = (int) $draft->getKey();
            $this->tenantGuard->assertCanAccessProject($draft, $actor);

            if (! $draft->isDraftPlanning()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_NOT_DRAFT,
                    'Only Draft projects can be split.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);

            try {
                $preview = $this->splitter->preview(
                    $draft,
                    $command->selectionMode,
                    $command->quantity,
                    $itemIds,
                    $command->targetMonth,
                    $command->projectName,
                );
            } catch (InvalidArgumentException $e) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $e->getMessage(),
                    $projectId,
                );
            }

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return ContentProjectActionResult::ok(
                    ContentProjectActionCodes::PREVIEW_READY,
                    'Preview ready.',
                    $projectId,
                    metadata: array_merge($preview, [
                        'requires_confirmation' => false,
                    ]),
                );
            }

            if ((int) ($preview['moved_count'] ?? 0) <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No Draft items to move.',
                    $projectId,
                );
            }

            try {
                $result = $this->businessLock->withLock(
                    $this->businessLock->projectArchive($projectId),
                    function () use ($draft, $command, $itemIds, $actor): array {
                        return $this->splitter->split(
                            $draft,
                            $command->selectionMode,
                            $command->quantity,
                            $itemIds,
                            $command->targetMonth,
                            $command->projectName,
                            $actor->actorId,
                        );
                    },
                );
            } catch (InvalidArgumentException $e) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $e->getMessage(),
                    $projectId,
                );
            }

            $executionId = (int) ($result['execution_project_id'] ?? 0);
            $moved = (int) ($result['moved_count'] ?? 0);
            $remaining = (int) ($result['remaining_count'] ?? 0);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::DRAFT_SPLIT,
                sprintf(
                    '%d items moved · %d remain in Draft',
                    $moved,
                    $remaining,
                ),
                $projectId,
                $result['task_ids'] ?? [],
                metadata: $result,
            );
        });
    }
}
