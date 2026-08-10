<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;

/**
 * Recover stuck Publishing — không WordPress, không normal Cancel transition.
 */
final class RecoverStuckPublishingHandler extends AbstractPublishingHandler
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
        if (! $command instanceof RecoverStuckPublishingCommand) {
            throw new InvalidArgumentException('Expected RecoverStuckPublishingCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            if ($actor->actorType === 'user' && ! SeoAccessControl::canManageContentProjectWorkflow()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Không có quyền recover stuck publishing.',
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

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $itemIds,
                    $this->buildFingerprint($command->name(), $projectId, [
                        'item_ids' => $itemIds,
                        'target' => $command->target,
                    ]),
                    [
                        'action' => 'recover_stuck_publishing',
                        'target' => $command->target,
                        'item_count' => count($itemIds),
                    ],
                    requiresConfirmation: false,
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $itemIds, $command): ContentProjectActionResult {
                    $force = property_exists($command, 'force') && (bool) $command->force;
                    $stats = app(\Omnichannel\Addons\Publishing\Services\Publishing\PublishingStuckRecoveryService::class)
                        ->recoverNow($project, $itemIds, dryRun: false, force: $force);

                    $recovered = (int) $stats['affected'];
                    $skipped = (int) $stats['skipped'];
                    $failed = (int) ($stats['failed'] ?? 0);

                    if ($recovered === 0 && $skipped === 0 && $failed === 0) {
                        $message = 'Không có bài nào cần khôi phục.';
                    } elseif ($recovered === 0 && $skipped > 0) {
                        $message = sprintf(
                            'Không có bài nào cần khôi phục. %d bài vẫn đang xuất bản.',
                            $skipped,
                        );
                    } else {
                        $message = sprintf(
                            'Đã khôi phục %d bài%s%s.',
                            $recovered,
                            $skipped > 0 ? sprintf('; %d bài vẫn đang xuất bản', $skipped) : '',
                            $failed > 0 ? sprintf('; %d lỗi', $failed) : '',
                        );
                    }

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_PUBLISH_RECOVERED,
                        $message,
                        $projectId,
                        $itemIds,
                        metadata: [
                            'affected_count' => $recovered,
                            'skipped_active' => $skipped,
                            'failed' => $failed,
                            'target' => $command->target,
                            'force' => $force,
                            'wordpress_reconciled' => true,
                            'batch_id' => $stats['batch_id'],
                            'stats' => $stats,
                        ],
                    );
                },
            );
        });
    }
}
