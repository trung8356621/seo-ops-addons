<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class EloquentAgentWorkHistoryReader implements AgentWorkHistoryReader
{
    public function __construct(private readonly EloquentAgentWorkStatsReader $outstanding) {}

    public function inspect(AgentActorAccessContext $context, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $projects = $this->projects($context);
        $tasks = SeoProjectTask::query()->whereIn('project_id', (clone $projects)->select('id'));
        $planned = (clone $tasks)->planned()->count();
        $entered = (clone $tasks)
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->where(static function (Builder $query): void {
                $query->where('status', '!=', SeoProjectTask::STATUS_PENDING)
                    ->orWhere(static function (Builder $create): void {
                        $create->where('type', SeoProjectTask::TYPE_CREATE)
                            ->whereNotNull('article_id')
                            ->where('article_id', '>', 0);
                    });
            })
            ->count();
        $completed = (clone $tasks)
            ->whereNull('archived_at')
            ->where('status', SeoProjectTask::STATUS_COMPLETED)
            ->count();
        $archived = (clone $tasks)->where(static function (Builder $query) use ($projects): void {
            $query->whereNotNull('archived_at')
                ->orWhere('status', SeoProjectTask::STATUS_ARCHIVED)
                ->orWhereIn('project_id', (clone $projects)->whereNotNull('archived_at')->select('id'));
        })->count();
        $total = (clone $tasks)->count();
        $openProjectIds = (clone $projects)
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProject::STATUS_COMPLETED)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'planned' => $planned,
            'entered_workflow' => $entered,
            'completed' => $completed,
            'outstanding' => $this->outstandingCount($openProjectIds, (int) $context->userId),
            'archived' => $archived,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'rows' => $this->pageRows($tasks, $projects, $openProjectIds, (int) $context->userId, $page, $perPage, $total),
        ];
    }

    /**
     * @return Builder<SeoProject>
     */
    private function projects(AgentActorAccessContext $context): Builder
    {
        $query = SeoProject::query()->where(static function (Builder $inner): void {
            $inner->whereNull('kind')->orWhere('kind', '!=', SeoProject::KIND_ARCHIVE);
        });
        if ($context->role === SeoAccessControl::ROLE_CONTENT_MANAGER) {
            return $query->where('user_id', $context->userId);
        }

        $siteIds = $context->siteIds;

        return $query->where(static function (Builder $inner) use ($siteIds): void {
            $inner->whereIn('site_id', $siteIds === [] ? [-1] : $siteIds)->orWhereNull('site_id');
        });
    }

    /**
     * @param  list<int>  $openProjectIds
     */
    private function outstandingCount(array $openProjectIds, int $viewerId): int
    {
        if ($openProjectIds === [] || $viewerId <= 0) {
            return 0;
        }

        $count = 0;
        SeoProjectTask::query()
            ->active()
            ->whereIn('project_id', $openProjectIds)
            ->whereIn('status', [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_REVIEWING])
            ->orderBy('id')
            ->select([
                'id',
                'project_id',
                'status',
                'article_id',
                'content_manager_reviewed_at',
                'publish_queue_status',
                'publish_published_at',
                'publishing_queued_at',
            ])
            ->chunkById(100, function ($chunk) use (&$count, $viewerId, $openProjectIds): void {
                $count += count($this->outstanding->needsReviewTaskIds($chunk->all(), $viewerId, $openProjectIds));
            });

        return $count;
    }

    /**
     * @param  Builder<SeoProjectTask>  $tasks
     * @param  Builder<SeoProject>  $projects
     * @param  list<int>  $openProjectIds
     * @return list<array<string, mixed>>
     */
    private function pageRows(
        Builder $tasks,
        Builder $projects,
        array $openProjectIds,
        int $viewerId,
        int $page,
        int $perPage,
        int $total,
    ): array {
        if ($total <= 0 || (($page - 1) * $perPage) >= $total) {
            return [];
        }

        $taskRows = (clone $tasks)
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get([
                'id',
                'project_id',
                'status',
                'type',
                'article_id',
                'archived_at',
                'content_manager_reviewed_at',
                'publish_queue_status',
                'publish_published_at',
                'publishing_queued_at',
            ]);
        $projectIds = $taskRows->pluck('project_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $projectRows = $projectIds === []
            ? collect()
            : (clone $projects)->whereIn('id', $projectIds)->get(['id', 'name', 'month', 'status', 'archived_at'])->keyBy('id');
        $open = array_fill_keys($openProjectIds, true);
        $outstandingIds = array_fill_keys(
            $this->outstanding->needsReviewTaskIds($taskRows->all(), $viewerId, $openProjectIds),
            true,
        );
        $rows = [];
        foreach ($taskRows as $task) {
            $project = $projectRows->get((int) $task->project_id);
            $projectArchived = $project !== null && $project->archived_at !== null;
            $taskArchived = $task->archived_at !== null;
            $outstanding = isset($open[(int) $task->project_id]) && isset($outstandingIds[(int) $task->id]);
            $month = $project?->month;
            $rows[] = [
                'project_id' => (int) $task->project_id,
                'project_name' => $project !== null && trim((string) $project->name) !== ''
                    ? (string) $project->name
                    : 'Content Project '.(int) $task->project_id,
                'month' => $month ? Carbon::parse($month)->format('m/Y') : '',
                'task_id' => (int) $task->id,
                'article_id' => (int) ($task->article_id ?? 0),
                'type' => (string) ($task->type ?? ''),
                'status' => (string) $task->status,
                'classification' => WorkHistoryRecord::classification(
                    (string) $task->status,
                    (string) ($task->type ?? ''),
                    $task->article_id,
                    $taskArchived,
                    $projectArchived,
                    $outstanding,
                ),
                'outstanding' => $outstanding,
            ];
        }

        return $rows;
    }
}
