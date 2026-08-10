<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Collection;

/**
 * Explicit duplicate candidate resolver — không chọn đại âm thầm.
 *
 * @phpstan-type ResolveResult array{
 *     status: 'resolved'|'ambiguous'|'empty',
 *     task: SeoProjectTask|null,
 *     reason: string|null,
 *     candidate_task_ids: list<int>
 * }
 */
final class SeoProjectTaskCanonicalCandidateResolver
{
    /**
     * @param  Collection<int, SeoProjectTask>|list<SeoProjectTask>  $candidates
     * @return ResolveResult
     */
    public function resolve(Collection|array $candidates): array
    {
        $list = $candidates instanceof Collection
            ? $candidates->values()
            : collect($candidates)->values();

        $ids = $list
            ->map(static fn (SeoProjectTask $task): int => (int) $task->id)
            ->all();

        if ($list->isEmpty()) {
            return [
                'status' => 'empty',
                'task' => null,
                'reason' => null,
                'candidate_task_ids' => [],
            ];
        }

        if ($list->count() === 1) {
            /** @var SeoProjectTask $only */
            $only = $list->first();

            return [
                'status' => 'resolved',
                'task' => $only,
                'reason' => 'single_candidate',
                'candidate_task_ids' => $ids,
            ];
        }

        $withArticle = $list->filter(
            static fn (SeoProjectTask $task): bool => (int) ($task->article_id ?? 0) > 0,
        );
        $withoutArticle = $list->filter(
            static fn (SeoProjectTask $task): bool => (int) ($task->article_id ?? 0) <= 0,
        );

        if ($withArticle->count() === 1 && $withoutArticle->count() === $list->count() - 1) {
            /** @var SeoProjectTask $canonical */
            $canonical = $withArticle->first();

            return [
                'status' => 'resolved',
                'task' => $canonical,
                'reason' => 'sole_article_linked',
                'candidate_task_ids' => $ids,
            ];
        }

        if ($withArticle->count() > 1) {
            return [
                'status' => 'ambiguous',
                'task' => null,
                'reason' => 'multiple_article_linked',
                'candidate_task_ids' => $ids,
            ];
        }

        $completed = $list->filter(
            static fn (SeoProjectTask $task): bool => (string) $task->status === SeoProjectTask::STATUS_COMPLETED,
        );
        $others = $list->filter(
            static fn (SeoProjectTask $task): bool => (string) $task->status !== SeoProjectTask::STATUS_COMPLETED
                && (int) ($task->article_id ?? 0) <= 0
                && in_array((string) $task->status, [
                    SeoProjectTask::STATUS_PENDING,
                    SeoProjectTask::STATUS_FAILED,
                    'cancelled',
                    'draft',
                ], true),
        );

        if ($completed->count() === 1 && $others->count() === $list->count() - 1) {
            /** @var SeoProjectTask $canonical */
            $canonical = $completed->first();

            return [
                'status' => 'resolved',
                'task' => $canonical,
                'reason' => 'sole_completed_without_article_peers',
                'candidate_task_ids' => $ids,
            ];
        }

        return [
            'status' => 'ambiguous',
            'task' => null,
            'reason' => 'no_absolute_winner',
            'candidate_task_ids' => $ids,
        ];
    }

    /**
     * @deprecated Phase 3C2 — wrapper cho preferTaskForSyncPreserve cũ.
     */
    public function preferLegacy(SeoProjectTask $current, SeoProjectTask $candidate): SeoProjectTask
    {
        $resolved = $this->resolve([$current, $candidate]);
        if ($resolved['status'] === 'resolved' && $resolved['task'] instanceof SeoProjectTask) {
            return $resolved['task'];
        }

        return (int) $candidate->id >= (int) $current->id ? $candidate : $current;
    }
}
