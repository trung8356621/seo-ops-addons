<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Điều phối destroy AI Workspace sau khi snapshot Archive đã ghi.
 * Gọi trong transaction DB; disk/cache release sau commit.
 */
final class ContentProjectAiWorkspaceDestroyer
{
    public function __construct(
        private readonly ContentProjectWorkspaceCleanupRegistry $registry,
    ) {}

    /**
     * @return ContentProjectWorkspaceCleanupContext context sau khi clean (chứa disk/cache deferred)
     */
    public function destroyInTransaction(SeoProject $project, array $additionalArticleIds = []): ContentProjectWorkspaceCleanupContext
    {
        $tasks = SeoProjectTask::withTrashed()
            ->where('project_id', (int) $project->getKey())
            ->get(['id', 'article_id']);

        $taskIds = $tasks
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $articleIds = $tasks
            ->pluck('article_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->merge(collect($additionalArticleIds)->map(static fn ($id): int => (int) $id))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $runIds = SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $context = new ContentProjectWorkspaceCleanupContext(
            project: $project,
            articleIds: $articleIds,
            taskIds: $taskIds,
            runIds: $runIds,
        );

        foreach ($this->registry->all() as $cleaner) {
            $cleaner->clean($context);
        }

        RuntimeLogger::info('content_project_workspace_destroyed', [
            'project_id' => (int) $project->getKey(),
            'article_count' => count($articleIds),
            'task_count' => count($taskIds),
            'run_count' => count($runIds),
            'cleaners' => $this->registry->keys(),
            'stats' => $context->stats(),
        ]);

        return $context;
    }

    /**
     * Best-effort sau commit — không throw để tránh nửa trạng thái DB đã commit.
     */
    public function releaseDeferredSideEffects(ContentProjectWorkspaceCleanupContext $context): void
    {
        foreach ($context->diskPathsToDelete() as $path) {
            try {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            } catch (Throwable $e) {
                RuntimeLogger::warning('content_project_workspace_disk_delete_failed', [
                    'project_id' => $context->projectId(),
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        foreach ($context->cacheLockKeys() as $lockKey) {
            try {
                Cache::lock($lockKey, 1)->forceRelease();
            } catch (Throwable) {
                // lock store may not support forceRelease / key absent
            }
        }
    }
}
