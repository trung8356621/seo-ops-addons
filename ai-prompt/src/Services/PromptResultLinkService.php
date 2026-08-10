<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;

final class PromptResultLinkService
{
    /**
     * @param  array<string, mixed>  $step
     */
    public function linkFromWorkflowStep(
        array $step,
        int $articleId,
        int $runId,
        int $taskId,
        string $source = 'workflow_run',
    ): void {
        $resultId = (int) ($step['result_id'] ?? 0);
        if ($resultId <= 0 || $articleId <= 0) {
            return;
        }

        $this->linkPromptResult(
            promptResultId: $resultId,
            articleId: $articleId,
            source: $source,
            runId: $runId > 0 ? $runId : null,
            taskId: $taskId > 0 ? $taskId : null,
            workflowNodeId: trim((string) ($step['node_id'] ?? '')) ?: null,
            workflowStepTitle: trim((string) ($step['title'] ?? '')) ?: null,
            meta: [
                'status' => (string) ($step['status'] ?? ''),
                'type' => (string) ($step['type'] ?? ''),
                'prompt_name' => (string) ($step['prompt_name'] ?? ''),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    public function linkFromWorkflowSteps(
        array $steps,
        int $articleId,
        int $runId,
        int $taskId,
        string $source = 'workflow_run',
    ): void {
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $this->linkFromWorkflowStep($step, $articleId, $runId, $taskId, $source);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function linkPromptResult(
        int $promptResultId,
        int $articleId,
        string $source,
        ?int $runId = null,
        ?int $taskId = null,
        ?string $workflowNodeId = null,
        ?string $workflowStepTitle = null,
        array $meta = [],
    ): void {
        if ($promptResultId <= 0 || $articleId <= 0) {
            return;
        }

        if (! PromptResult::query()->whereKey($promptResultId)->exists()) {
            return;
        }

        if (! SeoArticle::query()->whereKey($articleId)->exists()) {
            return;
        }

        [$resolvedRunId, $resolvedTaskId] = $this->resolveRunTaskFromArticleMeta($articleId, $runId, $taskId);

        SeoPromptResultLink::query()->updateOrCreate(
            [
                'prompt_result_id' => $promptResultId,
                'source' => $source,
                'project_run_id' => $resolvedRunId,
                'project_task_id' => $resolvedTaskId,
                'workflow_node_id' => $workflowNodeId,
            ],
            [
                'article_id' => $articleId,
                'user_id' => auth()->id(),
                'workflow_step_title' => $workflowStepTitle,
                'meta' => $meta === [] ? null : $meta,
            ],
        );
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveRunTaskFromArticleMeta(int $articleId, ?int $runId, ?int $taskId): array
    {
        $providedRunId = (int) ($runId ?? 0);
        $providedTaskId = (int) ($taskId ?? 0);

        if ($providedRunId > 0 || $providedTaskId > 0) {
            if (
                $providedRunId > 0
                && ! SeoProjectRun::query()->whereKey($providedRunId)->exists()
            ) {
                $providedRunId = 0;
            }

            if (
                $providedTaskId > 0
                && ! SeoProjectTask::query()->whereKey($providedTaskId)->exists()
            ) {
                $providedTaskId = 0;
            }

            return [
                $providedRunId > 0 ? $providedRunId : null,
                $providedTaskId > 0 ? $providedTaskId : null,
            ];
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return [null, null];
        }

        $raw = $article->articleMetas()
            ->where('meta_key', 'content_project_run')
            ->value('meta_value');

        if (! is_string($raw) || trim($raw) === '') {
            return [null, null];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [null, null];
        }

        $resolvedRunId = (int) ($decoded['run_id'] ?? 0);
        $resolvedTaskId = (int) ($decoded['task_id'] ?? 0);

        if (
            $resolvedRunId > 0
            && ! SeoProjectRun::query()->whereKey($resolvedRunId)->exists()
        ) {
            $resolvedRunId = 0;
        }

        if (
            $resolvedTaskId > 0
            && ! SeoProjectTask::query()->whereKey($resolvedTaskId)->exists()
        ) {
            $resolvedTaskId = 0;
        }

        return [
            $resolvedRunId > 0 ? $resolvedRunId : null,
            $resolvedTaskId > 0 ? $resolvedTaskId : null,
        ];
    }
}

