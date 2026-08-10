<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectActiveExecution;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;

/**
 * Single source of truth — execution nào còn thực sự active (pending/processing).
 * Không coi terminal / finished_at set / alias failed là active.
 */
final class ContentProjectActiveExecutionResolver
{
    public function findActiveForTask(SeoProjectRun $run, int $taskId): ?ContentProjectActiveExecution
    {
        if ($taskId <= 0) {
            return null;
        }

        $item = $this->activeQuery((int) $run->id)
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->first();

        return $item instanceof SeoProjectRunItem
            ? $this->toActive($item, $run)
            : null;
    }

    public function findActiveForArticle(SeoProjectRun $run, SeoArticle|int $article): ?ContentProjectActiveExecution
    {
        $articleId = $article instanceof SeoArticle ? (int) $article->id : (int) $article;
        if ($articleId <= 0) {
            return null;
        }

        $taskIds = SeoProjectTask::query()
            ->where('project_id', (int) ($run->project_id ?? 0))
            ->where('article_id', $articleId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $query = $this->activeQuery((int) $run->id)
            ->where(function ($inner) use ($articleId, $taskIds): void {
                $inner->where('article_id', $articleId);
                if ($taskIds !== []) {
                    $inner->orWhereIn('task_id', $taskIds);
                }
            });

        $item = $query->orderByDesc('id')->first();

        return $item instanceof SeoProjectRunItem
            ? $this->toActive($item, $run)
            : null;
    }

    public function hasActiveForTask(SeoProjectRun $run, int $taskId): bool
    {
        return $this->findActiveForTask($run, $taskId) instanceof ContentProjectActiveExecution;
    }

    /**
     * @return list<ContentProjectActiveExecution>
     */
    public function listActiveForRun(SeoProjectRun $run, ?int $taskId = null, ?int $articleId = null): array
    {
        $query = $this->activeQuery((int) $run->id);
        if ($taskId !== null && $taskId > 0) {
            $query->where('task_id', $taskId);
        }
        if ($articleId !== null && $articleId > 0) {
            $query->where('article_id', $articleId);
        }

        $out = [];
        foreach ($query->orderBy('id')->get() as $item) {
            if (! $item instanceof SeoProjectRunItem) {
                continue;
            }
            // Defense: bỏ qua row terminal lọt query (status lệch normalize).
            if (! ContentProjectExecutionStatus::isActive((string) $item->status)) {
                continue;
            }
            if ($item->finished_at !== null) {
                continue;
            }
            $out[] = $this->toActive($item, $run);
        }

        return $out;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SeoProjectRunItem>
     */
    private function activeQuery(int $runId)
    {
        return SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('action', 'like', 'step:%')
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->whereNull('finished_at');
    }

    private function toActive(SeoProjectRunItem $item, SeoProjectRun $run): ContentProjectActiveExecution
    {
        $snapshot = is_array($item->input_snapshot) ? $item->input_snapshot : [];
        $nodeId = isset($snapshot['node_id']) ? trim((string) $snapshot['node_id']) : null;
        if ($nodeId === '') {
            $nodeId = isset($snapshot['target_node_id']) ? trim((string) $snapshot['target_node_id']) : null;
        }
        if ($nodeId === '') {
            $nodeId = null;
        }

        $lockKey = null;
        $settings = is_array($run->settings ?? null) ? $run->settings : [];
        $engine = is_array($settings['engine'] ?? null) ? $settings['engine'] : [];
        $activeDispatch = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if (is_array($activeDispatch) && (int) ($activeDispatch['run_item_id'] ?? 0) === (int) $item->id) {
            $lockKey = isset($activeDispatch['token']) ? (string) $activeDispatch['token'] : 'active_dispatch';
        }

        return new ContentProjectActiveExecution(
            runItemId: (int) $item->id,
            runId: (int) $item->run_id,
            taskId: (int) ($item->task_id ?? 0),
            articleId: (int) ($item->article_id ?? 0) ?: null,
            action: (string) $item->action,
            nodeId: $nodeId,
            status: ContentProjectExecutionStatus::normalize((string) $item->status),
            startedAt: $item->started_at?->toDateTimeString(),
            finishedAt: $item->finished_at?->toDateTimeString(),
            createdAt: $item->created_at?->toDateTimeString(),
            updatedAt: $item->updated_at?->toDateTimeString(),
            lockKey: $lockKey,
        );
    }
}
