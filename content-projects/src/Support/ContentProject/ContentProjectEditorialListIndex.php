<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemGenerationReadState;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;

/**
 * Batch list KPIs for editorial mode — Normal / Cần biên tập / Đã biên tập.
 *
 * Uses the same predicates as Project Details ops cards
 * ({@see ContentProjectOpsStateClassifier}, {@see ContentProjectRecentlyCompletedDefinition},
 * {@see ContentProjectInReviewReportingDefinition}).
 */
final class ContentProjectEditorialListIndex
{
    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{normal: int, needs_review: int, review: int}>
     */
    public static function countsByProject(array $projectIds, int $viewerId, bool $contentManagerPresentation): array
    {
        $projectIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $projectIds),
            static fn (int $id): bool => $id > 0,
        )));
        $empty = ['normal' => 0, 'needs_review' => 0, 'review' => 0];
        $counts = array_fill_keys($projectIds, $empty);
        if ($projectIds === []) {
            return $counts;
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('project_id', $projectIds)
            ->planned()
            ->inContentProjectWorkingSet()
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
        if ($tasks === []) {
            return $counts;
        }

        $taskIds = array_map(static fn (SeoProjectTask $task): int => (int) $task->id, $tasks);
        $articleIds = [];
        foreach ($tasks as $task) {
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

        foreach ($tasks as $task) {
            $projectId = (int) $task->project_id;
            $taskId = (int) $task->id;
            $articleId = (int) ($task->article_id ?? 0);
            $status = (string) $task->status;
            $type = (string) ($task->type ?? '');
            $ungenerated = SeoProjectTask::isUngeneratedPlanStatus($status, $type, $articleId);

            if (! $contentManagerPresentation || ! $ungenerated) {
                $counts[$projectId]['normal']++;
            }

            $reviewed = $task->content_manager_reviewed_at;
            $published = $task->publish_published_at;
            $queued = $task->publishing_queued_at;
            $row = ContentProjectNeedsEditingIndex::evidenceRow(
                $status,
                $latest[$taskId] ?? null,
                $reviewed instanceof \DateTimeInterface ? $reviewed->format('Y-m-d H:i:s') : null,
                $reviewByArticle[$articleId] ?? null,
                $observedByArticle[$articleId] ?? null,
                $viewedByTask[$taskId] ?? null,
                (string) ($task->publish_queue_status ?? 'none'),
                $published instanceof \DateTimeInterface ? $published->format('Y-m-d H:i:s') : null,
                $queued instanceof \DateTimeInterface ? $queued->format('Y-m-d H:i:s') : null,
            );
            $row['task_status'] = $status;
            $row['type'] = $type;
            $row['article_id'] = $articleId;

            $classified = ContentProjectOpsStateClassifier::classify($row);
            if ($classified['summary_bucket'] === ContentProjectOpsStateClassifier::BUCKET_NEEDS_REVIEW) {
                $counts[$projectId]['needs_review']++;
            } elseif ($classified['summary_bucket'] === ContentProjectOpsStateClassifier::BUCKET_IN_REVIEW) {
                $counts[$projectId]['review']++;
            }
        }

        return $counts;
    }
}
