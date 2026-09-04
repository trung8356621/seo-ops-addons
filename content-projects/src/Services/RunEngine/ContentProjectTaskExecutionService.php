<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\RunEngine;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTaskExecutionResult;
use App\Support\RuntimeLogger;
use Illuminate\Contracts\Foundation\Application;

/**
 * Sole entry for Content Project task/article execution (Phase 1.7).
 * Does not know queue / Livewire / RunEngine / retry button.
 *
 * Pipeline internals (claim → CreateArticlesFromTaskService → persist) live in
 * SeoProjectWorkflowRunService::runTaskPipeline — invoked only through this service.
 */
final class ContentProjectTaskExecutionService
{
    public function __construct(
        private readonly Application $app,
        private readonly SeoProjectRunItemService $runItemService,
        private readonly \Omnichannel\Addons\Agent\Automation\Migration\ProjectTaskCallerBridge $taskCallerBridge,
    ) {}

    /**
     * Execute by task id (manual retry / engine article job).
     */
    public function execute(
        SeoProjectRun $run,
        int $taskId,
        bool $markCompleted = true,
        ?int $forcedArticleId = null,
        bool $forceRetry = true,
    ): ContentProjectTaskExecutionResult {
        @set_time_limit(0);
        $started = microtime(true);

        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            throw new \InvalidArgumentException('Không tìm thấy dự án của lần run này.');
        }

        $run->refresh();

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            return $this->failMissingTask($run, $taskId, $markCompleted, $started);
        }

        $this->attachForcedArticleIfNeeded($project, $task, $forcedArticleId);

        if ((string) $task->status === SeoProjectTask::STATUS_FAILED) {
            SeoProjectTask::query()->whereKey((int) $task->id)->update([
                'status' => SeoProjectTask::STATUS_PENDING,
            ]);
            $task->refresh();
        }

        $projectSiteId = (int) ($project->site_id ?? 0);

        return $this->withRunCostPolicy($run, function () use ($project, $run, $task, $taskId, $projectSiteId, $forceRetry, $markCompleted, $started): ContentProjectTaskExecutionResult {
            $itemRow = $this->pipeline()->runTaskPipeline(
                $project,
                $run,
                $task,
                $projectSiteId,
                forceRetry: $forceRetry,
            );
            $itemRow['task_id'] = $taskId;
            $itemRow['retry_task_id'] = $taskId;

            $this->runItemService->syncMirrorAndCounters($run, $markCompleted);

            RuntimeLogger::info('content_project_task_execution.finished', [
                'run_id' => (int) $run->id,
                'task_id' => $taskId,
                'status' => (string) ($itemRow['status'] ?? ''),
                'duration_seconds' => round(microtime(true) - $started, 3),
                'force_retry' => $forceRetry,
                'mark_completed' => $markCompleted,
                'cost_policy' => AiCostPolicyScope::current()->value,
            ]);

            return ContentProjectTaskExecutionResult::fromLegacyItemRow(
                $itemRow,
                durationSeconds: microtime(true) - $started,
            );
        });
    }

    /**
     * Execute an already-loaded task (batch prepare/execute path).
     */
    public function executeLoadedTask(
        SeoProject $project,
        SeoProjectRun $run,
        SeoProjectTask $task,
        int $projectSiteId,
        bool $forceRetry = false,
    ): ContentProjectTaskExecutionResult {
        @set_time_limit(0);
        $started = microtime(true);

        return $this->withRunCostPolicy($run, function () use ($project, $run, $task, $projectSiteId, $forceRetry, $started): ContentProjectTaskExecutionResult {
            $itemRow = $this->pipeline()->runTaskPipeline(
                $project,
                $run,
                $task,
                $projectSiteId,
                forceRetry: $forceRetry,
            );
            $itemRow['task_id'] = (int) $task->id;
            $itemRow['retry_task_id'] = (int) $task->id;

            return ContentProjectTaskExecutionResult::fromLegacyItemRow(
                $itemRow,
                durationSeconds: microtime(true) - $started,
            );
        });
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withRunCostPolicy(SeoProjectRun $run, callable $callback): mixed
    {
        $settings = is_array($run->settings) ? $run->settings : [];

        return AiCostPolicyScope::run(
            AiCostPolicy::tryFromMixed($settings[AiCostPolicy::SETTING_KEY] ?? null),
            $callback,
        );
    }

    private function pipeline(): SeoProjectWorkflowRunService
    {
        return $this->app->make(SeoProjectWorkflowRunService::class);
    }

    private function failMissingTask(
        SeoProjectRun $run,
        int $taskId,
        bool $markCompleted,
        float $started,
    ): ContentProjectTaskExecutionResult {
        $action = SeoProjectRunAction::ArticleCreate;
        $existingRunItem = $this->runItemService->findByLogicalOperation(
            (int) $run->id,
            $taskId,
            $action->value,
        );
        if (! $existingRunItem instanceof SeoProjectRunItem) {
            $existingRunItem = SeoProjectRunItem::query()
                ->where('run_id', (int) $run->id)
                ->where('task_id', $taskId)
                ->orderByDesc('id')
                ->first();
        }

        if ($existingRunItem instanceof SeoProjectRunItem) {
            $this->runItemService->markFailed(
                $existingRunItem,
                ContentProjectErrorCode::TaskNotFound,
                'Task không còn tồn tại — không reconstruct từ JSON.',
            );
            $this->runItemService->syncMirrorAndCounters($run, $markCompleted);

            $row = [
                'task_id' => $taskId,
                'retry_task_id' => $taskId,
                'status' => 'failed',
                'message' => 'Task không còn tồn tại.',
                'error_code' => ContentProjectErrorCode::TaskNotFound->value,
                'error_detail' => 'Task không còn tồn tại — không reconstruct từ JSON.',
                'article_id' => $existingRunItem->article_id,
                'steps' => [],
            ];

            return ContentProjectTaskExecutionResult::fromLegacyItemRow(
                $row,
                durationSeconds: microtime(true) - $started,
            );
        }

        throw new \InvalidArgumentException('Không tìm thấy hạng mục #'.$taskId.' trong dự án.');
    }

    private function attachForcedArticleIfNeeded(
        SeoProject $project,
        SeoProjectTask $task,
        ?int $forcedArticleId,
    ): void {
        $linkedArticleId = (int) ($forcedArticleId ?? 0);
        if ($linkedArticleId <= 0) {
            $linkedArticleId = (int) ($task->article_id ?? 0);
        }

        if ($linkedArticleId <= 0 || (int) ($task->article_id ?? 0) === $linkedArticleId) {
            return;
        }

        $itemSiteId = (int) ($task->site_id ?? $project->site_id ?? 0);
        $articleExists = SeoArticle::query()
            ->whereKey($linkedArticleId)
            ->when($itemSiteId > 0, static fn ($q) => $q->where('site_id', $itemSiteId))
            ->exists();

        if (! $articleExists) {
            return;
        }

        $this->taskCallerBridge->attachArticle(
            $task,
            $linkedArticleId,
            auth()->id() !== null ? (int) auth()->id() : null,
            $itemSiteId,
        );
        $task->refresh();
    }
}
