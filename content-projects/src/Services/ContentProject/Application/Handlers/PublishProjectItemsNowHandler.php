<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\Publishing\Services\Publishing\DispatchClaimResult;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemOutcome;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemService;
use InvalidArgumentException;

final class PublishProjectItemsNowHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly PublishDueItemService $dueItemService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof PublishProjectItemsNowCommand) {
            throw new InvalidArgumentException('Expected PublishProjectItemsNowCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $draftBlock = ContentProjectDraftExecutionGuard::rejectIfDraft($project, $projectId);
            if ($draftBlock !== null) {
                return $draftBlock;
            }

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
                        'action' => 'publish_now',
                        'items' => array_map(
                            static fn (int $id): array => ['item_id' => $id, 'publish_at' => now()->toIso8601String()],
                            $itemIds,
                        ),
                    ],
                );
            }

            $token = $command->confirmationToken ?? $actor->confirmationToken;
            $confirmationFailure = $this->assertConfirmationToken(
                $token,
                $fingerprint,
                required: $this->requiresConfirmation($actor, $token),
                projectId: $projectId,
            );
            if ($confirmationFailure instanceof ContentProjectActionResult) {
                return $confirmationFailure;
            }

            $outcomes = [];
            foreach ($itemIds as $itemId) {
                $outcomes[] = $this->businessLock->withLock(
                    $this->businessLock->itemPublish($itemId),
                    fn (): PublishDueItemOutcome => $this->dueItemService->execute(
                        $itemId,
                        PublishDueItemService::TRIGGER_PUBLISH_NOW,
                    ),
                );
            }

            $this->consumeConfirmationToken($command->confirmationToken ?? $actor->confirmationToken);
            $summary = $this->summarize($outcomes);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
                $summary['message'],
                $projectId,
                $itemIds,
                metadata: $summary,
            );
        });
    }

    /**
     * @param  list<PublishDueItemOutcome>  $outcomes
     * @return array<string, mixed>
     */
    private function summarize(array $outcomes): array
    {
        $started = 0;
        $alreadyActive = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($outcomes as $o) {
            if ($o->outcome === PublishDueItemOutcome::AWAITING_DELIVERY
                || $o->outcome === PublishDueItemOutcome::PUBLISHED
            ) {
                $started++;
            } elseif ($o->claimCode === DispatchClaimResult::ACTIVE_PUBLISH
                || $o->reason === DispatchClaimResult::ACTIVE_PUBLISH
            ) {
                $alreadyActive++;
            } elseif (in_array($o->outcome, [PublishDueItemOutcome::FAILED, PublishDueItemOutcome::ERROR], true)) {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return [
            'affected_count' => count($outcomes),
            'started' => $started,
            'already_active' => $alreadyActive,
            'failed' => $failed,
            'skipped' => $skipped,
            'outcomes' => array_map(static fn (PublishDueItemOutcome $o): array => $o->toLogArray(), $outcomes),
            'message' => sprintf(
                'Publish now: %d bắt đầu, %d đang xuất bản, %d lỗi, %d bỏ qua.',
                $started,
                $alreadyActive,
                $failed,
                $skipped,
            ),
        ];
    }
}
