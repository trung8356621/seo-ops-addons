<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemGenerationReadState;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;

/**
 * Batch projection of the Content Project "Cần biên tập" card.
 *
 * Listing counts and Agent outstanding both call this. The match itself stays
 * {@see ContentProjectRecentlyCompletedDefinition::matches()}.
 */
final class ContentProjectNeedsEditingIndex
{
    /**
     * @param  array<string, mixed>|null  $latestExecution
     * @return array<string, mixed>
     */
    public static function evidenceRow(
        string $taskStatus,
        ?array $latestExecution,
        ?string $contentManagerReviewedAt,
        ?string $articleReviewStatus,
        ?string $observedPostStatus,
        ?string $viewedGenerationCompletedAt,
        ?string $publishQueueStatus = null,
        ?string $publishPublishedAt = null,
        ?string $publishingQueuedAt = null,
    ): array {
        $presented = ContentProjectItemOpsEvidencePresenter::present(
            $taskStatus,
            $latestExecution,
            null,
            [],
        );
        $executionStatus = strtolower(trim((string) ($presented['execution_status'] ?? '')));
        $finishedAt = $latestExecution['finished_at'] ?? $latestExecution['finished_at_iso'] ?? null;
        $finishedAt = $finishedAt instanceof \DateTimeInterface
            ? $finishedAt->format('Y-m-d H:i:s')
            : (is_string($finishedAt) ? trim($finishedAt) : '');
        $reviewedAt = trim((string) $contentManagerReviewedAt);

        return [
            'generation_status' => $presented['generation_status'],
            'execution_status' => $executionStatus,
            'generation_completed_at' => in_array($executionStatus, ['success', 'completed'], true) && $finishedAt !== ''
                ? $finishedAt
                : null,
            'is_genuinely_running' => (bool) ($presented['is_genuinely_running'] ?? false),
            'is_content_manager_reviewed' => $reviewedAt !== '',
            'content_manager_reviewed_at' => $reviewedAt,
            'review_status' => $articleReviewStatus,
            'observed_post_status' => $observedPostStatus,
            'viewed_generation_completed_at' => $viewedGenerationCompletedAt,
            'queue_status' => $publishQueueStatus ?? 'none',
            'publish_published_at' => $publishPublishedAt,
            'publishing_queued_at' => $publishingQueuedAt,
            'has_published_revision' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isOutstanding(array $row): bool
    {
        $status = strtolower(trim((string) ($row['task_status'] ?? '')));
        if (in_array($status, [
            SeoProjectTask::STATUS_ARCHIVED,
            SeoProjectTask::STATUS_CANCELLED,
        ], true)) {
            return false;
        }

        return ContentProjectRecentlyCompletedDefinition::matches($row);
    }

    /**
     * @param  list<SeoProjectTask>  $tasks
     * @param  list<int>  $projectIds
     * @return list<int>
     */
    public static function taskIds(array $tasks, int $viewerId, array $projectIds): array
    {
        $open = array_fill_keys($projectIds, true);
        $candidates = [];
        foreach ($tasks as $task) {
            $projectId = (int) $task->project_id;
            $status = (string) $task->status;
            if (! isset($open[$projectId]) || ! in_array($status, [SeoProjectTask::STATUS_COMPLETED, SeoProjectTask::STATUS_REVIEWING], true)) {
                continue;
            }
            $candidates[(int) $task->id] = $task;
        }
        if ($candidates === []) {
            return [];
        }

        $taskIds = array_keys($candidates);
        $articleIds = [];
        foreach ($candidates as $task) {
            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId > 0) {
                $articleIds[$articleId] = $articleId;
            }
        }

        $reviewByArticle = [];
        if ($articleIds !== []) {
            foreach (SeoArticle::query()->whereIn('id', array_values($articleIds))->get(['id', 'review_status']) as $article) {
                $reviewByArticle[(int) $article->id] = (string) ($article->review_status ?? '');
            }
        }
        $observedByArticle = [];
        if ($articleIds !== []) {
            foreach (WordpressArticleLink::query()->whereIn('article_id', array_values($articleIds))->get(['article_id', 'observed_post_status']) as $link) {
                $observedByArticle[(int) $link->article_id] = (string) ($link->observed_post_status ?? '');
            }
        }
        $viewedByTask = [];
        if ($viewerId > 0) {
            foreach (SeoContentProjectItemGenerationReadState::query()
                ->where('user_id', $viewerId)
                ->whereIn('project_item_id', $taskIds)
                ->get(['project_item_id', 'viewed_generation_completed_at']) as $state) {
                $viewed = $state->viewed_generation_completed_at;
                $viewedByTask[(int) $state->project_item_id] = $viewed instanceof \DateTimeInterface
                    ? $viewed->format('Y-m-d H:i:s')
                    : null;
            }
        }

        $items = [];
        foreach (SeoProjectRunItem::query()
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get(['id', 'task_id', 'run_id', 'status', 'finished_at', 'started_at']) as $item) {
            $finished = $item->finished_at;
            $items[] = [
                'id' => (int) $item->id,
                'task_id' => (int) $item->task_id,
                'run_id' => (int) $item->run_id,
                'status' => (string) $item->status,
                'finished_at' => $finished instanceof \DateTimeInterface ? $finished->format('Y-m-d H:i:s') : null,
                'started_at' => $item->started_at instanceof \DateTimeInterface ? $item->started_at->format('Y-m-d H:i:s') : null,
                'lazy_bulk' => false,
            ];
        }
        $latest = ContentProjectRunItemEvidenceIndex::partition($items, null, null)['latest_execution_by_task'];

        $ids = [];
        foreach ($candidates as $taskId => $task) {
            $articleId = (int) ($task->article_id ?? 0);
            $reviewed = $task->content_manager_reviewed_at;
            $published = $task->publish_published_at;
            $queued = $task->publishing_queued_at;
            $row = self::evidenceRow(
                (string) $task->status,
                $latest[$taskId] ?? null,
                $reviewed instanceof \DateTimeInterface ? $reviewed->format('Y-m-d H:i:s') : null,
                $reviewByArticle[$articleId] ?? null,
                $observedByArticle[$articleId] ?? null,
                $viewedByTask[$taskId] ?? null,
                (string) ($task->publish_queue_status ?? 'none'),
                $published instanceof \DateTimeInterface ? $published->format('Y-m-d H:i:s') : null,
                $queued instanceof \DateTimeInterface ? $queued->format('Y-m-d H:i:s') : null,
            );
            $row['task_status'] = (string) $task->status;
            if (self::isOutstanding($row)) {
                $ids[] = $taskId;
            }
        }

        return $ids;
    }

    /**
     * One task/evidence batch for the projects on the current list query.
     *
     * @param  list<int>  $projectIds
     * @return array<int, int>
     */
    public static function countsByProject(array $projectIds, int $viewerId): array
    {
        $projectIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $projectIds),
            static fn (int $id): bool => $id > 0,
        )));
        $counts = array_fill_keys($projectIds, 0);
        if ($projectIds === []) {
            return $counts;
        }

        $tasks = SeoProjectTask::query()
            ->active()
            ->whereIn('project_id', $projectIds)
            ->where('status', '!=', SeoProjectTask::STATUS_ARCHIVED)
            ->get([
                'id',
                'project_id',
                'status',
                'type',
                'article_id',
                'content_manager_reviewed_at',
                'publish_queue_status',
                'publish_published_at',
                'publishing_queued_at',
            ])
            ->all();
        $outstanding = array_fill_keys(self::taskIds($tasks, $viewerId, $projectIds), true);
        foreach ($tasks as $task) {
            if (! isset($outstanding[(int) $task->id])) {
                continue;
            }
            $projectId = (int) $task->project_id;
            $counts[$projectId] = ($counts[$projectId] ?? 0) + 1;
        }

        return $counts;
    }
}
