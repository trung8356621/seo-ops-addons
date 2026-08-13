<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Content\Services\ArticleEditorReadinessService;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunErrorFormatter;
use App\Support\RuntimeLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SeoProjectWorkflowRunService
{
    public const TEST_RUN_LIMIT = 1;

    public function __construct(
        private readonly TaskTestInputResolver $inputResolver,
        private readonly CreateArticlesFromTaskService $articleRunner,
        private readonly SeoProjectRunErrorFormatter $errorFormatter,
        private readonly PromptResultLinkService $promptResultLinks,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
        private readonly ArticleEditorReadinessService $editorReadiness,
        private readonly \Omnichannel\Addons\Agent\Automation\Migration\ProjectTaskCallerBridge $taskCallerBridge,
        private readonly SeoProjectRunItemService $runItemService,
        private readonly SeoProjectTaskEventRecorder $eventRecorder,
        private readonly \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPostRunPipeline $postRunPipeline,
        private readonly \Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectTaskExecutionService $taskExecution,
    ) {}

    public function startRun(SeoProject $project, string $mode, ?array $settings = null): SeoProjectRun
    {
        if ($project->archived_at !== null || $project->isArchive()) {
            throw new \RuntimeException(__('seo-content-ai::filament.projects.archive_blocked_generate'));
        }

        // Keep task_ids / rerun* — fromArray()->toArray() alone strips them and
        // prepareRunQueue then expands to all pending project items.
        $snapshot = ContentProjectRunSettings::snapshotForRun($settings);
        $workflowSnap = $this->capturePublishWorkflowSnapshot();
        if ($workflowSnap !== null) {
            $snapshot['workflow_execution_snapshot'] = $workflowSnap;
        }

        $run = SeoProjectRun::query()->create([
            'project_id' => (int) $project->id,
            'user_id' => (int) auth()->id(),
            'mode' => $mode === SeoProjectRun::MODE_TEST ? SeoProjectRun::MODE_TEST : SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_RUNNING,
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'items' => null,
            'settings' => $snapshot,
            'started_at' => now(),
        ]);

        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->runStarted($run);

        return $run;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function capturePublishWorkflowSnapshot(): ?array
    {
        try {
            $taskId = app(SeoCreateArticleSettingsService::class)->getPublishArticleTaskId();
            if ($taskId === null || $taskId <= 0) {
                return null;
            }
            $task = SeoTask::query()->find($taskId);
            if (! $task instanceof SeoTask) {
                return null;
            }

            return app(WorkflowExecutionSnapshotBuilder::class)->fromTask($task)->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    public function updateRunSettings(SeoProjectRun $run, array $settings): SeoProjectRun
    {
        $existing = is_array($run->settings) ? $run->settings : [];
        $snapshot = ContentProjectRunSettings::snapshotForRun(array_merge($existing, $settings));
        $run->update(['settings' => $snapshot]);

        return $run->fresh() ?? $run;
    }

    public function prepareRunQueue(SeoProject $project, SeoProjectRun $run, ?int $limit = null): SeoProjectRun
    {
        $project->loadMissing('site');

        $settings = is_array($run->settings) ? $run->settings : [];
        $isRerun = (bool) ($settings['rerun'] ?? false);
        $explicitIds = [];
        if (isset($settings['task_ids']) && is_array($settings['task_ids'])) {
            $explicitIds = array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                $settings['task_ids'],
            ), static fn (int $id): bool => $id > 0));
        }

        if ($isRerun) {
            if ($explicitIds === []) {
                throw new \InvalidArgumentException(
                    'Rerun requires explicit item selection — entire-project rerun blocked.',
                );
            }
            $query = $project->tasks()
                ->planned()
                ->whereIn('id', $explicitIds)
                ->where('type', '!=', SeoProjectTask::TYPE_IMPROVE)
                ->orderBy('target_date')
                ->orderBy('id');
            $tasks = $query->get();
        } elseif ($explicitIds !== []) {
            // Explicit generate selection — never expand to other pending items.
            $preview = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier::class)
                ->preview($project);
            $allowed = array_flip($preview->runnableTaskIds());
            $runnableIds = array_values(array_filter(
                $explicitIds,
                static fn (int $id): bool => isset($allowed[$id]),
            ));
            if ($runnableIds === []) {
                throw new \InvalidArgumentException(__('seo-content-ai::filament.projects.run_items_empty'));
            }
            if ($limit !== null && $limit > 0) {
                $runnableIds = array_slice($runnableIds, 0, $limit);
            }
            $tasks = $project->tasks()
                ->planned()
                ->whereIn('id', $runnableIds)
                ->orderBy('target_date')
                ->orderBy('id')
                ->get();
        } else {
            $preview = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier::class)
                ->preview($project);

            if ($preview->runCount() <= 0) {
                throw new \InvalidArgumentException(__('seo-content-ai::filament.projects.run_items_empty'));
            }

            if ($preview->failClosed && ! (bool) ($settings['technical_confirm_full_rerun'] ?? false)) {
                throw new \InvalidArgumentException(
                    __('seo-content-ai::filament.projects.generate_pending_fail_closed'),
                );
            }

            $runnableIds = $preview->runnableTaskIds();

            if ($runnableIds === []) {
                throw new \InvalidArgumentException(__('seo-content-ai::filament.projects.run_items_empty'));
            }

            if ($limit !== null && $limit > 0) {
                $runnableIds = array_slice($runnableIds, 0, $limit);
            }

            $tasks = $project->tasks()
                ->planned()
                ->whereIn('id', $runnableIds)
                ->orderBy('target_date')
                ->orderBy('id')
                ->get();
        }

        if ($tasks->isEmpty()) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.projects.run_items_empty'));
        }

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $this->runItemService->prepareOperation($run, $project, $task);
        }

        $run = $this->runItemService->syncMirrorAndCounters($run, false);

        if ($tasks->count() === 0) {
            return $this->completeRunQueue($run);
        }

        return $run;
    }

    private function pendingAiTasksInBatch(SeoProject $project, ?int $limit): int
    {
        $query = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->planned()
            ->orderBy('target_date')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return (int) $query->count();
    }

    /**
     * Seed toàn bộ task trong batch vào run.items (pending/manual) để UI không mất hàng giữa chừng.
     *
     * @return list<array<string, mixed>>
     */
    private function seedPlannedItems(SeoProject $project, int $projectSiteId, ?int $limit = null): array
    {
        $query = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->orderBy('target_date')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(fn (SeoProjectTask $task): array => $this->buildPendingItemRow($task))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPendingItemRow(SeoProjectTask $task): array
    {
        return [
            'task_id' => (int) $task->id,
            'type' => (string) $task->type,
            'source_content' => (string) $task->source_content,
            'keyword' => $task->keyword,
            'title' => $task->title,
            'secondary_description' => $task->secondary_description,
            'post_type' => SeoProjectTask::isNewArticleType($task->type)
                ? SeoProjectTask::normalizePostType($task->post_type)
                : null,
            'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->loai_san_pham ?? '')
                    : null,
            'gallery_description' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->description ?? '')
                    : null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'rewrite_mode' => in_array(SeoProjectTask::normalizeType($task->type), [
                SeoProjectTask::TYPE_REWRITE,
                SeoProjectTask::TYPE_IMPROVE,
            ], true)
                ? SeoProjectTask::REWRITE_MODE_CONTENT
                : null,
            'rewrite_notes' => in_array(SeoProjectTask::normalizeType($task->type), [
                SeoProjectTask::TYPE_REWRITE,
                SeoProjectTask::TYPE_IMPROVE,
            ], true)
                ? $task->rewrite_notes
                : null,
            'status' => 'pending',
            'article_id' => null,
            'article_edit_url' => null,
            'message' => '',
            'steps' => [],
        ];
    }

    /**
     * Reconcile JSON mirror từ seo_project_run_items.
     * Không tạo task mới. Không tạo article. Không fuzzy identity.
     */
    public function reconcileMissingCompletedItems(SeoProjectRun $run): SeoProjectRun
    {
        return $this->runItemService->syncMirrorAndCounters($run, false);
    }

    public function completeRunQueue(SeoProjectRun $run): SeoProjectRun
    {
        $run = $this->runItemService->syncMirrorAndCounters($run, true);
        $run->loadMissing('project');

        $project = $run->project;
        if (! $project instanceof SeoProject) {
            app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                ->runCompleted($run);

            return $run;
        }

        $result = app(SeoProjectRunConsolidationService::class)->maybeConsolidate($project) ?? $run;
        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->runCompleted($result);

        try {
            app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\GenerationBatchNotificationPublisher::class)
                ->notifyRunCompleted($project, $result, (int) ($result->user_id ?? 0) ?: null);

            $succeeded = (int) ($result->succeeded ?? 0);
            if ($succeeded > 0) {
                $reviewerId = (int) ($project->user_id ?? 0);
                if ($reviewerId > 0) {
                    app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\ReviewAssignmentNotificationPublisher::class)
                        ->notifyItemsAssigned(
                            $project,
                            $reviewerId,
                            $succeeded,
                            (int) ($result->user_id ?? 0) ?: null,
                        );
                }
            }
        } catch (\Throwable $notificationError) {
            RuntimeLogger::warning('seo.operational_notification.generation_batch_hook_failed', [
                'run_id' => (int) $result->getKey(),
                'error' => $notificationError->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Đánh dấu run completed + cập nhật counter từ run items, không consolidate.
     */
    public function markRunCompletedQuietly(SeoProjectRun $run): SeoProjectRun
    {
        $run->refresh();

        return $this->runItemService->syncMirrorAndCounters($run, true);
    }

    public function execute(SeoProject $project, SeoProjectRun $run, ?int $limit = null): SeoProjectRun
    {
        @set_time_limit(0);

        $project->loadMissing('site');
        $projectSiteId = (int) ($project->site_id ?? 0);

        $query = $project->tasks()
            ->where('status', SeoProjectTask::STATUS_PENDING)
            ->orderBy('target_date')
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $tasks = $query->get();
        $items = [];

        foreach ($tasks as $task) {
            /** @var SeoProjectTask $task */
            $items[] = $this->taskExecution->executeLoadedTask(
                $project,
                $run,
                $task,
                $projectSiteId,
                forceRetry: false,
            )->toLegacyItemRow();
        }

        return $this->finalizeRun($run, $items);
    }

    public function ensureFailedTasksQueued(SeoProjectRun $run): void
    {
        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            return;
        }

        // Phase 3A: không tạo task copy. Reset task failed → pending trên task gốc + run item.
        $failedItems = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('status', SeoProjectRunItemStatus::Failed->value)
            ->get();

        foreach ($failedItems as $runItem) {
            if (! $runItem instanceof SeoProjectRunItem) {
                continue;
            }

            $taskId = (int) ($runItem->task_id ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            $task = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->whereKey($taskId)
                ->first();

            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            if ((string) $task->status === SeoProjectTask::STATUS_FAILED) {
                SeoProjectTask::query()->whereKey($taskId)->update([
                    'status' => SeoProjectTask::STATUS_PENDING,
                ]);
            }

            if ((string) $runItem->status === SeoProjectRunItemStatus::Failed->value) {
                $runItem->fill([
                    'status' => SeoProjectRunItemStatus::Pending->value,
                    'error_code' => null,
                    'error_message' => null,
                    'finished_at' => null,
                ]);
                $runItem->save();
            }
        }

        $this->runItemService->mirrorJsonSafely($run);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryTask(SeoProjectRun $run, int $taskId, bool $markCompleted = true, ?int $forcedArticleId = null): array
    {
        // Thin adapter — business execution owns ContentProjectTaskExecutionService.
        return $this->taskExecution->execute(
            $run,
            $taskId,
            markCompleted: $markCompleted,
            forcedArticleId: $forcedArticleId,
            forceRetry: true,
        )->toLegacyItemRow();
    }

    /**
     * @return array<string, mixed>
     */
    public function markTaskFixed(SeoProjectRun $run, int $taskId, ?int $articleId = null): array
    {
        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            throw new \InvalidArgumentException('Không tìm thấy dự án của lần run này.');
        }

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            throw new \InvalidArgumentException('Không tìm thấy hạng mục #'.$taskId.' trong kết quả run.');
        }

        $action = $this->runItemService->resolveAction($task);
        $runItem = $this->runItemService->findByLogicalOperation((int) $run->id, $taskId, $action->value)
            ?? $this->runItemService->prepareOperation($run, $project, $task);

        $resolvedArticleId = $articleId ?: (int) ($runItem->article_id ?? 0) ?: (int) ($task->article_id ?? 0);
        $articleExists = $resolvedArticleId > 0
            && SeoArticle::query()
                ->whereKey($resolvedArticleId)
                ->where('site_id', (int) $project->site_id)
                ->exists();

        if (! $articleExists) {
            throw new \InvalidArgumentException('Không tìm thấy bài viết đã sửa để đánh dấu hoàn thành.');
        }

        $this->markTaskCompleted($task, $resolvedArticleId);
        $this->storeArticleRunMeta($resolvedArticleId, $run, $task);

        $this->runItemService->markSuccess(
            $runItem,
            $resolvedArticleId,
            'Đã sửa lỗi thủ công.',
        );

        $this->runItemService->syncMirrorAndCounters($run, false);

        return [
            'task_id' => $taskId,
            'retry_task_id' => $taskId,
            'action' => $action->value,
            'status' => 'success',
            'article_id' => $resolvedArticleId,
            'article_edit_url' => ArticleResource::getUrl('edit', ['record' => $resolvedArticleId], isAbsolute: false),
            'message' => 'Đã sửa lỗi thủ công.',
            'manual_fixed' => true,
            'run_item_id' => (int) $runItem->id,
        ];
    }

    /**
     * Claim → workflow provider → persist for one task.
     * Public only for ContentProjectTaskExecutionService (Phase 1.7).
     * Do not call from UI / Engine / Job directly.
     *
     * @return array<string, mixed>
     */
    public function runTaskPipeline(
        SeoProject $project,
        SeoProjectRun $run,
        SeoProjectTask $task,
        int $projectSiteId,
        bool $forceRetry = false,
    ): array
    {
        return $this->runOneTask($project, $run, $task, $projectSiteId, $forceRetry);
    }

    /**
     * @return array<string, mixed>
     */
    private function runOneTask(
        SeoProject $project,
        SeoProjectRun $run,
        SeoProjectTask $task,
        int $projectSiteId,
        bool $forceRetry = false,
    ): array
    {
        $preservePublished = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard::class)
            ->isPublishedLifecycle($task);
        $publishedSnapshot = $preservePublished ? [
            'task_status' => (string) $task->status,
            'publish_queue_status' => (string) ($task->publish_queue_status ?? 'none'),
            'publish_published_at' => $task->publish_published_at,
        ] : null;
        $revision = null;
        if ($preservePublished) {
            $articleId = (int) ($task->article_id ?? 0);
            $article = $articleId > 0 ? SeoArticle::query()->find($articleId) : null;
            if ($article instanceof SeoArticle) {
                $revision = app(SeoArticleRevisionService::class)->captureAfterSave(
                    $article,
                    (string) ($article->title ?? ''),
                    (string) ($article->content ?? ''),
                    [],
                    auth()->id() !== null ? (int) auth()->id() : null,
                    force: true,
                );
            }
        }

        $action = $this->runItemService->resolveAction($task);

        $claim = $this->runItemService->claimForExecution(
            $run,
            (int) $task->id,
            $action,
            forceRetry: $forceRetry,
        );
        $runItem = $claim['run_item'];
        $task = $claim['task'] ?? $task;

        if ($claim['outcome'] === 'already_processing') {
            return [
                'task_id' => (int) ($task?->id ?? 0),
                'retry_task_id' => (int) ($task?->id ?? 0),
                'action' => $action->value,
                'status' => 'pending',
                'message' => (string) ($claim['message'] ?? 'Operation đang processing.'),
                'error_code' => $claim['error_code'],
                'article_id' => $claim['article_id'],
                'steps' => [],
            ];
        }

        if ($claim['outcome'] === 'already_processed' || $claim['outcome'] === 'skipped') {
            if ($task instanceof SeoProjectTask && ($claim['article_id'] ?? 0) > 0) {
                $this->markTaskCompleted($task, (int) $claim['article_id']);
            }
            $this->runItemService->syncMirrorAndCounters($run, false);
            if ($task instanceof SeoProjectTask && $runItem instanceof SeoProjectRunItem) {
                return app(\Omnichannel\Addons\ContentProjects\Support\ProjectRunItemLegacyJsonPresenter::class)
                    ->present($runItem->fresh() ?? $runItem, $task->fresh() ?? $task);
            }

            return [
                'task_id' => (int) ($task?->id ?? 0),
                'retry_task_id' => (int) ($task?->id ?? 0),
                'status' => 'success',
                'message' => (string) ($claim['message'] ?? 'Already processed.'),
                'article_id' => $claim['article_id'],
                'steps' => [],
            ];
        }

        if ($claim['outcome'] === 'failed' || ! $task instanceof SeoProjectTask || ! $runItem instanceof SeoProjectRunItem) {
            $this->runItemService->syncMirrorAndCounters($run, false);

            return [
                'task_id' => (int) ($task?->id ?? $runItem?->task_id ?? 0),
                'retry_task_id' => (int) ($task?->id ?? $runItem?->task_id ?? 0),
                'status' => 'failed',
                'message' => (string) ($claim['message'] ?? 'Claim failed.'),
                'error_code' => $claim['error_code'],
                'error_detail' => (string) ($claim['message'] ?? ''),
                'article_id' => $claim['article_id'],
                'steps' => [],
            ];
        }

        $taskSiteId = (int) ($task->site_id ?? $projectSiteId);
        if ($taskSiteId <= 0) {
            $this->markTaskFailed($task);
            $this->runItemService->markFailed(
                $runItem,
                ContentProjectErrorCode::ExternalWorkflowFailed,
                'Thiếu site_id.',
            );
            $this->safeTaskEvent($task, SeoProjectTaskEventType::TaskFailed, SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_FAILED, $run, $runItem);

            return $this->finalizeFailedJson($run, $task, $runItem, $this->errorFormatter->fromPlainDetail('Thiếu site_id.'));
        }

        $scope = $this->articleScopeForProject($projectSiteId);

        $runSettings = is_array($run->settings) ? $run->settings : [];
        $fromStepRaw = $runSettings['rerun_from_step'] ?? null;
        $fromStep = \Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep::tryFromMixed($fromStepRaw);
        $includeDownstream = (bool) ($runSettings['rerun_include_downstream'] ?? false);
        $cleanRestart = $fromStep === null || (bool) ($runSettings['rerun'] ?? false);

        try {
            $context = $this->inputResolver->resolveForProjectTask(
                $task,
                $scope,
                cleanRestart: $cleanRestart,
            );

            Log::info('seo.project_run.task.start', [
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'task_type' => (string) $task->type,
                'article_id' => (int) ($task->article_id ?? 0),
                'run_item_id' => (int) $runItem->id,
                'attempt' => (int) $runItem->attempt,
                'rerun_from_step' => $fromStep?->value,
            ]);

            if ($fromStep instanceof \Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep) {
                $result = $this->articleRunner->runRerunFromStepForContext(
                    $context,
                    $taskSiteId,
                    $fromStep,
                    $includeDownstream,
                );
            } else {
                $result = $this->articleRunner->runPublishWorkflowForContext($context, $taskSiteId);
            }
            $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];
            $stepStats = $this->summarizeStepStats($steps);
            $ranAt = now();

            if ($result['success']) {
                $articleId = (int) ($result['article_id'] ?? 0);

                if ($articleId > 0) {
                    $bind = $this->runItemService->bindArticleAfterExternal(
                        $task,
                        $runItem,
                        $articleId,
                        (int) $run->id,
                        created: true,
                    );
                    $task->refresh();
                    $runItem->refresh();

                    if (! $bind['ok']) {
                        $errorCode = ContentProjectErrorCode::tryFrom((string) $bind['error_code'])
                            ?? ContentProjectErrorCode::ArticleRelationConflict;
                        if (! $preservePublished) {
                            $this->markTaskFailed($task, (int) ($task->article_id ?? 0) ?: null);
                        } else {
                            $this->restorePublishedLifecycle($task, $publishedSnapshot, $revision);
                        }
                        $this->runItemService->markFailed(
                            $runItem,
                            $errorCode,
                            (string) $bind['message'],
                            articleId: $articleId,
                        );
                        $this->safeTaskEvent($task, SeoProjectTaskEventType::TaskFailed, SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_FAILED, $run, $runItem);

                        return $this->finalizeFailedJson($run, $task, $runItem, [
                            'message' => (string) $bind['message'],
                            'error_detail' => (string) $bind['message'],
                            'error_class' => null,
                            'error_trace' => null,
                            'failed_step' => null,
                        ]);
                    }
                }

                $message = $this->formatRunResultMessage((string) $result['message'], $ranAt, $stepStats);

                if ($articleId > 0 && SeoProjectTask::normalizeType($task->type) !== SeoProjectTask::TYPE_IMPROVE) {
                    $article = SeoArticle::query()->find($articleId);
                    if ($article instanceof SeoArticle) {
                        $pipelineResult = $this->postRunPipeline->apply($task, $run, $article, $runItem);
                        $message = rtrim($message, '.').($pipelineResult['message_suffix'] ?? '');
                    }
                }

                if ($preservePublished) {
                    $this->restorePublishedLifecycle($task, $publishedSnapshot, null);
                } else {
                    $this->markTaskCompleted($task, $articleId > 0 ? $articleId : (int) ($task->article_id ?? 0));
                }

                $this->runItemService->markSuccess(
                    $runItem,
                    $articleId > 0 ? $articleId : null,
                    $message,
                    [
                        'steps' => $this->promptSteps($steps),
                        'step_stats' => $stepStats,
                    ],
                );

                $this->safeTaskEvent($task, SeoProjectTaskEventType::TaskCompleted, SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_COMPLETED, $run, $runItem);

                $readiness = null;
                if ($articleId > 0) {
                    try {
                        $this->storeArticleRunMeta($articleId, $run, $task);
                        $this->promptResultLinks->linkFromWorkflowSteps(
                            steps: $steps,
                            articleId: $articleId,
                            runId: (int) $run->id,
                            taskId: (int) $task->id,
                        );
                        $article = SeoArticle::query()->find($articleId);
                        if ($article instanceof SeoArticle) {
                            $readiness = $this->editorReadiness->queueAfterWorkflowRun($article, (int) $run->id);
                        }
                    } catch (\Throwable $sideEffectException) {
                        Log::warning('seo.project_run.task.post_success_side_effect', [
                            'run_id' => (int) $run->id,
                            'task_id' => (int) $task->id,
                            'article_id' => $articleId,
                            'error' => $sideEffectException->getMessage(),
                        ]);
                    }
                }

                $this->runItemService->syncMirrorAndCounters($run, false);
                $freshItem = $runItem->fresh() ?? $runItem;
                $row = app(\Omnichannel\Addons\ContentProjects\Support\ProjectRunItemLegacyJsonPresenter::class)
                    ->present($freshItem, $task->fresh() ?? $task, [
                        'step_stats' => $stepStats,
                    ]);

                if ($readiness !== null) {
                    $row['article_editor_ready'] = $readiness->isReady;
                    $row['article_editor_preparing_message'] = $this->editorReadiness->userMessage($readiness);
                }

                return $row;
            }

            $failedArticleId = (int) ($result['article_id'] ?? 0);
            if ($failedArticleId <= 0) {
                $failedArticleId = (int) ($task->article_id ?? 0);
            }
            if ($failedArticleId > 0) {
                $this->runItemService->bindArticleAfterExternal(
                    $task,
                    $runItem,
                    $failedArticleId,
                    (int) $run->id,
                    created: false,
                );
                $task->refresh();
            }

            if ($preservePublished) {
                $this->restorePublishedLifecycle($task, $publishedSnapshot, $revision);
            } else {
                $this->markTaskFailed($task, $failedArticleId > 0 ? $failedArticleId : null);
            }
            $failedStep = is_array($result['failed_step'] ?? null) ? $result['failed_step'] : null;
            $error = $this->errorFormatter->fromWorkflowFailure((string) $result['message'], $failedStep);

            $this->runItemService->markFailed(
                $runItem,
                ContentProjectErrorCode::ExternalWorkflowFailed,
                $error['error_detail'] ?? $error['message'],
                $error['message'],
                ['steps' => $this->promptSteps($steps), 'step_stats' => $stepStats],
                $failedArticleId > 0 ? $failedArticleId : null,
            );
            $this->safeTaskEvent($task, SeoProjectTaskEventType::TaskFailed, SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_FAILED, $run, $runItem);

            try {
                if (is_array($failedStep)) {
                    $enriched = $failedStep;
                    foreach ($this->promptSteps($steps) as $stepRow) {
                        if (! is_array($stepRow) || (string) ($stepRow['status'] ?? '') !== 'failed') {
                            continue;
                        }
                        foreach (['failure_category', 'prompt_id', 'hook', 'hook_key', 'error_code'] as $key) {
                            if (! isset($enriched[$key]) && isset($stepRow[$key])) {
                                $enriched[$key] = $stepRow[$key];
                            }
                        }
                        break;
                    }
                    app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\PromptContractNotificationPublisher::class)
                        ->notifyFromFailedStep(
                            $project,
                            $enriched,
                            (int) $run->id,
                            (int) $task->id,
                        );
                }
            } catch (\Throwable $notificationError) {
                RuntimeLogger::warning('seo.operational_notification.prompt_contract_hook_failed', [
                    'run_id' => (int) $run->id,
                    'task_id' => (int) $task->id,
                    'error' => $notificationError->getMessage(),
                ]);
            }

            if ($failedArticleId > 0) {
                $this->storeArticleRunMeta($failedArticleId, $run, $task);
                $this->promptResultLinks->linkFromWorkflowSteps(
                    steps: $steps,
                    articleId: $failedArticleId,
                    runId: (int) $run->id,
                    taskId: (int) $task->id,
                    source: 'workflow_run_failed',
                );
            }

            return $this->finalizeFailedJson($run, $task, $runItem, $error, $stepStats);
        } catch (\Throwable $exception) {
            Log::error('seo.project_run.task.exception', [
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'error' => $exception->getMessage(),
                'class' => $exception::class,
            ]);

            $keptArticleId = (int) ($task->article_id ?? 0);
            if ($preservePublished) {
                $this->restorePublishedLifecycle($task, $publishedSnapshot, $revision);
            } else {
                $this->markTaskFailed($task, $keptArticleId > 0 ? $keptArticleId : null);
            }
            $error = $this->errorFormatter->fromThrowable($exception);
            $this->runItemService->markFailed(
                $runItem,
                ContentProjectErrorCode::ExternalWorkflowFailed,
                $error['error_detail'] ?? $error['message'],
                $error['message'],
                articleId: $keptArticleId > 0 ? $keptArticleId : null,
            );
            $this->safeTaskEvent($task, SeoProjectTaskEventType::TaskFailed, SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_FAILED, $run, $runItem);

            return $this->finalizeFailedJson($run, $task, $runItem, $error);
        }
    }

    /**
     * @deprecated Phase 3A — không tạo task copy. Trả về task gốc.
     */
    private function enqueueFailedTaskOnce(SeoProject $project, SeoProjectTask $failedTask): int
    {
        if ((string) $failedTask->status === SeoProjectTask::STATUS_FAILED) {
            SeoProjectTask::query()->whereKey((int) $failedTask->id)->update([
                'status' => SeoProjectTask::STATUS_PENDING,
            ]);
        }

        return (int) $failedTask->id;
    }

    /**
     * @deprecated Phase 3A — không reconstruct task từ JSON.
     *
     * @param  array<string, mixed>  $item
     */
    private function createRetryTaskFromItem(SeoProject $project, array $item): SeoProjectTask
    {
        $taskId = (int) ($item['task_id'] ?? 0);
        $task = $taskId > 0
            ? SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->whereKey($taskId)
                ->first()
            : null;

        if ($task instanceof SeoProjectTask) {
            return $task;
        }

        throw new \InvalidArgumentException(
            ContentProjectErrorCode::TaskNotFound->value.': không reconstruct task từ JSON.',
        );
    }

    /**
     * @param  array<string, mixed>  $error
     * @param  array<string, mixed>|null  $stepStats
     * @return array<string, mixed>
     */
    private function finalizeFailedJson(
        SeoProjectRun $run,
        SeoProjectTask $task,
        SeoProjectRunItem $runItem,
        array $error,
        ?array $stepStats = null,
    ): array {
        $this->runItemService->syncMirrorAndCounters($run, false);
        $row = app(\Omnichannel\Addons\ContentProjects\Support\ProjectRunItemLegacyJsonPresenter::class)
            ->present($runItem->fresh() ?? $runItem, $task->fresh() ?? $task);
        $row['retry_task_id'] = (int) $task->id;
        $row['error_detail'] = $error['error_detail'] ?? ($error['message'] ?? '');
        if ($stepStats !== null) {
            $row['step_stats'] = $stepStats;
        }

        return $row;
    }

    private function safeTaskEvent(
        SeoProjectTask $task,
        SeoProjectTaskEventType $event,
        ?string $from,
        ?string $to,
        SeoProjectRun $run,
        SeoProjectRunItem $runItem,
    ): void {
        try {
            $this->eventRecorder->record(
                task: $task,
                event: $event,
                fromStatus: $from,
                toStatus: $to,
                payload: [
                    'run_item_id' => (int) $runItem->id,
                    'action' => (string) $runItem->action,
                    'attempt' => (int) $runItem->attempt,
                    'article_id' => $runItem->article_id,
                    'error_code' => $runItem->error_code,
                ],
                runId: (int) $run->id,
                createdBy: auth()->id() !== null ? (int) auth()->id() : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('seo.project_run.event_failed', [
                'task_id' => (int) $task->id,
                'event' => $event->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchingTaskQuery(SeoProject $project, array $item): HasMany
    {
        $type = (string) ($item['type'] ?? SeoProjectTask::TYPE_CREATE);
        $source = trim((string) ($item['source_content'] ?? ''));

        $query = $project->tasks()
            ->where('type', $type)
            ->where('source_content', $source);

        if (SeoProjectTask::isNewArticleType($type)) {
            $query->where(
                'post_type',
                SeoProjectTask::normalizePostType($item['post_type'] ?? null),
            );
        }

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function finalizeRun(SeoProjectRun $run, array $items): SeoProjectRun
    {
        return $this->persistRunItems($run, $items, true);
    }

    /**
     * Dual-write: counters từ run items; JSON mirror sau (compatibility).
     * $items chỉ còn dùng làm hint merge legacy — nguồn chuẩn là seo_project_run_items.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function persistRunItems(SeoProjectRun $run, array $items, bool $markCompleted): SeoProjectRun
    {
        // Prefer structured table; ignore incoming JSON as source of truth.
        unset($items);

        return $this->runItemService->syncMirrorAndCounters($run, $markCompleted);
    }

    /**
     * @param  list<array<string, mixed>>  $dbItems
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeRunItemsByTaskId(array $dbItems, array $incoming): array
    {
        /** @var array<int, array<string, mixed>> $byTaskId */
        $byTaskId = [];
        /** @var list<int|array<string, mixed>> $order */
        $order = [];
        /** @var array<int, true> $seenTaskIds */
        $seenTaskIds = [];

        foreach (array_merge($dbItems, $incoming) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId <= 0) {
                $order[] = $item;
                continue;
            }

            if (! isset($seenTaskIds[$taskId])) {
                $seenTaskIds[$taskId] = true;
                $order[] = $taskId;
                $byTaskId[$taskId] = $item;
                continue;
            }

            $byTaskId[$taskId] = $this->preferRicherRunItem($byTaskId[$taskId], $item);
        }

        $merged = [];
        foreach ($order as $entry) {
            if (is_int($entry)) {
                $merged[] = $byTaskId[$entry];
                continue;
            }
            $merged[] = $entry;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function preferRicherRunItem(array $a, array $b): array
    {
        $score = static function (array $item): int {
            return match ((string) ($item['status'] ?? '')) {
                'success' => 300,
                'failed' => 200,
                'manual' => 100,
                'pending' => 50,
                default => 0,
            };
        };

        if ($score($b) !== $score($a)) {
            return $score($b) > $score($a) ? $b : $a;
        }

        // Cùng rank: ưu tiên bản có article_id / message mới hơn.
        $aArticle = (int) ($a['article_id'] ?? 0);
        $bArticle = (int) ($b['article_id'] ?? 0);
        if ($bArticle > 0 && $aArticle <= 0) {
            return $b;
        }
        if ($aArticle > 0 && $bArticle <= 0) {
            return $a;
        }

        return $b;
    }

    /**
     * @return null|callable(Builder): void
     */
    private function articleScopeForProject(int $projectSiteId): ?callable
    {
        return function (Builder $builder) use ($projectSiteId): void {
            if (SeoAccessControl::shouldScopeToAccountOwner()) {
                SeoAccessControl::applyAccessibleSiteScope($builder);
            }

            if ($projectSiteId > 0) {
                $builder->where('site_id', $projectSiteId);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildImproveManualItemRow(SeoProjectTask $task, int $projectSiteId): array
    {
        $taskSiteId = (int) ($task->site_id ?? $projectSiteId);
        $articleId = $this->resolveArticleIdForTask($task, $taskSiteId > 0 ? $taskSiteId : $projectSiteId);
        $message = $articleId > 0
            ? __('seo-content-ai::filament.projects.run_item_manual_hint')
            : __('seo-content-ai::filament.projects.run_item_manual_no_article');

        return [
            'task_id' => (int) $task->id,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'source_content' => (string) $task->source_content,
            'post_type' => null,
            'loai_san_pham' => null,
            'gallery_description' => null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'status' => 'manual',
            'article_id' => $articleId > 0 ? $articleId : null,
            'article_edit_url' => $articleId > 0
                ? ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false)
                : null,
            'message' => $message,
            'steps' => [],
        ];
    }

    private function resolveArticleIdForTask(SeoProjectTask $task, int $siteId): int
    {
        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId > 0) {
            return $articleId;
        }

        $title = trim((string) $task->source_content);
        if ($title === '') {
            return 0;
        }

        $scope = $this->articleScopeForProject($siteId);
        $query = SeoArticle::query();
        if (is_callable($scope)) {
            $scope($query);
        }

        $resolved = (int) ($query
            ->where('title', $title)
            ->orderByDesc('id')
            ->value('id') ?? 0);

        return $resolved > 0 ? $resolved : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItemRow(
        SeoProjectTask $task,
        bool $success,
        ?int $articleId,
        string $message,
        array $steps = [],
        mixed $ranAt = null,
    ): array {
        $lastRunAt = $ranAt instanceof \DateTimeInterface
            ? $ranAt->format('Y-m-d H:i:s')
            : (is_string($ranAt) && trim($ranAt) !== '' ? trim($ranAt) : now()->format('Y-m-d H:i:s'));

        return [
            'task_id' => (int) $task->id,
            'type' => (string) $task->type,
            'source_content' => (string) $task->source_content,
            'post_type' => SeoProjectTask::isNewArticleType($task->type)
                ? SeoProjectTask::normalizePostType($task->post_type)
                : null,
            'loai_san_pham' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->loai_san_pham ?? '')
                    : null,
            'gallery_description' => SeoProjectTask::isNewArticleType($task->type)
                && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
                    ? (string) ($task->description ?? '')
                    : null,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'last_run_at' => $lastRunAt,
            'status' => $success ? 'success' : 'failed',
            'article_id' => $articleId,
            'article_edit_url' => $articleId > 0 && $this->editorReadiness->isReady($articleId)
                ? ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false)
                : null,
            'article_editor_ready' => $articleId > 0 ? $this->editorReadiness->isReady($articleId) : true,
            'message' => $message,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array{
     *     message: string,
     *     error_detail: string,
     *     error_class: ?string,
     *     error_trace: ?string,
     *     failed_step: ?array{title: string, prompt_name: string, message: string}
     * }  $error
     * @return array<string, mixed>
     */
    private function buildFailedItemRow(
        SeoProjectTask $task,
        ?int $articleId,
        array $error,
        array $steps = [],
        mixed $ranAt = null,
    ): array {
        $row = $this->buildItemRow($task, false, $articleId, $error['message'], $steps, $ranAt);

        $row['error_detail'] = $error['error_detail'];

        if (filled($error['error_class'] ?? null)) {
            $row['error_class'] = $error['error_class'];
        }

        if (filled($error['error_trace'] ?? null)) {
            $row['error_trace'] = $error['error_trace'];
        }

        if (is_array($error['failed_step'] ?? null)) {
            $row['failed_step'] = $error['failed_step'];
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{completed: int, skipped: int, failed: int, total: int}
     */
    private function summarizeStepStats(array $steps): array
    {
        $completed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $status = (string) ($step['status'] ?? '');
            match ($status) {
                'completed' => $completed++,
                'skipped' => $skipped++,
                'failed' => $failed++,
                default => null,
            };
        }

        return [
            'completed' => $completed,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => $completed + $skipped + $failed,
        ];
    }

    /**
     * @param  array{completed: int, skipped: int, failed: int, total: int}  $stepStats
     */
    private function formatRunResultMessage(string $baseMessage, \DateTimeInterface $ranAt, array $stepStats): string
    {
        $base = trim($baseMessage);
        if ($base === '') {
            $base = 'Đã chạy quy trình và tạo/cập nhật bài.';
        }

        return sprintf(
            '%s · Chạy lúc %s · AI xong %d / bỏ qua %d / lỗi %d.',
            $base,
            $ranAt->format('d/m/Y H:i:s'),
            (int) ($stepStats['completed'] ?? 0),
            (int) ($stepStats['skipped'] ?? 0),
            (int) ($stepStats['failed'] ?? 0),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function promptSteps(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        return collect($steps)
            ->filter(fn (mixed $step): bool => is_array($step) && ($step['type'] ?? '') === 'prompt')
            ->values()
            ->all();
    }

    private function markTaskWriting(SeoProjectTask $task): void
    {
        $existingArticleId = (int) ($task->article_id ?? 0);

        $this->persistTaskState(
            $task,
            SeoProjectTask::STATUS_WRITING,
            $existingArticleId > 0 ? $existingArticleId : null,
        );
    }

    /**
     * @param  array{task_status: string, publish_queue_status: string, publish_published_at: mixed}|null  $snapshot
     */
    private function restorePublishedLifecycle(
        SeoProjectTask $task,
        ?array $snapshot,
        ?\Omnichannel\Addons\Content\Models\SeoArticleRevision $revision,
    ): void {
        if ($revision instanceof \Omnichannel\Addons\Content\Models\SeoArticleRevision) {
            $articleId = (int) ($task->article_id ?? 0);
            $article = $articleId > 0 ? SeoArticle::query()->find($articleId) : null;
            if ($article instanceof SeoArticle) {
                app(SeoArticleRevisionService::class)->restoreRevisionToArticle($article, $revision);
            }
        }

        if ($snapshot === null) {
            return;
        }

        SeoProjectTask::query()->whereKey((int) $task->id)->update([
            'status' => $snapshot['task_status'],
            'publish_queue_status' => $snapshot['publish_queue_status'],
            'publish_published_at' => $snapshot['publish_published_at'],
        ]);
        $task->refresh();
    }

    private function markTaskFailed(SeoProjectTask $task, ?int $articleId = null): void
    {
        $resolvedArticleId = $articleId !== null && $articleId > 0
            ? $articleId
            : (((int) ($task->article_id ?? 0) > 0) ? (int) $task->article_id : null);

        $this->persistTaskState($task, SeoProjectTask::STATUS_FAILED, $resolvedArticleId);
        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->taskFailed($task->fresh() ?? $task);
    }

    private function markTaskCompleted(SeoProjectTask $task, int $articleId): void
    {
        $task->loadMissing('project');
        $this->taskCallerBridge->markCompleted(
            $task,
            $articleId,
            auth()->id() !== null ? (int) auth()->id() : null,
            (int) ($task->site_id ?? $task->project?->site_id ?? 0),
            'content_project_run',
        );
    }

    private function persistTaskState(SeoProjectTask $task, string $status, ?int $articleId): void
    {
        $payload = [
            'status' => $status,
            'article_id' => $articleId,
        ];

        if ($articleId !== null && $articleId > 0 && $task->connected_at === null) {
            $payload['connected_at'] = now();
        }

        if ($status === SeoProjectTask::STATUS_COMPLETED && $task->completed_at === null) {
            $payload['completed_at'] = now();
        }

        SeoProjectTask::query()->whereKey($task->id)->update($payload);

        $task->refresh();
    }

    private function releaseArticleLinkFromOtherTasks(int $articleId, int $keepTaskId): void
    {
        if ($articleId <= 0) {
            return;
        }

        SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->whereKeyNot($keepTaskId)
            ->update(['article_id' => null]);
    }

    private function storeArticleRunMeta(int $articleId, SeoProjectRun $run, SeoProjectTask $task): void
    {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'content_project_run'],
            [
                'meta_value' => json_encode([
                    'run_id' => (int) $run->id,
                    'project_id' => (int) $run->project_id,
                    'task_id' => (int) $task->id,
                    'ran_at' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
            ],
        );

        if (
            SeoProjectTask::isNewArticleType($task->type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            && filled($task->description)
        ) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'gallery_description'],
                ['meta_value' => (string) $task->description],
            );
        }

        if (
            SeoProjectTask::isNewArticleType($task->type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            && filled($task->loai_san_pham)
        ) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'loai_san_pham'],
                ['meta_value' => (string) $task->loai_san_pham],
            );
        }
    }
}
