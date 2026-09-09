<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Single ownership SSOT: whether a PromptResult belongs to an Article.
 *
 * Shared by ArticlePromptRunHistoryService (list) and ArticleAiCallRawDetailService (detail).
 * Invariant: if History legitimately displays PromptResult X for Article Y, detail must pass.
 */
final class ArticlePromptResultOwnershipResolver
{
    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function isOwned(int $articleId, int $promptResultId, array $accessibleProjectIds = []): bool
    {
        if ($articleId <= 0 || $promptResultId <= 0) {
            return false;
        }

        if ($this->ownedViaLegacyLink($articleId, $promptResultId, $accessibleProjectIds)) {
            return true;
        }

        $result = PromptResult::query()->find($promptResultId);
        if (! $result instanceof PromptResult) {
            return false;
        }

        // Execution snapshot — same evidence History uses for orphan/editor cards.
        if ($this->snapshotArticleId($result) === $articleId) {
            return true;
        }

        if ($this->ownedViaParentChildSnapshot($articleId, $promptResultId)) {
            return true;
        }

        if ($this->ownedViaContentProjectCorrelation($articleId, $result, $accessibleProjectIds)) {
            return true;
        }

        if ($this->ownedViaRunItemSteps($articleId, $promptResultId, $accessibleProjectIds)) {
            return true;
        }

        return false;
    }

    /**
     * Apply the input_snapshot article_id ownership constraint used by History discovery.
     *
     * @param  Builder<PromptResult>  $query
     * @return Builder<PromptResult>
     */
    public function constrainToSnapshotArticle(Builder $query, int $articleId): Builder
    {
        return $query->where(function (Builder $inner) use ($articleId): void {
            $inner
                ->where('input_snapshot->article_id', (string) $articleId)
                ->orWhere('input_snapshot->article_id', $articleId)
                ->orWhere('input_snapshot->variables->article_id', (string) $articleId)
                ->orWhere('input_snapshot->variables->article_id', $articleId);
        });
    }

    public function snapshotArticleId(PromptResult $result): int
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];

        return (int) ($snapshot['article_id'] ?? $variables['article_id'] ?? 0);
    }

    /**
     * Pure helper for unit tests / callers without hydrating a model.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function articleIdFromSnapshot(array $snapshot): int
    {
        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];

        return (int) ($snapshot['article_id'] ?? $variables['article_id'] ?? 0);
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    private function ownedViaLegacyLink(int $articleId, int $promptResultId, array $accessibleProjectIds): bool
    {
        $accessibleRunIds = $this->accessibleRunIds($accessibleProjectIds);

        return SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->where('prompt_result_id', $promptResultId)
            ->where(function (Builder $query) use ($accessibleRunIds): void {
                // Match History: null project_run_id is valid (editor / non-project calls).
                $query->whereNull('project_run_id');
                if ($accessibleRunIds !== []) {
                    $query->orWhereIn('project_run_id', $accessibleRunIds);
                }
            })
            ->exists();
    }

    private function ownedViaParentChildSnapshot(int $articleId, int $promptResultId): bool
    {
        $parents = $this->constrainToSnapshotArticle(
            PromptResult::query()->select(['id', 'input_snapshot']),
            $articleId,
        )->get();

        foreach ($parents as $parent) {
            if (! $parent instanceof PromptResult) {
                continue;
            }
            $snap = is_array($parent->input_snapshot) ? $parent->input_snapshot : [];
            foreach (is_array($snap['child_prompt_result_ids'] ?? null) ? $snap['child_prompt_result_ids'] : [] as $childId) {
                if ((int) $childId === $promptResultId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    private function ownedViaContentProjectCorrelation(
        int $articleId,
        PromptResult $result,
        array $accessibleProjectIds,
    ): bool {
        if ($accessibleProjectIds === []) {
            return false;
        }

        $runId = (int) ($result->run_id ?? 0);
        $projectId = (int) ($result->content_project_id ?? 0);
        $itemId = (int) ($result->project_item_id ?? 0);

        if ($projectId > 0 && ! in_array($projectId, $accessibleProjectIds, true)) {
            return false;
        }

        if ($runId > 0) {
            $run = SeoProjectRun::query()->find($runId);
            if (! $run instanceof SeoProjectRun) {
                return false;
            }
            $runProjectId = (int) ($run->project_id ?? 0);
            if ($runProjectId <= 0 || ! in_array($runProjectId, $accessibleProjectIds, true)) {
                return false;
            }

            $itemOwns = SeoProjectRunItem::query()
                ->where('run_id', $runId)
                ->where('article_id', $articleId)
                ->exists();
            if ($itemOwns) {
                return true;
            }

            $taskOwns = SeoProjectTask::query()
                ->where('article_id', $articleId)
                ->where('project_id', $runProjectId)
                ->whereIn('id', SeoProjectRunItem::query()
                    ->where('run_id', $runId)
                    ->whereNotNull('task_id')
                    ->select('task_id'))
                ->exists();
            if ($taskOwns) {
                return true;
            }
        }

        if ($itemId > 0) {
            $task = SeoProjectTask::query()->find($itemId);
            if ($task instanceof SeoProjectTask
                && (int) ($task->article_id ?? 0) === $articleId
                && in_array((int) ($task->project_id ?? 0), $accessibleProjectIds, true)
            ) {
                return true;
            }

            $runItem = SeoProjectRunItem::query()->find($itemId);
            if ($runItem instanceof SeoProjectRunItem && (int) ($runItem->article_id ?? 0) === $articleId) {
                $itemRunId = (int) ($runItem->run_id ?? 0);
                if ($itemRunId > 0) {
                    $itemRun = SeoProjectRun::query()->find($itemRunId);
                    if ($itemRun instanceof SeoProjectRun
                        && in_array((int) ($itemRun->project_id ?? 0), $accessibleProjectIds, true)
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    private function ownedViaRunItemSteps(int $articleId, int $promptResultId, array $accessibleProjectIds): bool
    {
        $accessibleRunIds = $this->accessibleRunIds($accessibleProjectIds);
        if ($accessibleRunIds === []) {
            return false;
        }

        $items = SeoProjectRunItem::query()
            ->whereIn('run_id', $accessibleRunIds)
            ->where('article_id', $articleId)
            ->get(['id', 'output_snapshot']);

        foreach ($items as $item) {
            $output = is_array($item->output_snapshot) ? $item->output_snapshot : [];
            $steps = is_array($output['steps'] ?? null) ? $output['steps'] : [];
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $ids = [(int) ($step['result_id'] ?? 0)];
                foreach (['outline_result_id', 'vocabulary_result_id'] as $key) {
                    $ids[] = (int) ($step[$key] ?? 0);
                }
                foreach (is_array($step['prompt_result_ids'] ?? null) ? $step['prompt_result_ids'] : [] as $rid) {
                    $ids[] = (int) $rid;
                }
                foreach (is_array($step['child_prompt_result_ids'] ?? null) ? $step['child_prompt_result_ids'] : [] as $rid) {
                    $ids[] = (int) $rid;
                }
                if (in_array($promptResultId, $ids, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return list<int>
     */
    private function accessibleRunIds(array $accessibleProjectIds): array
    {
        if ($accessibleProjectIds === []) {
            return [];
        }

        return SeoProjectRun::query()
            ->whereIn('project_id', $accessibleProjectIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
