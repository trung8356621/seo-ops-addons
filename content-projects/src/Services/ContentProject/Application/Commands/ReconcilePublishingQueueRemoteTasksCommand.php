<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Ops reconcile: WP already published, Laravel queue still open.
 * System/CLI only — no duplicate WordPress create.
 */
final class ReconcilePublishingQueueRemoteTasksCommand implements ContentProjectCommand
{
    /**
     * @param  list<int>  $taskIds
     */
    public function __construct(
        public readonly array $taskIds,
        public readonly bool $dryRun = true,
        public readonly bool $resyncContent = false,
    ) {}

    public function name(): string
    {
        return 'content_project.reconcile_publishing_queue_remote';
    }
}
