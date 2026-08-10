<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Illuminate\Support\Collection;

/**
 * Repair-time canonical picker for duplicate source_key groups (Phase 3C3).
 *
 * @phpstan-type ResolveOut array{
 *     canonical_task_id: int|null,
 *     duplicate_task_ids: list<int>,
 *     reason: string,
 *     confidence: 'high'|'medium'|'low',
 *     manual_review_required: bool,
 *     classification: string
 * }
 */
final class SeoProjectTaskCanonicalResolver
{
    /**
     * @param  Collection<int, SeoProjectTask>|list<SeoProjectTask>  $tasks
     * @return ResolveOut
     */
    public function resolve(Collection|array $tasks): array
    {
        $list = collect($tasks)->values()->filter(
            static fn (mixed $t): bool => $t instanceof SeoProjectTask,
        );

        if ($list->isEmpty()) {
            return [
                'canonical_task_id' => null,
                'duplicate_task_ids' => [],
                'reason' => 'empty',
                'confidence' => 'low',
                'manual_review_required' => true,
                'classification' => 'empty',
            ];
        }

        $ids = $list->map(static fn (SeoProjectTask $t): int => (int) $t->id)->all();

        if ($list->count() === 1) {
            return [
                'canonical_task_id' => (int) $list->first()->id,
                'duplicate_task_ids' => [],
                'reason' => 'single',
                'confidence' => 'high',
                'manual_review_required' => false,
                'classification' => 'single',
            ];
        }

        $alive = $list->filter(static fn (SeoProjectTask $t): bool => $t->deleted_at === null);
        $trashed = $list->filter(static fn (SeoProjectTask $t): bool => $t->deleted_at !== null);

        // A: one alive + soft-deleted orphans
        if ($alive->count() === 1 && $trashed->isNotEmpty()) {
            /** @var SeoProjectTask $canonical */
            $canonical = $alive->first();

            return [
                'canonical_task_id' => (int) $canonical->id,
                'duplicate_task_ids' => $trashed->map(static fn (SeoProjectTask $t): int => (int) $t->id)->values()->all(),
                'reason' => 'active_plus_soft_deleted_orphans',
                'confidence' => 'high',
                'manual_review_required' => false,
                'classification' => 'A',
            ];
        }

        $active = $alive->filter(static fn (SeoProjectTask $t): bool => $t->archived_at === null);
        $archived = $alive->filter(static fn (SeoProjectTask $t): bool => $t->archived_at !== null);

        $withArticle = $alive->filter(static fn (SeoProjectTask $t): bool => (int) ($t->article_id ?? 0) > 0);
        $articleIds = $withArticle
            ->map(static fn (SeoProjectTask $t): int => (int) $t->article_id)
            ->unique()
            ->values();

        // E: multiple different articles
        if ($articleIds->count() > 1) {
            return [
                'canonical_task_id' => null,
                'duplicate_task_ids' => $ids,
                'reason' => 'multiple_distinct_articles',
                'confidence' => 'low',
                'manual_review_required' => true,
                'classification' => 'E',
            ];
        }

        // B: exactly one with article
        if ($withArticle->count() === 1) {
            /** @var SeoProjectTask $canonical */
            $canonical = $withArticle->first();
            $dups = $alive->filter(static fn (SeoProjectTask $t): bool => (int) $t->id !== (int) $canonical->id)
                ->merge($trashed)
                ->map(static fn (SeoProjectTask $t): int => (int) $t->id)
                ->values()
                ->all();

            return [
                'canonical_task_id' => (int) $canonical->id,
                'duplicate_task_ids' => $dups,
                'reason' => 'sole_article_linked',
                'confidence' => 'high',
                'manual_review_required' => false,
                'classification' => 'B',
            ];
        }

        // C: one completed, others pending/failed no article
        $completed = $alive->filter(
            static fn (SeoProjectTask $t): bool => (string) $t->status === SeoProjectTask::STATUS_COMPLETED,
        );
        if ($completed->count() === 1 && $withArticle->isEmpty()) {
            /** @var SeoProjectTask $canonical */
            $canonical = $completed->first();
            $peersOk = $alive->every(static function (SeoProjectTask $t) use ($canonical): bool {
                if ((int) $t->id === (int) $canonical->id) {
                    return true;
                }

                return in_array((string) $t->status, [
                    SeoProjectTask::STATUS_PENDING,
                    SeoProjectTask::STATUS_FAILED,
                    SeoProjectTask::STATUS_CANCELLED,
                    'draft',
                ], true) && (int) ($t->article_id ?? 0) <= 0;
            });

            if ($peersOk) {
                $dups = $alive->filter(static fn (SeoProjectTask $t): bool => (int) $t->id !== (int) $canonical->id)
                    ->merge($trashed)
                    ->map(static fn (SeoProjectTask $t): int => (int) $t->id)
                    ->values()
                    ->all();

                return [
                    'canonical_task_id' => (int) $canonical->id,
                    'duplicate_task_ids' => $dups,
                    'reason' => 'sole_completed',
                    'confidence' => 'high',
                    'manual_review_required' => false,
                    'classification' => 'C',
                ];
            }
        }

        // F: active + archived same identity
        if ($active->isNotEmpty() && $archived->isNotEmpty()) {
            $pick = $this->pickByScore($alive);
            if ($pick === null) {
                return [
                    'canonical_task_id' => null,
                    'duplicate_task_ids' => $ids,
                    'reason' => 'active_and_archived_ambiguous',
                    'confidence' => 'low',
                    'manual_review_required' => true,
                    'classification' => 'F',
                ];
            }

            $dups = $alive->filter(static fn (SeoProjectTask $t): bool => (int) $t->id !== (int) $pick->id)
                ->merge($trashed)
                ->map(static fn (SeoProjectTask $t): int => (int) $t->id)
                ->values()
                ->all();

            return [
                'canonical_task_id' => (int) $pick->id,
                'duplicate_task_ids' => $dups,
                'reason' => 'active_archived_scored',
                'confidence' => 'medium',
                'manual_review_required' => false,
                'classification' => 'F',
            ];
        }

        // D: no articles — score
        $pick = $this->pickByScore($alive->isNotEmpty() ? $alive : $list);
        if ($pick === null) {
            return [
                'canonical_task_id' => null,
                'duplicate_task_ids' => $ids,
                'reason' => 'unscored_ambiguous',
                'confidence' => 'low',
                'manual_review_required' => true,
                'classification' => 'D',
            ];
        }

        $dups = $list->filter(static fn (SeoProjectTask $t): bool => (int) $t->id !== (int) $pick->id)
            ->map(static fn (SeoProjectTask $t): int => (int) $t->id)
            ->values()
            ->all();

        return [
            'canonical_task_id' => (int) $pick->id,
            'duplicate_task_ids' => $dups,
            'reason' => 'deterministic_score',
            'confidence' => 'medium',
            'manual_review_required' => false,
            'classification' => 'D',
        ];
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     */
    private function pickByScore(Collection $tasks): ?SeoProjectTask
    {
        if ($tasks->isEmpty()) {
            return null;
        }

        $ranked = $tasks->sortByDesc(function (SeoProjectTask $task): array {
            return [
                $this->statusRank((string) $task->status),
                $this->relationScore($task),
                $task->archived_at === null ? 1 : 0,
                -1 * (int) $task->id, // older id wins when tie (smaller id = higher when negated desc... wait)
            ];
        });

        // sortByDesc: higher statusRank first; for id we want oldest (smallest id) so use negative id in desc = larger magnitude of older?
        // -id: id=1 -> -1, id=10 -> -10; desc puts -1 before -10, so smaller id wins. Good.

        /** @var SeoProjectTask $first */
        $first = $ranked->first();

        return $first;
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            SeoProjectTask::STATUS_COMPLETED => 100,
            SeoProjectTask::STATUS_REVIEWING => 80,
            SeoProjectTask::STATUS_WRITING, 'processing' => 70,
            SeoProjectTask::STATUS_PENDING => 50,
            SeoProjectTask::STATUS_FAILED => 40,
            'draft' => 30,
            SeoProjectTask::STATUS_CANCELLED => 20,
            SeoProjectTask::STATUS_ARCHIVED => 10,
            default => 0,
        };
    }

    private function relationScore(SeoProjectTask $task): int
    {
        $id = (int) $task->id;
        $runItems = (int) SeoProjectRunItem::query()->where('task_id', $id)->count();
        $events = (int) SeoProjectTaskEvent::query()->where('task_id', $id)->count();
        $links = (int) SeoPromptResultLink::query()->where('project_task_id', $id)->count();
        $article = (int) ($task->article_id ?? 0) > 0 ? 50 : 0;

        return $article + ($runItems * 5) + ($events * 2) + ($links * 3);
    }
}
