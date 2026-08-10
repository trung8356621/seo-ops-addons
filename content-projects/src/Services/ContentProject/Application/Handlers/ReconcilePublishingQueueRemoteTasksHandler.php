<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReconcilePublishingQueueRemoteTasksCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingQueueRemoteReconcileService;
use InvalidArgumentException;

/**
 * CommandBus entry for safe WordPress ↔ Publishing Queue reconcile (+ optional content resync).
 */
final class ReconcilePublishingQueueRemoteTasksHandler implements ContentProjectCommandHandler
{
    public function __construct(
        private readonly PublishingQueueRemoteReconcileService $reconcile,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ReconcilePublishingQueueRemoteTasksCommand) {
            throw new InvalidArgumentException('Expected ReconcilePublishingQueueRemoteTasksCommand.');
        }

        $dryRun = $command->dryRun || $actor->dryRun;
        $taskIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $command->taskIds),
            static fn (int $id): bool => $id > 0,
        ));

        if ($taskIds === []) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Task id list is empty.',
            );
        }

        $rows = $this->reconcile->classifyTasks($taskIds, $dryRun);
        $resyncRows = [];
        if ($command->resyncContent) {
            foreach ($taskIds as $taskId) {
                $resyncRows[] = $this->reconcile->resyncContent($taskId, $dryRun);
            }
        }

        $applied = array_values(array_filter(
            array_map(static fn (array $row): int => ! empty($row['applied']) ? (int) $row['task_id'] : 0, $rows),
            static fn (int $id): bool => $id > 0,
        ));

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::ITEMS_PUBLISH_RECONCILED,
            $dryRun
                ? 'Dry-run classify complete (no writes).'
                : 'Remote reconcile applied where remote_published_matching.',
            null,
            $applied,
            metadata: [
                'dry_run' => $dryRun,
                'resync_content' => $command->resyncContent,
                'results' => $rows,
                'resync_results' => $resyncRows,
                'task_count' => count($taskIds),
            ],
        );
    }
}
