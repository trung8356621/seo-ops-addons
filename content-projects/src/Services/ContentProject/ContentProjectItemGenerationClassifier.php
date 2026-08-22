<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Illuminate\Support\Facades\Schema;

/**
 * Generate pending = item chưa có output/execution hợp lệ trên chính item đó.
 * Rewrite/improve: article_id là bài nguồn — không coi là đã generate.
 * Working set only (không lấy item đã bàn giao Publishing Queue).
 */
final class ContentProjectItemGenerationClassifier
{
    public function __construct(
        private readonly ContentProjectLifecycle $lifecycle,
        private readonly ?ContentProjectExecutionStalenessPolicy $staleness = null,
    ) {}

    public function preview(SeoProject $project): ContentProjectGeneratePendingPreview
    {
        $projectId = (int) $project->getKey();
        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->eligibleForGeneration()
            ->inContentProjectWorkingSet()
            ->with(['article'])
            ->orderBy('id')
            ->get();

        $evidenceByTask = $this->loadEvidenceIndex($projectId, $tasks->all());
        $hasHistoricalExecution = $this->projectHasSuccessfulExecution($projectId);

        $decisions = [];
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $decisions[] = $this->classifyTask($task, $evidenceByTask[(int) $task->id] ?? []);
        }

        $runCount = count(array_filter(
            $decisions,
            static fn (ContentProjectItemGenerationDecision $d): bool => $d->shouldRun(),
        ));
        $total = count($decisions);

        $failClosed = $this->shouldFailClosed($hasHistoricalExecution, $total, $runCount, $decisions);
        $failReason = $failClosed
            ? 'fail_closed_would_rerun_entire_project_with_history'
            : '';

        return new ContentProjectGeneratePendingPreview(
            projectId: $projectId,
            totalItems: $total,
            decisions: $decisions,
            hasHistoricalExecution: $hasHistoricalExecution,
            failClosed: $failClosed,
            failClosedReason: $failReason,
        );
    }

    /**
     * Fail-closed chỉ khi selection = cả project + đã có lịch sử execution
     * VÀ không phải recovery hợp lệ (1 item / toàn bộ failed_without_output).
     *
     * @param  list<ContentProjectItemGenerationDecision>  $decisions
     */
    public function shouldFailClosed(
        bool $hasHistoricalExecution,
        int $total,
        int $runCount,
        array $decisions,
    ): bool {
        if (! $hasHistoricalExecution || $total <= 0 || $runCount !== $total) {
            return false;
        }

        // 1 item project: Generate pending / recover đúng 1 item — không phải full-project hazard.
        if ($total === 1) {
            return false;
        }

        // Toàn bộ runnable = failed không output → recovery sau stale/worker chết, cho chạy.
        if ($this->allRunnableHaveReasons($decisions, ['failed_without_output'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<ContentProjectItemGenerationDecision>  $decisions
     * @param  list<string>  $reasons
     */
    private function allRunnableHaveReasons(array $decisions, array $reasons): bool
    {
        $allowed = array_fill_keys($reasons, true);
        $sawRunnable = false;

        foreach ($decisions as $decision) {
            if (! $decision instanceof ContentProjectItemGenerationDecision || ! $decision->shouldRun()) {
                continue;
            }
            $sawRunnable = true;
            if (! isset($allowed[$decision->reason])) {
                return false;
            }
        }

        return $sawRunnable;
    }

    /**
     * Pure snapshot classifier — unit tests không cần DB.
     *
     * @param  array{
     *     task_id: int,
     *     type: string,
     *     status: string,
     *     article_id?: int|null,
     *     keyword?: string|null,
     *     archived_at?: mixed,
     *     publish_queue_status?: string|null,
     *     scheduled_publish_at?: mixed,
     *     publish_published_at?: mixed,
     *     article_status?: string|null,
     *     article_is_approved?: bool,
     *     article_review_status?: string|null,
     *     article_has_body?: bool,
     *     article_manually_edited?: bool,
     *     lifecycle_phase?: string|null,
     *     successful_execution?: bool,
     *     last_run_item_status?: string|null,
     *     generation_meta_complete?: bool,
     *     stale_generation?: bool,
     * }  $snapshot
     */
    public function classifySnapshot(array $snapshot): ContentProjectItemGenerationDecision
    {
        $taskId = (int) ($snapshot['task_id'] ?? 0);
        $type = SeoProjectTask::normalizeType((string) ($snapshot['type'] ?? SeoProjectTask::TYPE_CREATE));
        $status = (string) ($snapshot['status'] ?? SeoProjectTask::STATUS_PENDING);
        $articleId = (int) ($snapshot['article_id'] ?? 0);
        $keyword = isset($snapshot['keyword']) ? (string) $snapshot['keyword'] : null;
        $evidence = [];
        $requiresSourceArticle = in_array($type, SeoProjectTask::typesRequiringExistingArticle(), true);
        $isStaleGeneration = ! empty($snapshot['stale_generation']);

        if (($snapshot['archived_at'] ?? null) !== null || $status === SeoProjectTask::STATUS_ARCHIVED) {
            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'archived', $status, $type, $evidence, $keyword, $articleId);
        }

        if (($snapshot['generation_blocked_at'] ?? null) !== null || ! empty($snapshot['generation_blocked'])) {
            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'generation_blocked', $status, $type, $evidence, $keyword, $articleId);
        }

        if ($status === SeoProjectTask::STATUS_CANCELLED) {
            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'cancelled', $status, $type, $evidence, $keyword, $articleId);
        }

        // Manual edit bảo vệ output CREATE — không áp cho rewrite/improve (article_id = nguồn).
        if (! $requiresSourceArticle && ! empty($snapshot['article_manually_edited'])) {
            $evidence[] = 'manually_edited';

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'manually_edited', $status, $type, $evidence, $keyword, $articleId);
        }

        $phase = (string) ($snapshot['lifecycle_phase'] ?? '');
        if ($phase === ContentProjectLifecyclePhase::Generating->value && $isStaleGeneration) {
            $evidence[] = 'stale_generation';

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_RUN, 'failed_without_output', $status, $type, $evidence, $keyword, $articleId > 0 ? $articleId : null);
        }

        foreach ([
            ContentProjectLifecyclePhase::Review->value => 'lifecycle_review',
            ContentProjectLifecyclePhase::Approved->value => 'lifecycle_approved',
            ContentProjectLifecyclePhase::WaitingPublish->value => 'lifecycle_scheduled',
            ContentProjectLifecyclePhase::Published->value => 'lifecycle_published',
            ContentProjectLifecyclePhase::Generating->value => 'lifecycle_generating',
            ContentProjectLifecyclePhase::Archived->value => 'lifecycle_archived',
        ] as $phaseValue => $reason) {
            if ($phase === $phaseValue) {
                $evidence[] = 'lifecycle:'.$phaseValue;

                return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, $reason, $status, $type, $evidence, $keyword, $articleId);
            }
        }

        if (in_array($status, [
            SeoProjectTask::STATUS_REVIEWING,
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_WRITING,
        ], true)) {
            $evidence[] = 'task_status:'.$status;

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'task_status_'.$status, $status, $type, $evidence, $keyword, $articleId);
        }

        if (! empty($snapshot['successful_execution'])) {
            $evidence[] = 'successful_execution';

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'successful_execution', $status, $type, $evidence, $keyword, $articleId);
        }

        $lastRunStatus = strtolower((string) ($snapshot['last_run_item_status'] ?? ''));
        if (in_array($lastRunStatus, ['success', 'completed'], true)) {
            $evidence[] = 'last_run_item:'.$lastRunStatus;

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'last_run_item_completed', $status, $type, $evidence, $keyword, $articleId);
        }

        if ($lastRunStatus === 'acknowledged_error') {
            $evidence[] = 'last_run_item:acknowledged_error';

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_RUN, 'failed_without_output', $status, $type, $evidence, $keyword, $articleId > 0 ? $articleId : null);
        }

        // Output CREATE (body trên article kết quả). Rewrite/improve giữ article nguồn — không skip.
        if (! $requiresSourceArticle && $articleId > 0 && ! empty($snapshot['article_has_body'])) {
            $evidence[] = 'valid_article_output';

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'valid_article_output', $status, $type, $evidence, $keyword, $articleId);
        }

        if (! $requiresSourceArticle && ! empty($snapshot['generation_meta_complete'])) {
            $evidence[] = 'generation_meta_complete';

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_SKIP, 'generation_meta_complete', $status, $type, $evidence, $keyword, $articleId);
        }

        if (in_array($status, [
            ContentProjectLifecyclePhase::Failed->value,
            SeoProjectTask::STATUS_FAILED,
        ], true) || $status === SeoProjectTask::STATUS_FAILED) {
            // Failed không có evidence → eligible generate pending (khác Retry failed step).
            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_RUN, 'failed_without_output', $status, $type, $evidence, $keyword, $articleId > 0 ? $articleId : null);
        }

        if ($status !== SeoProjectTask::STATUS_PENDING) {
            $evidence[] = 'unexpected_status:'.$status;

            return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_ANOMALY, 'unexpected_status', $status, $type, $evidence, $keyword, $articleId > 0 ? $articleId : null);
        }

        return $this->decision($taskId, ContentProjectItemGenerationDecision::ACTION_RUN, 'never_generated', $status, $type, $evidence, $keyword, null);
    }

    /**
     * @param  array<string, mixed>  $evidenceBag
     */
    public function classifyTask(SeoProjectTask $task, array $evidenceBag = []): ContentProjectItemGenerationDecision
    {
        $article = $task->relationLoaded('article') ? $task->article : null;
        $phase = $this->lifecycle->resolvePhase($task, $article instanceof SeoArticle ? $article : null);

        $body = '';
        if ($article instanceof SeoArticle) {
            $body = trim((string) ($article->body ?? $article->content ?? ''));
        }

        $manuallyEdited = false;
        if ($article instanceof SeoArticle) {
            $manuallyEdited = $article->last_manual_saved_at !== null
                && (
                    $article->last_ai_content_at === null
                    || $article->last_manual_saved_at->greaterThan($article->last_ai_content_at)
                );
        }

        $generationMeta = (bool) ($evidenceBag['generation_meta_complete'] ?? false);
        $requiresSourceArticle = in_array(
            SeoProjectTask::normalizeType((string) ($task->type ?? '')),
            SeoProjectTask::typesRequiringExistingArticle(),
            true,
        );
        if (! $requiresSourceArticle && $article instanceof SeoArticle && $article->last_ai_content_at !== null) {
            $generationMeta = true;
        }

        $staleGeneration = (bool) ($evidenceBag['stale_generation'] ?? false);
        if (
            ! $staleGeneration
            && $phase === ContentProjectLifecyclePhase::Generating
            && $this->staleness instanceof ContentProjectExecutionStalenessPolicy
        ) {
            $staleGeneration = (bool) ($this->staleness->evaluateTask($task)['stale'] ?? false);
        }

        return $this->classifySnapshot([
            'task_id' => (int) $task->id,
            'type' => (string) ($task->type ?? SeoProjectTask::TYPE_CREATE),
            'status' => (string) ($task->status ?? SeoProjectTask::STATUS_PENDING),
            'article_id' => (int) ($task->article_id ?? 0),
            'keyword' => $task->keyword !== null ? (string) $task->keyword : ($task->title !== null ? (string) $task->title : null),
            'archived_at' => $task->archived_at,
            'generation_blocked_at' => $task->isGenerationBlocked() ? ($task->generation_blocked_at ?? true) : null,
            'generation_blocked' => $task->isGenerationBlocked(),
            'lifecycle_phase' => $phase->value,
            'article_has_body' => $body !== '',
            'article_manually_edited' => $manuallyEdited,
            'successful_execution' => (bool) ($evidenceBag['successful_execution'] ?? false),
            'last_run_item_status' => $evidenceBag['last_run_item_status'] ?? null,
            'generation_meta_complete' => $generationMeta,
            'stale_generation' => $staleGeneration,
        ]);
    }

    /**
     * Single-item classify with DB evidence (row smart-action / launch planner).
     */
    public function decisionForTask(SeoProjectTask $task): ContentProjectItemGenerationDecision
    {
        $task->loadMissing('article');
        $index = $this->loadEvidenceIndex((int) ($task->project_id ?? 0), [$task]);

        return $this->classifyTask($task, $index[(int) $task->id] ?? []);
    }

    /**
     * @param  list<SeoProjectTask>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private function loadEvidenceIndex(int $projectId, array $tasks): array
    {
        $taskIds = array_map(static fn (SeoProjectTask $t): int => (int) $t->id, $tasks);
        if ($taskIds === []) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $index */
        $index = [];
        foreach ($taskIds as $id) {
            $index[$id] = [
                'successful_execution' => false,
                'last_run_item_status' => null,
                'generation_meta_complete' => false,
            ];
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
            return $index;
        }

        $rows = SeoProjectRunItem::query()
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get(['id', 'task_id', 'status', 'article_id', 'output_snapshot']);

        foreach ($rows as $row) {
            $tid = (int) $row->task_id;
            if (! isset($index[$tid])) {
                continue;
            }
            $snapshot = is_array($row->output_snapshot) ? $row->output_snapshot : [];
            $wasAcknowledgedError = isset($snapshot['acknowledged_error']);
            if ($index[$tid]['last_run_item_status'] === null) {
                $index[$tid]['last_run_item_status'] = $wasAcknowledgedError
                    ? 'acknowledged_error'
                    : (string) ($row->status ?? '');
            }
            $status = strtolower((string) ($row->status ?? ''));
            if ($wasAcknowledgedError) {
                continue;
            }
            if (in_array($status, [
                SeoProjectRunItemStatus::Success->value,
                'completed',
            ], true)) {
                $index[$tid]['successful_execution'] = true;
            }
        }

        foreach ($tasks as $task) {
            $tid = (int) $task->id;
            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }
            $article = $task->relationLoaded('article') ? $task->article : null;
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $meta = is_array($article->meta ?? null) ? $article->meta : [];
            if (
                (bool) data_get($meta, 'content_project.generation_complete', false)
                || (bool) data_get($meta, 'generation_complete', false)
                || data_get($meta, 'content_project_run_id') !== null
            ) {
                $index[$tid]['generation_meta_complete'] = true;
            }
        }

        return $index;
    }

    private function projectHasSuccessfulExecution(int $projectId): bool
    {
        if ($projectId <= 0) {
            return false;
        }

        $runOk = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->where(function ($q): void {
                $q->where('succeeded', '>', 0)
                    ->orWhere('status', SeoProjectRun::STATUS_COMPLETED);
            })
            ->exists();

        if ($runOk) {
            return true;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
            return false;
        }

        return SeoProjectRunItem::query()
            ->whereIn('run_id', SeoProjectRun::query()->where('project_id', $projectId)->select('id'))
            ->whereIn('status', [SeoProjectRunItemStatus::Success->value, 'completed'])
            ->exists();
    }

    /**
     * @param  list<string>  $evidence
     */
    private function decision(
        int $taskId,
        string $action,
        string $reason,
        string $status,
        string $type,
        array $evidence,
        ?string $keyword,
        ?int $articleId,
    ): ContentProjectItemGenerationDecision {
        return new ContentProjectItemGenerationDecision(
            taskId: $taskId,
            action: $action,
            reason: $reason,
            taskStatus: $status,
            itemType: $type,
            evidence: $evidence,
            keyword: $keyword,
            articleId: $articleId,
        );
    }
}
