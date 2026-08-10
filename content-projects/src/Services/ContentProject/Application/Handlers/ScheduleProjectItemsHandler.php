<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use InvalidArgumentException;

final class ScheduleProjectItemsHandler extends AbstractPublishingHandler
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
        if (! $command instanceof ScheduleProjectItemsCommand) {
            throw new InvalidArgumentException('Expected ScheduleProjectItemsCommand.');
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
                'scheduled_at' => $command->scheduledAt->toIso8601String(),
            ]);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $itemIds,
                    $fingerprint,
                    [
                        'action' => 'schedule',
                        'scheduled_at' => $command->scheduledAt->toIso8601String(),
                        'items' => array_map(
                            static fn (int $id): array => [
                                'item_id' => $id,
                                'scheduled_at' => $command->scheduledAt->toIso8601String(),
                            ],
                            $itemIds,
                        ),
                    ],
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $itemIds, $command): ContentProjectActionResult {
                    $report = $this->queueService->scheduleWithReport($project, $itemIds, $command->scheduledAt);
                    $message = sprintf(
                        'Đã đổi lịch %d bài. Bỏ qua %d bài đang xuất bản.',
                        (int) $report['scheduled'],
                        (int) $report['skipped_active'],
                    );
                    if ((int) $report['cancelled_pending'] > 0) {
                        $message .= sprintf(' Đã hủy %d lần chờ worker.', (int) $report['cancelled_pending']);
                    }
                    if ((int) $report['failed'] > 0) {
                        $message .= sprintf(' %d không đổi được.', (int) $report['failed']);
                    }

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_SCHEDULED,
                        $message,
                        $projectId,
                        $itemIds,
                        metadata: [
                            'affected_count' => (int) $report['scheduled'],
                            'scheduled' => (int) $report['scheduled'],
                            'skipped_active' => (int) $report['skipped_active'],
                            'cancelled_pending' => (int) $report['cancelled_pending'],
                            'failed' => (int) $report['failed'],
                            'scheduled_at' => $command->scheduledAt->toIso8601String(),
                            'report' => $report,
                        ],
                    );
                },
            );
        });
    }
}
