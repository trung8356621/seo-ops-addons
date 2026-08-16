<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;
use Omnichannel\Addons\ContentProjects\Support\ProjectRunIdempotencyKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\ProjectRunItemLegacyJsonPresenter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SeoProjectRunItemService
{
    public function __construct(
        private readonly ProjectRunIdempotencyKeyGenerator $idempotencyKeys,
        private readonly ProjectRunItemLegacyJsonPresenter $jsonPresenter,
        private readonly SeoProjectTaskEventRecorder $eventRecorder,
    ) {}

    public function staleMinutes(): int
    {
        $minutes = (int) config('seo-content-ai.content_project.run_item_stale_minutes', 30);

        return max(1, $minutes);
    }

    public function resolveAction(SeoProjectTask $task): SeoProjectRunAction
    {
        return SeoProjectRunAction::fromLegacyTaskType((string) $task->type);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildInputSnapshot(SeoProject $project, SeoProjectTask $task): array
    {
        return [
            'task_id' => (int) $task->id,
            'project_id' => (int) $project->id,
            'site_id' => (int) ($task->site_id ?? $project->site_id ?? 0),
            'type' => (string) $task->type,
            'post_type' => $task->post_type,
            'source_content' => (string) $task->source_content,
            'keyword' => $task->keyword,
            'title' => $task->title,
            'secondary_description' => $task->secondary_description,
            'rewrite_mode' => $task->rewrite_mode,
            'rewrite_notes' => $task->rewrite_notes,
            'description' => $task->description,
            'loai_san_pham' => $task->loai_san_pham,
            'target_date' => $task->target_date?->format('Y-m-d'),
            'article_id' => $task->article_id !== null ? (int) $task->article_id : null,
        ];
    }

    public function buildOperationVersion(SeoProjectTask $task, SeoProjectRunAction $action): string
    {
        $payload = match ($action) {
            SeoProjectRunAction::ArticleRewrite => [
                'task_id' => (int) $task->id,
                'article_id' => (int) ($task->article_id ?? 0),
                'rewrite_mode' => SeoProjectTask::REWRITE_MODE_CONTENT,
                'rewrite_notes' => (string) ($task->rewrite_notes ?? ''),
                'source_content' => (string) $task->source_content,
                'keyword' => (string) ($task->keyword ?? ''),
                'title' => (string) ($task->title ?? ''),
                'secondary_description' => (string) ($task->secondary_description ?? ''),
                'description' => (string) ($task->description ?? ''),
                'site_id' => (int) ($task->site_id ?? 0),
            ],
            SeoProjectRunAction::ArticleUpdate => [
                'task_id' => (int) $task->id,
                'article_id' => (int) ($task->article_id ?? 0),
                'source_content' => (string) $task->source_content,
                'rewrite_notes' => (string) ($task->rewrite_notes ?? ''),
                'description' => (string) ($task->description ?? ''),
                'site_id' => (int) ($task->site_id ?? 0),
            ],
            default => [
                'task_id' => (int) $task->id,
                'type' => (string) $task->type,
                'post_type' => SeoProjectTask::normalizePostType($task->post_type),
                'source_content' => (string) $task->source_content,
                'keyword' => (string) ($task->keyword ?? ''),
                'title' => (string) ($task->title ?? ''),
                'secondary_description' => (string) ($task->secondary_description ?? ''),
                'description' => (string) ($task->description ?? ''),
                'loai_san_pham' => (string) ($task->loai_san_pham ?? ''),
                'target_date' => $task->target_date?->format('Y-m-d') ?? '',
                'site_id' => (int) ($task->site_id ?? 0),
            ],
        };

        return $this->idempotencyKeys->contentVersion($payload);
    }

    public function findByLogicalOperation(int $runId, int $taskId, string $action): ?SeoProjectRunItem
    {
        $item = SeoProjectRunItem::query()
            ->where('run_id', $runId)
            ->where('task_id', $taskId)
            ->where('action', $action)
            ->first();

        return $item instanceof SeoProjectRunItem ? $item : null;
    }

    public function prepareOperation(SeoProjectRun $run, SeoProject $project, SeoProjectTask $task): SeoProjectRunItem
    {
        $action = $this->resolveAction($task);
        $version = $this->buildOperationVersion($task, $action);
        // Key unique toàn bảng — bắt buộc gắn run_id, nếu không rerun/run mới cùng task sẽ duplicate.
        $version = sprintf('%s#run:%d', $version, (int) $run->id);
        $idempotencyKey = $this->idempotencyKeys->generate((int) $task->id, $action->value, $version);
        $snapshot = $this->buildInputSnapshot($project, $task);

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $run,
            $task,
            $action,
            $idempotencyKey,
            $snapshot,
        ): SeoProjectRunItem {
            /** @var SeoProjectRunItem|null $existing */
            $existing = SeoProjectRunItem::query()
                ->where('run_id', (int) $run->id)
                ->where('task_id', (int) $task->id)
                ->where('action', $action->value)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SeoProjectRunItem) {
                if ((string) $existing->status === SeoProjectRunItemStatus::Success->value) {
                    return $existing;
                }

                $existing->fill([
                    'idempotency_key' => $idempotencyKey,
                    'input_snapshot' => $snapshot,
                    'article_id' => $task->article_id !== null ? (int) $task->article_id : $existing->article_id,
                    'status' => SeoProjectRunItemStatus::Pending->value,
                    'output_snapshot' => null,
                    'message' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'started_at' => null,
                    'finished_at' => null,
                ]);
                $existing->save();
                $this->markTaskQueuedForGeneration($task);

                return $existing->fresh() ?? $existing;
            }

            $created = SeoProjectRunItem::query()->create([
                'run_id' => (int) $run->id,
                'task_id' => (int) $task->id,
                'article_id' => $task->article_id !== null ? (int) $task->article_id : null,
                'action' => $action->value,
                'status' => SeoProjectRunItemStatus::Pending->value,
                'attempt' => 1,
                'idempotency_key' => $idempotencyKey,
                'input_snapshot' => $snapshot,
                'output_snapshot' => null,
                'message' => null,
                'error_code' => null,
                'error_message' => null,
            ]);
            $this->markTaskQueuedForGeneration($task);

            return $created;
        });
    }

    /**
     * Accept generate/rerun immediately: Failed → Pending so Failed-only filter/cards drop the row.
     * Latest attempt error comes from the new pending run item (cleared), not history.
     */
    private function markTaskQueuedForGeneration(SeoProjectTask $task): void
    {
        $status = (string) ($task->status ?? '');
        if (in_array($status, [
            SeoProjectTask::STATUS_CANCELLED,
            'cancelled',
            'archived',
            SeoProjectTask::STATUS_PENDING,
        ], true)) {
            return;
        }

        $task->update([
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);
        $task->status = SeoProjectTask::STATUS_PENDING;
    }

    /**
     * @return array{
     *     outcome: 'claimed'|'already_processed'|'already_processing'|'skipped'|'failed',
     *     run_item: SeoProjectRunItem|null,
     *     task: SeoProjectTask|null,
     *     error_code: string|null,
     *     message: string|null,
     *     article_id: int|null
     * }
     */
    public function claimForExecution(
        SeoProjectRun $run,
        int $taskId,
        SeoProjectRunAction $action,
        bool $forceRetry = false,
    ): array
    {
        return DB::connection('omi_seo_ai')->transaction(function () use ($run, $taskId, $action, $forceRetry): array {
            /** @var SeoProjectTask|null $task */
            $task = SeoProjectTask::withTrashed()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first();

            if (! $task instanceof SeoProjectTask) {
                $runItem = $this->findByLogicalOperation((int) $run->id, $taskId, $action->value);
                if ($runItem instanceof SeoProjectRunItem) {
                    $this->markFailed(
                        $runItem,
                        ContentProjectErrorCode::TaskNotFound,
                        'Task không còn tồn tại.',
                        lock: false,
                    );
                }

                return [
                    'outcome' => 'failed',
                    'run_item' => $runItem?->fresh(),
                    'task' => null,
                    'error_code' => ContentProjectErrorCode::TaskNotFound->value,
                    'message' => 'Task không còn tồn tại.',
                    'article_id' => null,
                ];
            }

            if ($task->trashed() || $task->deleted_at !== null) {
                $runItem = $this->findByLogicalOperation((int) $run->id, $taskId, $action->value);
                if ($runItem instanceof SeoProjectRunItem) {
                    $this->markFailed(
                        $runItem,
                        ContentProjectErrorCode::TaskDeleted,
                        'Task đã bị xóa (soft-delete).',
                        lock: false,
                    );
                }

                return [
                    'outcome' => 'failed',
                    'run_item' => $runItem?->fresh(),
                    'task' => $task,
                    'error_code' => ContentProjectErrorCode::TaskDeleted->value,
                    'message' => 'Task đã bị xóa (soft-delete).',
                    'article_id' => null,
                ];
            }

            if ($task->archived_at !== null
                || (string) $task->status === 'archived'
            ) {
                return $this->failClaim($run, $task, $action, ContentProjectErrorCode::TaskArchived, 'Task đã archive.');
            }

            if ((string) $task->status === 'cancelled'
                || (string) $task->status === SeoProjectTask::STATUS_CANCELLED
            ) {
                return $this->failClaim($run, $task, $action, ContentProjectErrorCode::TaskCancelled, 'Task đã cancelled.');
            }

            /** @var SeoProjectRunItem|null $runItem */
            $runItem = SeoProjectRunItem::query()
                ->where('run_id', (int) $run->id)
                ->where('task_id', (int) $task->id)
                ->where('action', $action->value)
                ->lockForUpdate()
                ->first();

            if (! $runItem instanceof SeoProjectRunItem) {
                $project = $run->project;
                if (! $project instanceof SeoProject) {
                    $run->loadMissing('project');
                    $project = $run->project;
                }
                if ($project instanceof SeoProject) {
                    $runItem = $this->prepareOperation($run, $project, $task);
                    $runItem = SeoProjectRunItem::query()
                        ->whereKey((int) $runItem->id)
                        ->lockForUpdate()
                        ->first();
                }
            }

            if (! $runItem instanceof SeoProjectRunItem) {
                return [
                    'outcome' => 'failed',
                    'run_item' => null,
                    'task' => $task,
                    'error_code' => ContentProjectErrorCode::RunItemNotFound->value,
                    'message' => 'Không tìm thấy run item.',
                    'article_id' => null,
                ];
            }

            $relation = $this->resolveArticleRelation($task, $runItem, $action);
            if ($relation['error_code'] !== null) {
                $errorCode = ContentProjectErrorCode::tryFrom((string) $relation['error_code'])
                    ?? ContentProjectErrorCode::ArticleRelationMissing;
                $this->markFailed(
                    $runItem,
                    $errorCode,
                    (string) $relation['message'],
                    lock: false,
                );
                $this->safeRecordEvent(
                    $task,
                    SeoProjectTaskEventType::ArticleRelationMissing,
                    (string) $task->status,
                    (string) $task->status,
                    [
                        'run_item_id' => (int) $runItem->id,
                        'action' => $action->value,
                        'error_code' => $relation['error_code'],
                    ],
                    (int) $run->id,
                );

                return [
                    'outcome' => 'failed',
                    'run_item' => $runItem->fresh(),
                    'task' => $task->fresh(),
                    'error_code' => $relation['error_code'],
                    'message' => $relation['message'],
                    'article_id' => $relation['article_id'],
                ];
            }

            if (
                ! $forceRetry
                && $action === SeoProjectRunAction::ArticleCreate
                && $relation['article_id'] !== null
                && $relation['article_id'] > 0
            ) {
                $this->markSkipped(
                    $runItem,
                    'Đã có article — reuse, không tạo mới.',
                    $relation['article_id'],
                    ContentProjectErrorCode::OperationAlreadyProcessed,
                    lock: false,
                );

                return [
                    'outcome' => 'already_processed',
                    'run_item' => $runItem->fresh(),
                    'task' => $task->fresh(),
                    'error_code' => ContentProjectErrorCode::OperationAlreadyProcessed->value,
                    'message' => 'Đã có article — reuse.',
                    'article_id' => $relation['article_id'],
                ];
            }

            $status = (string) $runItem->status;
            // Idempotent skip chỉ cho lần claim thường. «Chạy lại» ($forceRetry) phải claim lại
            // kể cả khi run item đã success — nếu không UI xanh ~1s mà AI không chạy.
            if ($status === SeoProjectRunItemStatus::Success->value && ! $forceRetry) {
                return [
                    'outcome' => 'already_processed',
                    'run_item' => $runItem,
                    'task' => $task,
                    'error_code' => ContentProjectErrorCode::OperationAlreadyProcessed->value,
                    'message' => 'Operation đã success.',
                    'article_id' => $runItem->article_id !== null ? (int) $runItem->article_id : null,
                ];
            }

            if ($status === SeoProjectRunItemStatus::Processing->value && ! $this->isStale($runItem)) {
                return [
                    'outcome' => 'already_processing',
                    'run_item' => $runItem,
                    'task' => $task,
                    'error_code' => ContentProjectErrorCode::OperationAlreadyProcessing->value,
                    'message' => 'Operation đang processing.',
                    'article_id' => $runItem->article_id !== null ? (int) $runItem->article_id : null,
                ];
            }

            $version = $this->buildOperationVersion($task, $action);
            $attempt = (int) $runItem->attempt;
            if (
                $forceRetry
                || $status === SeoProjectRunItemStatus::Failed->value
                || $status === SeoProjectRunItemStatus::Processing->value
                || $status === SeoProjectRunItemStatus::Success->value
                || $status === SeoProjectRunItemStatus::Skipped->value
            ) {
                $attempt++;
            }

            // Force retry: gắn attempt vào version để idempotency key không trùng lần success trước.
            // Luôn salt run_id — unique constraint là global trên idempotency_key.
            $operationVersion = $forceRetry
                ? sprintf('%s#run:%d#attempt:%d', $version, (int) $run->id, max(1, $attempt))
                : sprintf('%s#run:%d', $version, (int) $run->id);
            $idempotencyKey = $this->idempotencyKeys->generate((int) $task->id, $action->value, $operationVersion);

            $runItem->fill([
                'status' => SeoProjectRunItemStatus::Processing->value,
                'attempt' => max(1, $attempt),
                'idempotency_key' => $idempotencyKey,
                'error_code' => null,
                'error_message' => null,
                'started_at' => now(),
                'finished_at' => null,
                'article_id' => $relation['article_id'] ?? $runItem->article_id,
            ]);
            $runItem->save();

            if ((string) $task->status !== SeoProjectTask::STATUS_WRITING) {
                SeoProjectTask::query()->whereKey((int) $task->id)->update([
                    'status' => SeoProjectTask::STATUS_WRITING,
                ]);
                $task->refresh();
            }

            $this->safeRecordEvent(
                $task,
                SeoProjectTaskEventType::TaskProcessing,
                (string) $task->getOriginal('status') ?: (string) $task->status,
                SeoProjectTask::STATUS_WRITING,
                [
                    'run_item_id' => (int) $runItem->id,
                    'action' => $action->value,
                    'attempt' => (int) $runItem->attempt,
                ],
                (int) $run->id,
            );

            return [
                'outcome' => 'claimed',
                'run_item' => $runItem->fresh() ?? $runItem,
                'task' => $task->fresh() ?? $task,
                'error_code' => null,
                'message' => null,
                'article_id' => $relation['article_id'],
            ];
        });
    }

    public function isStale(SeoProjectRunItem $runItem): bool
    {
        if ((string) $runItem->status !== SeoProjectRunItemStatus::Processing->value) {
            return false;
        }

        if ($runItem->finished_at !== null) {
            return false;
        }

        if ($runItem->started_at === null) {
            return true;
        }

        return $runItem->started_at->lte(now()->subMinutes($this->staleMinutes()));
    }

    /**
     * @param  array<string, mixed>|null  $outputSnapshot
     */
    public function markSuccess(
        SeoProjectRunItem $runItem,
        ?int $articleId = null,
        ?string $message = null,
        ?array $outputSnapshot = null,
        bool $lock = true,
    ): SeoProjectRunItem {
        $apply = function () use ($runItem, $articleId, $message, $outputSnapshot, $lock): SeoProjectRunItem {
            $item = $lock
                ? SeoProjectRunItem::query()->whereKey((int) $runItem->id)->lockForUpdate()->first()
                : $runItem;

            if (! $item instanceof SeoProjectRunItem) {
                return $runItem;
            }

            $item->fill([
                'status' => SeoProjectRunItemStatus::Success->value,
                'article_id' => $articleId !== null && $articleId > 0 ? $articleId : $item->article_id,
                'message' => $message,
                'error_code' => null,
                'error_message' => null,
                'output_snapshot' => $outputSnapshot ?? $item->output_snapshot,
                'finished_at' => now(),
            ]);
            $item->save();

            return $item->fresh() ?? $item;
        };

        return $lock
            ? DB::connection('omi_seo_ai')->transaction($apply)
            : $apply();
    }

    public function markFailed(
        SeoProjectRunItem $runItem,
        ContentProjectErrorCode|string $errorCode,
        string $errorMessage,
        ?string $message = null,
        ?array $outputSnapshot = null,
        ?int $articleId = null,
        bool $lock = true,
    ): SeoProjectRunItem {
        $code = $errorCode instanceof ContentProjectErrorCode ? $errorCode->value : $errorCode;

        $apply = function () use ($runItem, $code, $errorMessage, $message, $outputSnapshot, $articleId, $lock): SeoProjectRunItem {
            $item = $lock
                ? SeoProjectRunItem::query()->whereKey((int) $runItem->id)->lockForUpdate()->first()
                : $runItem;

            if (! $item instanceof SeoProjectRunItem) {
                return $runItem;
            }

            $item->fill([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'error_code' => $code,
                'error_message' => $errorMessage,
                'message' => $message ?? $errorMessage,
                'output_snapshot' => $outputSnapshot ?? $item->output_snapshot,
                'article_id' => $articleId !== null && $articleId > 0 ? $articleId : $item->article_id,
                'finished_at' => now(),
            ]);
            $item->save();

            return $item->fresh() ?? $item;
        };

        return $lock
            ? DB::connection('omi_seo_ai')->transaction($apply)
            : $apply();
    }

    public function markSkipped(
        SeoProjectRunItem $runItem,
        string $message,
        ?int $articleId = null,
        ?ContentProjectErrorCode $errorCode = null,
        bool $lock = true,
    ): SeoProjectRunItem {
        $apply = function () use ($runItem, $message, $articleId, $errorCode, $lock): SeoProjectRunItem {
            $item = $lock
                ? SeoProjectRunItem::query()->whereKey((int) $runItem->id)->lockForUpdate()->first()
                : $runItem;

            if (! $item instanceof SeoProjectRunItem) {
                return $runItem;
            }

            $item->fill([
                'status' => SeoProjectRunItemStatus::Skipped->value,
                'message' => $message,
                'error_code' => $errorCode?->value,
                'error_message' => null,
                'article_id' => $articleId !== null && $articleId > 0 ? $articleId : $item->article_id,
                'finished_at' => now(),
            ]);
            $item->save();

            return $item->fresh() ?? $item;
        };

        return $lock
            ? DB::connection('omi_seo_ai')->transaction($apply)
            : $apply();
    }

    /**
     * Attach article_id to task + run item after external create. Detect conflicts.
     *
     * @return array{ok: bool, error_code: string|null, message: string|null, article_id: int|null}
     */
    public function bindArticleAfterExternal(
        SeoProjectTask $task,
        SeoProjectRunItem $runItem,
        int $articleId,
        ?int $runId = null,
        bool $created = true,
    ): array {
        if ($articleId <= 0) {
            return [
                'ok' => false,
                'error_code' => ContentProjectErrorCode::ArticleRelationMissing->value,
                'message' => 'Thiếu article_id sau workflow.',
                'article_id' => null,
            ];
        }

        $task->loadMissing('project');
        $siteId = (int) ($task->project?->site_id ?? 0);
        if ($siteId <= 0) {
            $siteId = (int) ($task->site_id ?? 0);
        }
        $localArticleId = LocalArticleAssociationGuard::resolveLocalArticleId(
            $articleId,
            $siteId > 0 ? $siteId : null,
        );
        if ($localArticleId === null) {
            return [
                'ok' => false,
                'error_code' => ContentProjectErrorCode::ArticleRelationMissing->value,
                'message' => 'article_id không phải local articles.id — từ chối bind.',
                'article_id' => null,
            ];
        }
        $articleId = $localArticleId;

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use (
                $task,
                $runItem,
                $articleId,
                $runId,
                $created,
            ): array {
                /** @var SeoProjectTask|null $lockedTask */
                $lockedTask = SeoProjectTask::query()->whereKey((int) $task->id)->lockForUpdate()->first();
                /** @var SeoProjectRunItem|null $lockedItem */
                $lockedItem = SeoProjectRunItem::query()->whereKey((int) $runItem->id)->lockForUpdate()->first();

                if (! $lockedTask instanceof SeoProjectTask || ! $lockedItem instanceof SeoProjectRunItem) {
                    return [
                        'ok' => false,
                        'error_code' => ContentProjectErrorCode::TaskNotFound->value,
                        'message' => 'Task/run item mất khi bind article.',
                        'article_id' => $articleId,
                    ];
                }

                $taskArticleId = (int) ($lockedTask->article_id ?? 0);
                if ($taskArticleId > 0 && $taskArticleId !== $articleId) {
                    return [
                        'ok' => false,
                        'error_code' => ContentProjectErrorCode::ArticleRelationConflict->value,
                        'message' => 'Task đã gắn article khác.',
                        'article_id' => $taskArticleId,
                    ];
                }

                $itemArticleId = (int) ($lockedItem->article_id ?? 0);
                if ($itemArticleId > 0 && $itemArticleId !== $articleId && $taskArticleId <= 0) {
                    // run item có article khác — conflict
                    return [
                        'ok' => false,
                        'error_code' => ContentProjectErrorCode::ArticleRelationConflict->value,
                        'message' => 'Run item đã gắn article khác.',
                        'article_id' => $itemArticleId,
                    ];
                }

                if ($taskArticleId <= 0) {
                    $other = SeoProjectTask::query()
                        ->where('article_id', $articleId)
                        ->whereKeyNot((int) $lockedTask->id)
                        ->lockForUpdate()
                        ->first();

                    if ($other instanceof SeoProjectTask) {
                        return [
                            'ok' => false,
                            'error_code' => ContentProjectErrorCode::ArticleAlreadyLinked->value,
                            'message' => 'Article đã thuộc task khác.',
                            'article_id' => $articleId,
                        ];
                    }

                    $payload = ['article_id' => $articleId];
                    if ($lockedTask->connected_at === null) {
                        $payload['connected_at'] = now();
                    }
                    SeoProjectTask::query()->whereKey((int) $lockedTask->id)->update($payload);
                    $this->safeRecordEvent(
                        $lockedTask,
                        $created ? SeoProjectTaskEventType::ArticleCreated : SeoProjectTaskEventType::ArticleLinked,
                        (string) $lockedTask->status,
                        (string) $lockedTask->status,
                        [
                            'run_item_id' => (int) $lockedItem->id,
                            'action' => (string) $lockedItem->action,
                            'attempt' => (int) $lockedItem->attempt,
                            'article_id' => $articleId,
                        ],
                        $runId,
                    );
                    if ($created) {
                        $this->safeRecordEvent(
                            $lockedTask,
                            SeoProjectTaskEventType::ArticleLinked,
                            (string) $lockedTask->status,
                            (string) $lockedTask->status,
                            [
                                'run_item_id' => (int) $lockedItem->id,
                                'article_id' => $articleId,
                            ],
                            $runId,
                        );
                    }
                }

                $lockedItem->article_id = $articleId;
                $lockedItem->save();

                return [
                    'ok' => true,
                    'error_code' => null,
                    'message' => null,
                    'article_id' => $articleId,
                ];
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueArticleViolation($exception)) {
                return [
                    'ok' => false,
                    'error_code' => ContentProjectErrorCode::ArticleAlreadyLinked->value,
                    'message' => 'Article đã thuộc task khác (unique).',
                    'article_id' => $articleId,
                ];
            }

            throw $exception;
        }
    }

    public function recomputeRunCounters(SeoProjectRun $run, bool $markCompleted = false): SeoProjectRun
    {
        // Chỉ article execution — step/helper không tính counter / không reopen completed.
        $base = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution();

        $total = (int) (clone $base)->count();
        $succeeded = (int) (clone $base)
            ->whereIn('status', [
                SeoProjectRunItemStatus::Success->value,
                SeoProjectRunItemStatus::Skipped->value,
            ])
            ->count();
        $failed = (int) (clone $base)
            ->where('status', SeoProjectRunItemStatus::Failed->value)
            ->count();

        $payload = [
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];

        if ($markCompleted) {
            $payload['status'] = SeoProjectRun::STATUS_COMPLETED;
            $payload['finished_at'] = now();
        }
        // markCompleted=false: chỉ cập nhật counter — KHÔNG ép status=running
        // (trước đây step retry / abandon làm run completed → running → F5 autorun lại).

        $run->update($payload);

        return $run->fresh(['project']) ?? $run;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildLegacyJsonMirror(SeoProjectRun $run): array
    {
        $items = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->orderBy('id')
            ->get();

        $taskIds = $items->pluck('task_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $tasks = SeoProjectTask::query()
            ->whereIn('id', $taskIds)
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($items as $item) {
            if (! $item instanceof SeoProjectRunItem) {
                continue;
            }

            $task = $tasks->get((int) ($item->task_id ?? 0));
            if (! $task instanceof SeoProjectTask) {
                // Task hard-deleted — snapshot-only JSON row
                $rows[] = [
                    'task_id' => (int) ($item->task_id ?? 0),
                    'retry_task_id' => (int) ($item->task_id ?? 0),
                    'action' => (string) $item->action,
                    'type' => (string) ($item->input_snapshot['type'] ?? ''),
                    'source_content' => (string) ($item->input_snapshot['source_content'] ?? ''),
                    'status' => (SeoProjectRunItemStatus::tryFrom((string) $item->status)
                        ?? SeoProjectRunItemStatus::Failed)->toLegacyJsonStatus(),
                    'article_id' => $item->article_id,
                    'message' => (string) ($item->message ?? ''),
                    'attempt' => (int) $item->attempt,
                    'retry_count' => max(0, (int) $item->attempt - 1),
                    'error_code' => $item->error_code,
                    'error_detail' => $item->error_message,
                    'steps' => is_array($item->output_snapshot['steps'] ?? null)
                        ? $item->output_snapshot['steps']
                        : [],
                ];

                continue;
            }

            $rows[] = $this->jsonPresenter->present($item, $task);
        }

        return $rows;
    }

    public function mirrorJsonSafely(SeoProjectRun $run): void
    {
        // Phase 3C3: ngừng ghi full JSON business mirror.
        // Column items giữ historical/rollback; runtime dùng seo_project_run_items.
    }

    public function syncMirrorAndCounters(SeoProjectRun $run, bool $markCompleted = false): SeoProjectRun
    {
        return $this->recomputeRunCounters($run, $markCompleted);
    }

    /**
     * @return array{article_id: int|null, error_code: string|null, message: string|null}
     */
    private function resolveArticleRelation(
        SeoProjectTask $task,
        SeoProjectRunItem $runItem,
        SeoProjectRunAction $action,
    ): array {
        $taskArticleId = (int) ($task->article_id ?? 0);
        $itemArticleId = (int) ($runItem->article_id ?? 0);

        if ($taskArticleId > 0 && $itemArticleId > 0 && $taskArticleId !== $itemArticleId) {
            return [
                'article_id' => null,
                'error_code' => ContentProjectErrorCode::ArticleRelationConflict->value,
                'message' => 'Task và run item trỏ article khác nhau.',
            ];
        }

        if ($taskArticleId > 0) {
            $article = SeoArticle::query()->find($taskArticleId);
            if (! $article instanceof SeoArticle) {
                return [
                    'article_id' => null,
                    'error_code' => ContentProjectErrorCode::ArticleRelationMissing->value,
                    'message' => 'task.article_id trỏ article không tồn tại.',
                ];
            }

            return [
                'article_id' => $taskArticleId,
                'error_code' => null,
                'message' => null,
            ];
        }

        if ($itemArticleId > 0) {
            $article = SeoArticle::query()->find($itemArticleId);
            if (! $article instanceof SeoArticle) {
                return [
                    'article_id' => null,
                    'error_code' => ContentProjectErrorCode::ArticleRelationMissing->value,
                    'message' => 'run_item.article_id trỏ article không tồn tại.',
                ];
            }

            // Heal task.article_id
            try {
                $other = SeoProjectTask::query()
                    ->where('article_id', $itemArticleId)
                    ->whereKeyNot((int) $task->id)
                    ->exists();
                if ($other) {
                    return [
                        'article_id' => null,
                        'error_code' => ContentProjectErrorCode::ArticleAlreadyLinked->value,
                        'message' => 'Article trên run item đã thuộc task khác.',
                    ];
                }

                $payload = ['article_id' => $itemArticleId];
                if ($task->connected_at === null) {
                    $payload['connected_at'] = now();
                }
                SeoProjectTask::query()->whereKey((int) $task->id)->update($payload);
                $task->refresh();
                $this->safeRecordEvent(
                    $task,
                    SeoProjectTaskEventType::ArticleLinked,
                    (string) $task->status,
                    (string) $task->status,
                    [
                        'run_item_id' => (int) $runItem->id,
                        'article_id' => $itemArticleId,
                        'healed' => true,
                    ],
                );
            } catch (QueryException $exception) {
                if ($this->isUniqueArticleViolation($exception)) {
                    return [
                        'article_id' => null,
                        'error_code' => ContentProjectErrorCode::ArticleAlreadyLinked->value,
                        'message' => 'Không heal được article link (unique).',
                    ];
                }

                throw $exception;
            }

            return [
                'article_id' => $itemArticleId,
                'error_code' => null,
                'message' => null,
            ];
        }

        return [
            'article_id' => null,
            'error_code' => null,
            'message' => null,
        ];
    }

    /**
     * @return array{
     *     outcome: 'failed',
     *     run_item: SeoProjectRunItem|null,
     *     task: SeoProjectTask,
     *     error_code: string,
     *     message: string,
     *     article_id: null
     * }
     */
    private function failClaim(
        SeoProjectRun $run,
        SeoProjectTask $task,
        SeoProjectRunAction $action,
        ContentProjectErrorCode $code,
        string $message,
    ): array {
        $runItem = $this->findByLogicalOperation((int) $run->id, (int) $task->id, $action->value);
        if ($runItem instanceof SeoProjectRunItem) {
            $this->markFailed($runItem, $code, $message, lock: false);
        }

        return [
            'outcome' => 'failed',
            'run_item' => $runItem?->fresh(),
            'task' => $task,
            'error_code' => $code->value,
            'message' => $message,
            'article_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function safeRecordEvent(
        SeoProjectTask $task,
        SeoProjectTaskEventType $event,
        ?string $fromStatus,
        ?string $toStatus,
        array $payload = [],
        ?int $runId = null,
    ): void {
        try {
            $this->eventRecorder->record(
                task: $task,
                event: $event,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                payload: $payload,
                runId: $runId,
                createdBy: auth()->id() !== null ? (int) auth()->id() : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('seo.project_task.event_record_failed', [
                'task_id' => (int) $task->id,
                'event' => $event->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isUniqueArticleViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'article_id')
            && (
                str_contains($message, 'Duplicate')
                || str_contains($message, 'UNIQUE')
                || (string) $exception->getCode() === '23000'
            );
    }
}
