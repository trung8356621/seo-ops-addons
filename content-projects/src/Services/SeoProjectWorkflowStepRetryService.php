<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;


use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveExecutionResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chạy lại từng prompt/node trong workflow — không chạy lại toàn pipeline.
 */
final class SeoProjectWorkflowStepRetryService
{
    public function __construct(
        private readonly SeoProjectWorkflowStepCatalogService $catalog,
        private readonly TaskTestInputResolver $inputResolver,
        private readonly TaskWorkflowTestRunner $workflowRunner,
        private readonly SeoProjectRunItemService $runItemService,
        private readonly ArticleOutlineResolver $outlineResolver,
        private readonly ArticleGenerationInputResolver $articleGenerationInput,
        private readonly ContentProjectActiveExecutionResolver $activeResolver,
    ) {}

    /**
     * @return list<array{
     *     node_id: string,
     *     title: string,
     *     label: string,
     *     kind: string,
     *     prompt_id: int|null,
     *     status: string|null,
     *     last_finished_at: string|null,
     *     busy: bool,
     *     can_retry: bool
     * }>
     */
    public function stepsForTask(SeoProjectRun $run, SeoProjectTask $task): array
    {
        $this->abandonStaleActiveSteps((int) $run->id, (int) $task->id);

        $catalog = $this->catalog->listRerunnableSteps($task);
        $activeByAction = $this->activeStepStatuses((int) $run->id, (int) $task->id);
        $activeByNode = $this->activeStepStatusesByNode((int) $run->id, (int) $task->id);
        $latestByAction = $this->latestStepFinishes((int) $run->id, (int) $task->id);
        $latestByNode = $this->latestStepFinishesByNode((int) $run->id, (int) $task->id);
        $taskHasAnyActive = $this->activeResolver->hasActiveForTask($run, (int) $task->id);
        $runTerminal = in_array((string) $run->status, [
            SeoProjectRun::STATUS_COMPLETED,
            SeoProjectRun::STATUS_CANCELLED,
            SeoProjectRun::STATUS_FAILED,
        ], true);

        $rows = [];
        foreach ($catalog as $step) {
            $nodeId = (string) $step['node_id'];
            $action = $this->stepAction($nodeId);
            // Busy theo node_id (gồm cả append-only step:rr:* có cùng node trong snapshot).
            $status = $activeByNode[$nodeId]
                ?? $activeByAction[$action]
                ?? ($latestByNode[$nodeId]['status'] ?? ($latestByAction[$action]['status'] ?? null));
            // Không đánh busy tất cả step chỉ vì task có 1 active — tránh Lỗi/Đang chạy lệch.
            $busy = ! $runTerminal && (
                isset($activeByNode[$nodeId])
                || isset($activeByAction[$action])
            );

            $rows[] = [
                'node_id' => $step['node_id'],
                'title' => $step['title'],
                'label' => $step['label'],
                'kind' => $step['kind'],
                'prompt_id' => $step['prompt_id'],
                'execution_role' => $step['execution_role'] ?? null,
                'hook_key' => $step['hook_key'] ?? null,
                'status' => $status,
                'last_finished_at' => $latestByNode[$nodeId]['finished_at']
                    ?? ($latestByAction[$action]['finished_at'] ?? null),
                'busy' => $busy,
                'can_retry' => ! $busy && ! $taskHasAnyActive && ! $runTerminal,
                'rerunnable' => (bool) ($step['rerunnable'] ?? true),
            ];
        }

        if ((bool) config('seo-content-ai.content_project.cancel_debug', false) && $activeByAction !== []) {
            $activeRows = SeoProjectRunItem::query()
                ->where('run_id', (int) $run->id)
                ->where('task_id', (int) $task->id)
                ->where('action', 'like', 'step:%')
                ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
                ->get(['id', 'action', 'status', 'error_message', 'article_id']);

            RuntimeLogger::info('seo.project_run.step_busy_snapshot', [
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'article_id' => (int) ($task->article_id ?? 0),
                'active_actions' => array_keys($activeByAction),
                'active_rows' => $activeRows->map(static fn (SeoProjectRunItem $row): array => [
                    'id' => (int) $row->id,
                    'action' => (string) $row->action,
                    'status' => (string) $row->status,
                    'error_message' => (string) ($row->error_message ?? ''),
                    'article_id' => (int) ($row->article_id ?? 0),
                    'busy_reason' => 'status_in_pending_or_processing',
                ])->all(),
            ]);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $taskIds
     * @param  list<string>  $nodeIds
     * @return array{
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     results: list<array<string, mixed>>,
     *     message: string
     * }
     */
    public function enqueueBulk(
        SeoProjectRun $run,
        SeoProject $project,
        array $taskIds,
        array $nodeIds,
        bool $executeImmediately = true,
    ): array {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));
        $nodeIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $nodeIds),
            static fn (string $id): bool => $id !== '',
        )));

        if ($taskIds === [] || $nodeIds === []) {
            return [
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'results' => [],
                'message' => 'Chưa chọn bài hoặc prompt.',
            ];
        }

        $this->abandonStaleActiveSteps((int) $run->id);

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereIn('id', $taskIds)
            ->get()
            ->keyBy(static fn (SeoProjectTask $task): int => (int) $task->id);

        foreach ($taskIds as $taskId) {
            if (! $tasks->has($taskId)) {
                throw new \InvalidArgumentException('Task #'.$taskId.' không thuộc project hiện tại.');
            }
        }

        $plan = [];
        foreach ($taskIds as $taskId) {
            /** @var SeoProjectTask $task */
            $task = $tasks->get($taskId);
            $orderedNodes = $this->catalog->orderNodeIdsByDependency($task, $nodeIds);
            foreach ($orderedNodes as $nodeId) {
                $step = $this->catalog->findStep($task, $nodeId);
                if ($step === null) {
                    throw new \InvalidArgumentException(
                        'Prompt «'.$nodeId.'» không thuộc cấu hình workflow của task #'.$taskId.'.'
                    );
                }
                $plan[] = [
                    'task' => $task,
                    'node_id' => $nodeId,
                    'step' => $step,
                ];
            }
        }

        $createdKeys = [];
        $skipped = 0;
        $results = [];
        $failed = 0;

        DB::connection('omi_seo_ai')->transaction(function () use ($run, $plan, &$createdKeys, &$skipped, &$results, &$failed): void {
            $clearedTaskIds = [];
            foreach ($plan as $entry) {
                /** @var SeoProjectTask $task */
                $task = $entry['task'];
                $nodeId = (string) $entry['node_id'];
                $step = is_array($entry['step'] ?? null) ? $entry['step'] : [];
                $action = $this->stepAction($nodeId);
                $taskId = (int) $task->id;

                // Clear busy cũ một lần / task — không hủy Pending vừa tạo của node trước trong bulk.
                if (! isset($clearedTaskIds[$taskId])) {
                    $this->cancelAllActiveStepsForTask($run, $taskId, 'Superseded by new step retry.');
                    $clearedTaskIds[$taskId] = true;
                }

                $dependencyError = $this->assertDependencies($task, $step);
                if ($dependencyError !== null) {
                    $failed++;
                    $results[] = [
                        'task_id' => $taskId,
                        'node_id' => $nodeId,
                        'status' => 'failed',
                        'message' => $dependencyError,
                    ];
                    continue;
                }

                $active = SeoProjectRunItem::query()
                    ->where('run_id', (int) $run->id)
                    ->where('task_id', $taskId)
                    ->where('action', $action)
                    ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
                    ->lockForUpdate()
                    ->first();

                if ($active instanceof SeoProjectRunItem) {
                    $skipped++;
                    continue;
                }

                $runItem = $this->prepareStepRunItem($run, $task, $nodeId, $step);
                $createdKeys[] = [
                    'run_item_id' => (int) $runItem->id,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                ];
            }
        });

        $created = count($createdKeys);

        if ($executeImmediately) {
            $stoppedTaskIds = [];
            foreach ($createdKeys as $key) {
                $taskId = (int) $key['task_id'];
                $nodeId = (string) $key['node_id'];
                $runItemId = (int) $key['run_item_id'];

                // Stop-on-error trong cùng article/task sequence — không chạy node kế tiếp.
                if (isset($stoppedTaskIds[$taskId])) {
                    $this->failPrepared(
                        $runItemId,
                        'Đã dừng vì bước trước trong cùng bài thất bại hoặc bị ngắt.',
                        $taskId,
                        $nodeId,
                    );
                    $failed++;
                    $results[] = [
                        'task_id' => $taskId,
                        'node_id' => $nodeId,
                        'run_item_id' => $runItemId,
                        'status' => 'failed',
                        'message' => 'Đã dừng vì bước trước trong cùng bài thất bại hoặc bị ngắt.',
                    ];

                    continue;
                }

                $result = $this->executePreparedStep($run, $taskId, $nodeId, $runItemId);
                $results[] = $result;
                if (($result['status'] ?? '') === 'failed') {
                    $failed++;
                    $stoppedTaskIds[$taskId] = true;
                }
            }
        }

        $message = sprintf(
            'Đã tạo %d task. Bỏ qua %d task vì đang chờ hoặc đang chạy.',
            $created,
            $skipped,
        );
        if ($failed > 0) {
            $message .= ' Thất bại: '.$failed.'.';
        }
        if ($created === 0 && $failed > 0 && $skipped === 0 && $results !== []) {
            $firstMsg = trim((string) ($results[0]['message'] ?? ''));
            if ($firstMsg !== '') {
                $message = $firstMsg;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retryOne(
        SeoProjectRun $run,
        SeoProject $project,
        int $taskId,
        string $nodeId,
    ): array {
        $bulk = $this->enqueueBulk($run, $project, [$taskId], [$nodeId], executeImmediately: true);

        if ($bulk['skipped'] > 0 && $bulk['created'] === 0) {
            return [
                'success' => false,
                'status' => 'busy',
                'message' => 'Prompt của bài này đang chờ xử lý hoặc đang chạy.',
                'bulk' => $bulk,
            ];
        }

        $first = $bulk['results'][0] ?? null;
        if (! is_array($first)) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => $bulk['message'],
                'bulk' => $bulk,
            ];
        }

        return [
            'success' => ($first['status'] ?? '') === 'success',
            'status' => (string) ($first['status'] ?? 'failed'),
            'message' => (string) ($first['message'] ?? $bulk['message']),
            'item' => $first,
            'bulk' => $bulk,
        ];
    }

    /**
     * Phase 2.0: chạy item append-only đã tạo sẵn (rerun) — không gọi prepareStepRunItem.
     *
     * @return array<string, mixed>
     */
    public function executePreparedStepItem(
        SeoProjectRun $run,
        int $taskId,
        string $nodeId,
        int $runItemId,
    ): array {
        return $this->executePreparedStep($run, $taskId, $nodeId, $runItemId);
    }

    /**
     * @param  array<string, mixed>  $stepMeta
     */
    private function prepareStepRunItem(
        SeoProjectRun $run,
        SeoProjectTask $task,
        string $nodeId,
        array $stepMeta,
    ): SeoProjectRunItem {
        $action = $this->stepAction($nodeId);
        $existing = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('task_id', (int) $task->id)
            ->where('action', $action)
            ->first();

        $attempt = $existing instanceof SeoProjectRunItem
            ? max(1, (int) $existing->attempt) + 1
            : 1;

        $payload = [
            'run_id' => (int) $run->id,
            'task_id' => (int) $task->id,
            'article_id' => (int) ($task->article_id ?? 0) ?: null,
            'action' => $action,
            'status' => SeoProjectRunItemStatus::Pending->value,
            'attempt' => $attempt,
            'idempotency_key' => hash('sha256', implode('|', [
                (int) $run->id,
                (int) $task->id,
                $action,
                'attempt:'.$attempt,
                (string) Str::uuid(),
            ])),
            'message' => 'Đang chờ chạy lại: '.(string) ($stepMeta['label'] ?? $nodeId),
            'error_code' => null,
            'error_message' => null,
            'input_snapshot' => [
                'node_id' => $nodeId,
                'step_kind' => $stepMeta['kind'] ?? null,
                'step_label' => $stepMeta['label'] ?? null,
                'prompt_id' => $stepMeta['prompt_id'] ?? null,
                'retry_mode' => 'workflow_step',
            ],
            'output_snapshot' => null,
            'started_at' => null,
            'finished_at' => null,
        ];

        if ($existing instanceof SeoProjectRunItem) {
            $existing->fill($payload);
            $existing->save();

            return $existing->fresh() ?? $existing;
        }

        return SeoProjectRunItem::query()->create($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function executePreparedStep(
        SeoProjectRun $run,
        int $taskId,
        string $nodeId,
        int $runItemId,
    ): array {
        $run->loadMissing('project.site');
        $project = $run->project;
        if (! $project instanceof SeoProject) {
            return $this->failPrepared($runItemId, 'Không tìm thấy dự án của lần run này.');
        }

        $task = SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->whereKey($taskId)
            ->first();

        if (! $task instanceof SeoProjectTask) {
            return $this->failPrepared($runItemId, 'Không tìm thấy hạng mục #'.$taskId.' trong dự án.');
        }

        $stepMeta = $this->catalog->findStep($task, $nodeId);
        if ($stepMeta === null) {
            return $this->failPrepared($runItemId, 'Prompt không thuộc cấu hình workflow.');
        }

        $seoTask = $this->catalog->resolveSeoTaskForStepRetry($task);
        if (! $seoTask instanceof SeoTask) {
            return $this->failPrepared($runItemId, 'Chưa cấu hình quy trình đăng bài / viết lại.');
        }

        $runItem = SeoProjectRunItem::query()->find($runItemId);
        if (! $runItem instanceof SeoProjectRunItem) {
            return [
                'task_id' => $taskId,
                'node_id' => $nodeId,
                'status' => 'failed',
                'message' => 'Không tìm thấy run item.',
            ];
        }

        $dependencyError = $this->assertDependencies($task, $stepMeta);
        if ($dependencyError !== null) {
            return $this->failPrepared($runItemId, $dependencyError, $taskId, $nodeId);
        }

        $runItem->refresh();
        if ($this->wasCancelledByUser($runItem)) {
            $this->ensureCancelledFailureState($runItem, $run);

            return [
                'task_id' => $taskId,
                'node_id' => $nodeId,
                'run_item_id' => (int) $runItem->id,
                'status' => 'failed',
                'message' => (string) ($runItem->message ?: 'Đã ngắt bước đang chạy.'),
            ];
        }

        // Claim Pending→Processing có điều kiện: Ngắt giữa chừng không bị request treo đè lại.
        $claimed = SeoProjectRunItem::query()
            ->whereKey($runItemId)
            ->where('status', SeoProjectRunItemStatus::Pending->value)
            ->where(function ($query): void {
                $query->whereNull('error_message')
                    ->orWhere(function ($inner): void {
                        $inner->where('error_message', 'not like', '%Cancelled by user%')
                            ->where('error_message', 'not like', '%Cancelled by stop%')
                            ->where('error_message', 'not like', '%superseded by new step%');
                    });
            })
            ->update([
                'status' => SeoProjectRunItemStatus::Processing->value,
                'started_at' => now(),
                'message' => 'Đang chạy: '.(string) $stepMeta['label'],
            ]);

        if ($claimed === 0) {
            $runItem->refresh();
            if ($this->wasCancelledByUser($runItem)) {
                $this->ensureCancelledFailureState($runItem, $run);

                return [
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => (int) $runItem->id,
                    'status' => 'failed',
                    'message' => (string) ($runItem->message ?: 'Đã ngắt bước đang chạy.'),
                ];
            }

            return $this->failPrepared(
                $runItemId,
                'Không nhận được quyền chạy bước (đã ngắt hoặc trạng thái đổi).',
                $taskId,
                $nodeId,
            );
        }

        $runItem->refresh();
        $settled = false;
        register_shutdown_function(function () use (&$settled, $runItemId, $taskId, $nodeId): void {
            if ($settled || $runItemId <= 0) {
                return;
            }

            try {
                $this->failPrepared(
                    $runItemId,
                    'Bước bị gián đoạn (request kết thúc đột ngột / timeout).',
                    $taskId,
                    $nodeId,
                );
            } catch (\Throwable) {
                // Ignore — connection may already be closed.
            }
        });

        try {
            $projectSiteId = (int) ($project->site_id ?? 0);
            if ($projectSiteId <= 0) {
                $settled = true;

                return $this->failPrepared($runItemId, 'Thiếu site_id.', $taskId, $nodeId);
            }

            $context = $this->inputResolver->resolveForProjectTask(
                $task,
                function ($builder) use ($projectSiteId): void {
                    $builder->where('site_id', $projectSiteId);
                },
                cleanRestart: SeoProjectTask::normalizeType((string) ($task->type ?? '')) === SeoProjectTask::TYPE_REWRITE
                    && in_array((string) ($stepMeta['kind'] ?? ''), ['outline', 'content'], true),
            );

            if (! $context->article instanceof SeoArticle && (int) ($task->article_id ?? 0) > 0) {
                $article = SeoArticle::query()->find((int) $task->article_id);
                if ($article instanceof SeoArticle) {
                    $context = $context->withArticle($article);
                }
            }

            if (! $context->article instanceof SeoArticle && in_array($stepMeta['kind'], ['outline', 'content', 'image', 'faq', 'meta_title', 'meta_description', 'slug'], true)) {
                $settled = true;

                return $this->failPrepared(
                    $runItemId,
                    'Không thể chạy «'.$stepMeta['label'].'» vì bài này chưa có article.',
                    $taskId,
                    $nodeId,
                );
            }

            $priorSteps = $this->priorStepsForNode($run, $task, $nodeId, $seoTask);
            if (($stepMeta['kind'] ?? '') === 'content') {
                $priorSteps = $this->ensureOutlinePriorFromArticle($task, $priorSteps, $context->article);
            }

            $stepResult = $this->workflowRunner->runSingleStep($seoTask, $context, $nodeId, $priorSteps);

            // Provider có thể trả về sau khi user đã Ngắt / row đã terminal → discard.
            $runItem->refresh();
            if ($this->isExecutionTerminal($runItem)) {
                RuntimeLogger::info('seo.workflow_step.output_discarded', [
                    'run_id' => (int) $run->id,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'article_id' => (int) ($runItem->article_id ?? 0),
                    'attempt' => (int) $runItem->attempt,
                    'status' => (string) $runItem->status,
                    'failure_phase' => 'post_provider',
                    'reason' => $this->wasCancelledByUser($runItem) ? 'cancelled' : 'already_terminal',
                ]);
                $settled = true;
                if ($this->wasCancelledByUser($runItem)) {
                    $this->ensureCancelledFailureState($runItem, $run);

                    return [
                        'task_id' => $taskId,
                        'node_id' => $nodeId,
                        'run_item_id' => (int) $runItem->id,
                        'status' => 'failed',
                        'message' => (string) ($runItem->message ?: 'Đã ngắt bước đang chạy.'),
                    ];
                }

                return [
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => (int) $runItem->id,
                    'status' => 'failed',
                    'message' => (string) ($runItem->message ?: 'Bước đã kết thúc trước khi nhận output.'),
                ];
            }

            $status = (string) ($stepResult['status'] ?? '');

            if (in_array($status, ['failed', 'error'], true)) {
                $settled = true;
                RuntimeLogger::info('seo.workflow_step.terminal_failure', [
                    'run_id' => (int) $run->id,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'failure_phase' => 'step_result',
                    'message' => (string) ($stepResult['message'] ?? 'Bước thất bại.'),
                ]);

                return $this->failPrepared(
                    $runItemId,
                    (string) ($stepResult['message'] ?? 'Bước thất bại.'),
                    $taskId,
                    $nodeId,
                    $stepResult,
                );
            }

            if (! $this->assertExecutionStillActive($runItemId)) {
                $runItem->refresh();
                RuntimeLogger::info('seo.workflow_step.stale_execution_ignored', [
                    'run_id' => (int) $run->id,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'status' => (string) ($runItem->status ?? ''),
                    'failure_phase' => 'pre_persist',
                ]);
                $settled = true;
                if ($this->wasCancelledByUser($runItem)) {
                    $this->ensureCancelledFailureState($runItem, $run);
                }

                return [
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'status' => 'failed',
                    'message' => (string) ($runItem->message ?: 'Đã ngắt bước đang chạy.'),
                ];
            }

            $article = $context->article;
            if (($stepMeta['kind'] ?? '') === 'outline') {
                $persistError = $this->persistOutlineStepResult($article, $stepResult, (string) $stepMeta['label']);
                if ($persistError !== null) {
                    $settled = true;
                    RuntimeLogger::info('seo.workflow_step.terminal_failure', [
                        'run_id' => (int) $run->id,
                        'task_id' => $taskId,
                        'node_id' => $nodeId,
                        'run_item_id' => $runItemId,
                        'failure_phase' => 'outline_persist',
                        'message' => $persistError,
                    ]);

                    return $this->failPrepared($runItemId, $persistError, $taskId, $nodeId, $stepResult);
                }
            } elseif ($article instanceof SeoArticle) {
                $this->workflowRunner->applyParsedMetaFromSteps($article, [$stepResult]);
            }

            // Persist xong — re-check cancel trước khi Success (tránh đè terminal).
            $runItem->refresh();
            if ($this->isExecutionTerminal($runItem) || ! $this->assertExecutionStillActive($runItemId)) {
                RuntimeLogger::info('seo.workflow_step.stale_execution_ignored', [
                    'run_id' => (int) $run->id,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'status' => (string) $runItem->status,
                    'failure_phase' => 'pre_success',
                ]);
                $settled = true;
                if ($this->wasCancelledByUser($runItem)) {
                    $this->ensureCancelledFailureState($runItem, $run);
                }

                return [
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => (int) $runItem->id,
                    'status' => 'failed',
                    'message' => (string) ($runItem->message ?: 'Đã ngắt bước đang chạy.'),
                ];
            }

            $successPayload = [
                'status' => SeoProjectRunItemStatus::Success->value,
                'article_id' => $article instanceof SeoArticle ? (int) $article->id : $runItem->article_id,
                'message' => (string) ($stepResult['message'] ?? ('Đã chạy lại: '.$stepMeta['label'])),
                'error_code' => null,
                'error_message' => null,
                'output_snapshot' => [
                    'steps' => [$stepResult],
                    'node_id' => $nodeId,
                    'step_kind' => $stepMeta['kind'],
                    'step_label' => $stepMeta['label'],
                ],
                'finished_at' => now(),
            ];

            $saved = SeoProjectRunItem::query()
                ->whereKey($runItemId)
                ->where('status', SeoProjectRunItemStatus::Processing->value)
                ->where(function ($query): void {
                    $query->whereNull('error_message')
                        ->orWhere(function ($inner): void {
                            $inner->where('error_message', 'not like', '%Cancelled by user%')
                                ->where('error_message', 'not like', '%Cancelled by stop%')
                                ->where('error_message', 'not like', '%superseded by new step%');
                        });
                })
                ->update($successPayload);

            if ($saved === 0) {
                $runItem->refresh();
                RuntimeLogger::info('seo.workflow_step.stale_execution_ignored', [
                    'run_id' => (int) $run->id,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'status' => (string) $runItem->status,
                    'failure_phase' => 'success_update',
                    'attempt' => (int) $runItem->attempt,
                ]);
                if ($this->wasCancelledByUser($runItem)) {
                    $settled = true;
                    $this->ensureCancelledFailureState($runItem, $run);

                    return [
                        'task_id' => $taskId,
                        'node_id' => $nodeId,
                        'run_item_id' => (int) $runItem->id,
                        'status' => 'failed',
                        'message' => (string) ($runItem->message ?: 'Đã ngắt bước đang chạy.'),
                    ];
                }

                $settled = true;

                return $this->failPrepared(
                    $runItemId,
                    'Không lưu được kết quả bước (trạng thái đã đổi).',
                    $taskId,
                    $nodeId,
                    $stepResult,
                );
            }

            $runItem->refresh();
            $this->runItemService->syncMirrorAndCounters($run, false);
            $settled = true;

            return [
                'task_id' => $taskId,
                'node_id' => $nodeId,
                'run_item_id' => (int) $runItem->id,
                'status' => 'success',
                'message' => (string) ($runItem->message ?? ''),
                'step' => $stepResult,
                'article_id' => $article instanceof SeoArticle ? (int) $article->id : null,
            ];
        } catch (\Throwable $exception) {
            $settled = true;

            return $this->failPrepared(
                $runItemId,
                $exception->getMessage(),
                $taskId,
                $nodeId,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $stepMeta
     */
    private function assertDependencies(SeoProjectTask $task, array $stepMeta): ?string
    {
        $depends = $stepMeta['depends_on_kinds'] ?? [];
        if (! is_array($depends) || $depends === []) {
            return null;
        }

        $articleId = (int) ($task->article_id ?? 0);
        $article = null;
        if ($task->relationLoaded('article') && $task->getRelation('article') instanceof SeoArticle) {
            $article = $task->getRelation('article');
        } elseif ($articleId > 0) {
            $article = SeoArticle::query()->find($articleId);
        }

        foreach ($depends as $kind) {
            if ($kind !== 'outline') {
                continue;
            }

            if (! $article instanceof SeoArticle) {
                return ArticleGenerationInputResolver::REJECT_MESSAGE;
            }

            try {
                $this->articleGenerationInput->resolveForArticle($article);
            } catch (\InvalidArgumentException) {
                return ArticleGenerationInputResolver::REJECT_MESSAGE;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $stepResult
     */
    private function persistOutlineStepResult(?SeoArticle $article, array $stepResult, string $stepLabel): ?string
    {
        if (! $article instanceof SeoArticle) {
            return 'Không thể lưu outline vì bài này chưa có article.';
        }

        $markdown = '';
        foreach ($this->articleGenerationInput->candidatePayloadsFromStep($stepResult) as $candidate) {
            if ($this->articleGenerationInput->isValidArtifact($candidate)) {
                $markdown = $candidate;
                break;
            }
        }
        if ($markdown === '') {
            // Outline step mới chạy: cho phép persist nếu isUsable (kể cả chưa đủ marker legacy).
            $markdown = trim((string) ($stepResult['outline_markdown'] ?? ''));
            if ($markdown === '') {
                $markdown = trim((string) ($stepResult['output'] ?? ''));
            }
            if ($markdown === '' && is_array($stepResult['outputs'] ?? null)) {
                $markdown = trim((string) ($stepResult['outputs']['total']
                    ?? $stepResult['outputs']['out_outline']
                    ?? $stepResult['outputs']['out_main']
                    ?? ''));
            }
        }

        $saved = $this->outlineResolver->persist($article, $markdown);
        if (! $saved['ok']) {
            return (string) ($saved['message'] ?? ('Không lưu được kết quả «'.$stepLabel.'».'));
        }

        // Đồng bộ state meta phụ (parsed keywords/FAQ nếu step có) — outline đã persist canonical.
        $this->workflowRunner->applyParsedMetaFromSteps($article, [[
            ...$stepResult,
            'outline_markdown' => $saved['markdown'],
            'persists_as_outline' => true,
        ]]);

        RuntimeLogger::info('seo.project_run.outline_step_persisted', [
            'article_id' => (int) $article->id,
            'node_id' => (string) ($stepResult['node_id'] ?? ''),
            'outline_chars' => mb_strlen($saved['markdown']),
        ]);

        return null;
    }

    /**
     * Content retry: nếu prior snapshot thiếu outline, seed từ canonical article meta.
     *
     * @param  list<array<string, mixed>>  $priorSteps
     * @return list<array<string, mixed>>
     */
    private function ensureOutlinePriorFromArticle(
        SeoProjectTask $task,
        array $priorSteps,
        ?SeoArticle $article,
    ): array {
        if ($this->priorStepsContainUsableOutline($priorSteps)) {
            return $priorSteps;
        }

        if (! $article instanceof SeoArticle) {
            return $priorSteps;
        }

        try {
            $source = $this->articleGenerationInput->resolveForArticle($article);
        } catch (\InvalidArgumentException) {
            return $priorSteps;
        }

        $outline = $source->rawArtifact;
        $outlineNodeId = $this->catalog->firstPromptNodeIdForKind($task, 'outline') ?? 'outline-seed';

        $priorSteps[] = [
            'node_id' => $outlineNodeId,
            'type' => 'prompt',
            'title' => 'Outline',
            'status' => 'completed',
            'hook_key' => ArticleGenerationInputResolver::OUTLINE_HOOK_KEY,
            'output' => $outline,
            'outputs' => [
                'out_main' => $outline,
                'total' => $outline,
                'out_outline' => $outline,
                'task_1_outline' => $source->outlineSection,
                'task_2_vocabulary' => $source->writingInstructionsSection,
            ],
            'outline_markdown' => $outline,
            'persists_as_outline' => true,
            'result_id' => $source->sourcePromptResultId,
            'message' => 'Seed outline từ '.$source->sourceType.'.',
        ];

        return $priorSteps;
    }

    /**
     * @param  list<array<string, mixed>>  $priorSteps
     */
    private function priorStepsContainUsableOutline(array $priorSteps): bool
    {
        foreach ($priorSteps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (! $this->articleGenerationInput->isOutlineProducerStep($step)) {
                continue;
            }
            foreach ($this->articleGenerationInput->candidatePayloadsFromStep($step) as $candidate) {
                if ($this->articleGenerationInput->isValidArtifact($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function priorStepsForNode(
        SeoProjectRun $run,
        SeoProjectTask $task,
        string $nodeId,
        SeoTask $seoTask,
    ): array {
        $latest = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('task_id', (int) $task->id)
            ->where('status', SeoProjectRunItemStatus::Success->value)
            ->orderByDesc('id')
            ->get();

        foreach ($latest as $item) {
            $steps = is_array($item->output_snapshot['steps'] ?? null)
                ? $item->output_snapshot['steps']
                : [];
            if ($steps === []) {
                continue;
            }

            $prior = [];
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                if ((string) ($step['node_id'] ?? '') === $nodeId) {
                    break;
                }
                $prior[] = $step;
            }

            if ($prior !== []) {
                return $prior;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $stepResult
     * @return array<string, mixed>
     */
    private function failPrepared(
        int $runItemId,
        string $message,
        int $taskId = 0,
        string $nodeId = '',
        ?array $stepResult = null,
    ): array {
        $runItem = SeoProjectRunItem::query()->find($runItemId);
        if ($runItem instanceof SeoProjectRunItem) {
            $run = SeoProjectRun::query()->find((int) $runItem->run_id);

            if ($this->wasCancelledByUser($runItem)) {
                if ($run instanceof SeoProjectRun) {
                    $this->ensureCancelledFailureState($runItem->fresh() ?? $runItem, $run);
                }

                return [
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'status' => 'failed',
                    'message' => (string) ($runItem->message ?: 'Đã ngắt bước đang chạy.'),
                ];
            }

            if ($this->isExecutionTerminal($runItem)
                && (string) $runItem->status !== SeoProjectRunItemStatus::Processing->value
                && (string) $runItem->status !== SeoProjectRunItemStatus::Pending->value
            ) {
                RuntimeLogger::info('seo.workflow_step.stale_execution_ignored', [
                    'run_item_id' => $runItemId,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'status' => (string) $runItem->status,
                    'failure_phase' => 'fail_prepared',
                ]);

                return [
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'run_item_id' => $runItemId,
                    'status' => 'failed',
                    'message' => (string) ($runItem->message ?: $message),
                ];
            }

            $affected = SeoProjectRunItem::query()
                ->whereKey($runItemId)
                ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
                ->where(function ($query): void {
                    $query->whereNull('error_message')
                        ->orWhere(function ($inner): void {
                            $inner->where('error_message', 'not like', '%Cancelled by user%')
                                ->where('error_message', 'not like', '%Cancelled by stop%')
                                ->where('error_message', 'not like', '%superseded by new step%');
                        });
                })
                ->update([
                    'status' => SeoProjectRunItemStatus::Failed->value,
                    'message' => $message,
                    'error_message' => $message,
                    'output_snapshot' => $stepResult !== null
                        ? ['steps' => [$stepResult], 'node_id' => $nodeId]
                        : $runItem->output_snapshot,
                    'finished_at' => now(),
                ]);

            if ($affected === 0) {
                $runItem->refresh();
                RuntimeLogger::info('seo.workflow_step.stale_execution_ignored', [
                    'run_item_id' => $runItemId,
                    'task_id' => $taskId,
                    'node_id' => $nodeId,
                    'status' => (string) $runItem->status,
                    'failure_phase' => 'fail_prepared_race',
                ]);
                if ($this->wasCancelledByUser($runItem) && $run instanceof SeoProjectRun) {
                    $this->ensureCancelledFailureState($runItem, $run);
                }
            } elseif ($run instanceof SeoProjectRun) {
                $this->runItemService->syncMirrorAndCounters($run, false);
            }

            RuntimeLogger::info('seo.workflow_step.terminal_failure', [
                'run_item_id' => $runItemId,
                'task_id' => $taskId,
                'node_id' => $nodeId,
                'affected' => $affected,
                'failure_phase' => 'fail_prepared',
                'message' => $message,
            ]);
        }

        return [
            'task_id' => $taskId,
            'node_id' => $nodeId,
            'run_item_id' => $runItemId,
            'status' => 'failed',
            'message' => $message,
        ];
    }

    private function isExecutionTerminal(SeoProjectRunItem $runItem): bool
    {
        if ($this->wasCancelledByUser($runItem)) {
            return true;
        }

        $status = (string) $runItem->status;

        return ContentProjectExecutionStatus::isTerminal($status);
    }


    private function assertExecutionStillActive(int $runItemId): bool
    {
        $runItem = SeoProjectRunItem::query()->find($runItemId);
        if (! $runItem instanceof SeoProjectRunItem) {
            return false;
        }

        if ($this->wasCancelledByUser($runItem)) {
            return false;
        }

        return (string) $runItem->status === SeoProjectRunItemStatus::Processing->value;
    }

    private function wasCancelledByUser(SeoProjectRunItem $runItem): bool
    {
        // Chỉ nhìn error_message — request treo có thể đè status Pending/Failed → Processing
        // nhưng phải giữ marker cancel để không “hồi sinh” busy.
        $error = strtolower(trim((string) ($runItem->error_message ?? '')));

        return str_contains($error, 'cancelled by user')
            || str_contains($error, 'cancelled by stop')
            || str_contains($error, 'superseded by new step');
    }

    private function ensureCancelledFailureState(SeoProjectRunItem $runItem, SeoProjectRun $run): void
    {
        if (
            (string) $runItem->status === SeoProjectRunItemStatus::Failed->value
            && $runItem->finished_at !== null
            && $this->wasCancelledByUser($runItem)
        ) {
            return;
        }

        // Conditional: không đè Success; chỉ chốt terminal từ active hoặc processing lệch marker.
        $marker = $this->wasCancelledByUser($runItem)
            ? (string) $runItem->error_message
            : 'Cancelled by user.';

        SeoProjectRunItem::query()
            ->whereKey((int) $runItem->id)
            ->where(function ($query): void {
                $query->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
                    ->orWhere(function ($inner): void {
                        $inner->where('status', SeoProjectRunItemStatus::Failed->value)
                            ->whereNull('finished_at');
                    });
            })
            ->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => 'Đã ngắt bước đang chạy.',
                'error_message' => $marker,
                'finished_at' => now(),
            ]);

        $runItem->refresh();
        $this->runItemService->syncMirrorAndCounters($run, false);
    }

    /**
     * Đánh dấu step Pending/Processing quá hạn thành Failed — tránh UI «Đang chạy» treo.
     */
    public function abandonStaleActiveSteps(int $runId, ?int $taskId = null): int
    {
        if ($runId <= 0) {
            return 0;
        }

        $cutoff = now()->subMinutes($this->runItemService->staleMinutes());
        $query = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('action', 'like', 'step:%')
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses());

        if ($taskId !== null && $taskId > 0) {
            $query->where('task_id', $taskId);
        }

        $count = 0;
        $touchedRuns = [];

        foreach ($query->get() as $item) {
            if (! $item instanceof SeoProjectRunItem) {
                continue;
            }

            $status = (string) $item->status;
            $stale = false;

            if ($status === SeoProjectRunItemStatus::Processing->value) {
                $stale = $this->runItemService->isStale($item);
            } else {
                $reference = $item->updated_at ?? $item->created_at;
                $stale = $reference === null || $reference->lte($cutoff);
            }

            if (! $stale) {
                continue;
            }

            $item->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => 'Bước bị treo / request đứt — đánh dấu thất bại tự động.',
                'error_message' => 'Stale workflow step retry abandoned.',
                'finished_at' => now(),
            ]);
            $count++;
            $touchedRuns[(int) $item->run_id] = true;
        }

        foreach (array_keys($touchedRuns) as $touchedRunId) {
            $run = SeoProjectRun::query()->find((int) $touchedRunId);
            if ($run instanceof SeoProjectRun) {
                $this->runItemService->syncMirrorAndCounters($run, false);
            }
        }

        return $count;
    }

    /**
     * Ngắt tay — clear step Pending/Processing của task (ưu tiên task_id, fallback article_id hẹp).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     cancelled: int,
     *     already_idle: bool,
     *     match_mode: string,
     *     run_id: int,
     *     task_id: int,
     *     article_id: int,
     *     node_id: string,
     *     step_action: string,
     *     active_before: int,
     *     active_after: int,
     *     affected_item_ids: list<int>
     * }
     */
    public function cancelActiveStep(SeoProjectRun $run, int $taskId, string $nodeId = ''): array
    {
        $nodeId = trim($nodeId);
        $stepAction = $nodeId !== '' ? $this->stepAction($nodeId) : '';
        $articleId = $taskId > 0
            ? (int) (SeoProjectTask::query()->whereKey($taskId)->value('article_id') ?? 0)
            : 0;

        $base = [
            'run_id' => (int) $run->id,
            'task_id' => $taskId,
            'article_id' => $articleId,
            'node_id' => $nodeId,
            'step_action' => $stepAction,
            'active_before' => 0,
            'active_after' => 0,
            'affected_item_ids' => [],
            'already_idle' => false,
            'match_mode' => 'none',
            'cancelled' => 0,
        ];

        if ($taskId <= 0) {
            $payload = $base + [
                'success' => false,
                'message' => 'Thiếu task.',
            ];
            $this->logCancelDiagnostic($payload);

            return $payload;
        }

        $resolution = $this->resolveActiveStepIdsForCancel((int) $run->id, $taskId, $articleId);
        $activeBefore = count($resolution['ids']);
        $base['active_before'] = $activeBefore;
        $base['match_mode'] = $resolution['match_mode'];

        if ($activeBefore === 0) {
            $idleCancelled = $this->hasCancelledStepMarker((int) $run->id, $taskId, $articleId);
            $payload = $base + [
                'success' => $idleCancelled,
                'already_idle' => $idleCancelled,
                'message' => $idleCancelled
                    ? 'Bước đã được ngắt trước đó. Có thể chọn lại prompt.'
                    : 'Không tìm thấy bước đang chạy để ngắt (kiểm tra task_id/action).',
                'cancelled' => 0,
                'active_after' => 0,
            ];
            $this->logCancelDiagnostic($payload);

            return $payload;
        }

        $affectedIds = $resolution['ids'];
        $cancelled = SeoProjectRunItem::query()
            ->whereIn('id', $affectedIds)
            ->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => 'Đã ngắt bước đang chạy.',
                'error_message' => 'Cancelled by user.',
                'finished_at' => now(),
            ]);

        $this->runItemService->syncMirrorAndCounters($run, false);

        $activeAfter = $this->countActiveStepsForTask((int) $run->id, $taskId, $articleId);
        $payload = $base + [
            'success' => $cancelled > 0 && $activeAfter === 0,
            'already_idle' => false,
            'message' => $cancelled > 0 && $activeAfter === 0
                ? 'Đã ngắt bước đang chạy. Có thể chọn lại prompt.'
                : 'Ngắt không clear hết bước đang chạy (cancelled='.$cancelled.', active_after='.$activeAfter.').',
            'cancelled' => (int) $cancelled,
            'active_after' => $activeAfter,
            'affected_item_ids' => array_map('intval', $affectedIds),
        ];
        $this->logCancelDiagnostic($payload);

        return $payload;
    }

    /**
     * Đánh Failed mọi step active của 1 task trong run.
     * Ưu tiên task_id; chỉ fallback article_id khi task_id miss và row không thuộc task khác.
     */
    public function cancelAllActiveStepsForTask(
        SeoProjectRun $run,
        int $taskId,
        string $errorMessage = 'Cancelled.',
        string $message = 'Đã ngắt bước đang chạy.',
    ): int {
        if ($taskId <= 0) {
            return 0;
        }

        $articleId = (int) (SeoProjectTask::query()->whereKey($taskId)->value('article_id') ?? 0);
        $resolution = $this->resolveActiveStepIdsForCancel((int) $run->id, $taskId, $articleId);
        if ($resolution['ids'] === []) {
            return 0;
        }

        $cancelled = SeoProjectRunItem::query()
            ->whereIn('id', $resolution['ids'])
            ->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => $message,
                'error_message' => $errorMessage,
                'finished_at' => now(),
            ]);

        $this->runItemService->syncMirrorAndCounters($run, false);

        return (int) $cancelled;
    }

    /**
     * @return array{ids: list<int>, match_mode: string}
     */
    private function resolveActiveStepIdsForCancel(int $runId, int $taskId, int $articleId): array
    {
        $byTask = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', 'like', 'step:%')
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($byTask !== []) {
            return ['ids' => $byTask, 'match_mode' => 'task_id'];
        }

        if ($articleId <= 0) {
            return ['ids' => [], 'match_mode' => 'none'];
        }

        // Fallback hẹp: cùng article, task_id null/0 — không đụng task khác cùng article.
        $byArticle = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('article_id', $articleId)
            ->where(function ($query): void {
                $query->whereNull('task_id')->orWhere('task_id', 0);
            })
            ->where('action', 'like', 'step:%')
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($byArticle !== []) {
            return ['ids' => $byArticle, 'match_mode' => 'article_id_null_task'];
        }

        return ['ids' => [], 'match_mode' => 'none'];
    }

    private function countActiveStepsForTask(int $runId, int $taskId, int $articleId): int
    {
        return count($this->resolveActiveStepIdsForCancel($runId, $taskId, $articleId)['ids']);
    }

    private function hasCancelledStepMarker(int $runId, int $taskId, int $articleId): bool
    {
        $query = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('action', 'like', 'step:%')
            ->where('status', SeoProjectRunItemStatus::Failed->value)
            ->where(function ($builder) use ($taskId, $articleId): void {
                $builder->where('task_id', $taskId);
                if ($articleId > 0) {
                    $builder->orWhere(function ($inner) use ($articleId): void {
                        $inner->where('article_id', $articleId)
                            ->where(function ($taskNull): void {
                                $taskNull->whereNull('task_id')->orWhere('task_id', 0);
                            });
                    });
                }
            });

        foreach ($query->orderByDesc('id')->limit(10)->get(['error_message']) as $row) {
            if ($this->wasCancelledByUser($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logCancelDiagnostic(array $payload): void
    {
        RuntimeLogger::info('seo.project_run.cancel_workflow_step', [
            'run_id' => (int) ($payload['run_id'] ?? 0),
            'task_id' => (int) ($payload['task_id'] ?? 0),
            'article_id' => (int) ($payload['article_id'] ?? 0),
            'node_id' => (string) ($payload['node_id'] ?? ''),
            'step_action' => (string) ($payload['step_action'] ?? ''),
            'active_before' => (int) ($payload['active_before'] ?? 0),
            'cancelled_count' => (int) ($payload['cancelled'] ?? 0),
            'affected_item_ids' => array_values(array_map('intval', $payload['affected_item_ids'] ?? [])),
            'active_after' => (int) ($payload['active_after'] ?? 0),
            'match_mode' => (string) ($payload['match_mode'] ?? ''),
            'already_idle' => (bool) ($payload['already_idle'] ?? false),
            'success' => (bool) ($payload['success'] ?? false),
            'request_user_id' => (int) (auth()->id() ?? 0),
        ]);
    }

    /**
     * Ngắt mọi step Pending/Processing của cả run (Stop / force stop).
     */
    public function cancelAllActiveSteps(SeoProjectRun $run): int
    {
        $items = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('action', 'like', 'step:%')
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        foreach ($items as $item) {
            $item->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => 'Đã ngắt bước đang chạy (Stop).',
                'error_message' => 'Cancelled by stop.',
                'finished_at' => now(),
            ]);
        }

        $this->runItemService->syncMirrorAndCounters($run, false);

        return $items->count();
    }

    public function stepAction(string $nodeId): string
    {
        $raw = 'step:'.$nodeId;
        if (strlen($raw) <= 64) {
            return $raw;
        }

        return 'step:'.substr(hash('sha256', $nodeId), 0, 40);
    }

    /**
     * @return array<string, string>
     */
    private function activeStepStatuses(int $runId, int $taskId): array
    {
        $rows = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', 'like', 'step:%')
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->whereNull('finished_at')
            ->get(['action', 'status']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->action] = (string) $row->status;
        }

        return $map;
    }

    /**
     * @return array<string, array{status: string, finished_at: string|null}>
     */
    private function latestStepFinishes(int $runId, int $taskId): array
    {
        $rows = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', 'like', 'step:%')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->get(['action', 'status', 'finished_at']);

        $map = [];
        foreach ($rows as $row) {
            $action = (string) $row->action;
            $status = (string) $row->status;
            if (! isset($map[$action])) {
                $map[$action] = [
                    'status' => $status,
                    'finished_at' => null,
                ];
            }
            // «Lần cuối» chỉ khi Success — không coi fail/invalid là lần outline dùng được.
            if (
                $map[$action]['finished_at'] === null
                && $status === SeoProjectRunItemStatus::Success->value
            ) {
                $map[$action]['finished_at'] = $row->finished_at
                    ?->timezone(config('app.timezone'))
                    ->format('H:i');
            }
        }

        return $map;
    }

    /**
     * @return array<string, string> node_id => status
     */
    private function activeStepStatusesByNode(int $runId, int $taskId): array
    {
        $rows = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', 'like', 'step:%')
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->whereNull('finished_at')
            ->get(['action', 'status', 'input_snapshot']);

        $map = [];
        foreach ($rows as $row) {
            $nodeId = $this->nodeIdFromStepItem($row);
            if ($nodeId === null) {
                continue;
            }
            $map[$nodeId] = (string) $row->status;
        }

        return $map;
    }

    /**
     * @return array<string, array{status: string, finished_at: string|null}>
     */
    private function latestStepFinishesByNode(int $runId, int $taskId): array
    {
        $rows = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', 'like', 'step:%')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->get(['action', 'status', 'finished_at', 'input_snapshot']);

        $map = [];
        foreach ($rows as $row) {
            $nodeId = $this->nodeIdFromStepItem($row);
            if ($nodeId === null) {
                continue;
            }
            $status = (string) $row->status;
            if (! isset($map[$nodeId])) {
                $map[$nodeId] = [
                    'status' => $status,
                    'finished_at' => null,
                ];
            }
            if (
                $map[$nodeId]['finished_at'] === null
                && $status === SeoProjectRunItemStatus::Success->value
            ) {
                $map[$nodeId]['finished_at'] = $row->finished_at
                    ?->timezone(config('app.timezone'))
                    ->format('H:i');
            }
        }

        return $map;
    }

    private function nodeIdFromStepItem(SeoProjectRunItem $row): ?string
    {
        $snapshot = is_array($row->input_snapshot) ? $row->input_snapshot : [];
        $fromSnap = trim((string) ($snapshot['node_id'] ?? $snapshot['target_node_id'] ?? ''));
        if ($fromSnap !== '') {
            return $fromSnap;
        }

        $action = (string) $row->action;
        if (str_starts_with($action, 'step:rr:')) {
            return null;
        }
        if (str_starts_with($action, 'step:')) {
            $nodeId = substr($action, strlen('step:'));

            return $nodeId !== '' ? $nodeId : null;
        }

        return null;
    }
}
