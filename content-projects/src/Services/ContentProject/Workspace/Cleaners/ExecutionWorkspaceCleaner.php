<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;

/**
 * Dọn Execution / Run History / Retry History / Stop Token (DB).
 */
final class ExecutionWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'execution';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if ($context->hasRuns()) {
            $runIds = $context->runIds();

            $deletedItems = SeoProjectRunItem::query()
                ->whereIn('run_id', $runIds)
                ->delete();
            $context->bumpStat('run_items_deleted', (int) $deletedItems);

            $deletedEventsByRun = SeoProjectTaskEvent::query()
                ->whereIn('run_id', $runIds)
                ->delete();
            $context->bumpStat('task_events_deleted', (int) $deletedEventsByRun);

            $deletedRuns = SeoProjectRun::query()
                ->whereIn('id', $runIds)
                ->delete();
            $context->bumpStat('runs_deleted', (int) $deletedRuns);
        }

        if ($context->hasTasks()) {
            $deletedEventsByTask = SeoProjectTaskEvent::query()
                ->whereIn('task_id', $context->taskIds())
                ->delete();
            $context->bumpStat('task_events_deleted', (int) $deletedEventsByTask);
        }

        if ($context->hasArticles() && $context->hasTasks()) {
            $deletedNullRunItems = SeoProjectRunItem::query()
                ->whereIn('article_id', $context->articleIds())
                ->whereIn('task_id', $context->taskIds())
                ->whereNull('run_id')
                ->delete();
            $context->bumpStat('run_items_deleted', (int) $deletedNullRunItems);
        }
    }
}
