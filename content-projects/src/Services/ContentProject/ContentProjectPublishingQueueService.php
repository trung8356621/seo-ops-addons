<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassification;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishOperationKeyFactory;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Omnichannel\Addons\Publishing\Services\Publishing\DispatchClaimResult;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategyResolver;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingProcessingMarkerClearer;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Publishing Queue operations — batch-first, không đụng AI workflow.
 * Eligibility: ContentProjectItemActionGuard (shared with read-model available_actions).
 */
final class ContentProjectPublishingQueueService
{
    public function __construct(
        private readonly ContentProjectPublishTransitionGuard $transitionGuard,
        private readonly ContentProjectItemActionGuard $actionGuard = new ContentProjectItemActionGuard,
        private readonly ?PublishOperationKeyFactory $operationKeys = null,
        private readonly ?PublishingRetryPolicy $retryPolicy = null,
        private readonly ?PublishingActiveProcessing $activeProcessing = null,
        private readonly ?PublishingProcessingMarkerClearer $markerClearer = null,
        private readonly ContentPublishingStrategyResolver $strategyResolver = new ContentPublishingStrategyResolver,
    ) {}

    private function operationKeys(): PublishOperationKeyFactory
    {
        return $this->operationKeys ?? app(PublishOperationKeyFactory::class);
    }

    private function retryPolicy(): PublishingRetryPolicy
    {
        return $this->retryPolicy ?? app(PublishingRetryPolicy::class);
    }

    private function activeProcessing(): PublishingActiveProcessing
    {
        return $this->activeProcessing ?? new PublishingActiveProcessing;
    }

    private function markerClearer(): PublishingProcessingMarkerClearer
    {
        return $this->markerClearer ?? new PublishingProcessingMarkerClearer;
    }

    /**
     * Persist per-item UTC schedule map (Quick Mode / Auto Schedule).
     * Never bulk-set one timestamp for all ids.
     *
     * @param  array<int, Carbon|string>  $itemScheduleMap  task_id => UTC Carbon|ISO
     */
    public function schedulePlan(SeoProject $project, array $itemScheduleMap): int
    {
        $this->assertProjectActive($project);
        if ($itemScheduleMap === []) {
            return 0;
        }

        $ids = $this->normalizeIds(array_keys($itemScheduleMap));
        if ($ids === []) {
            return 0;
        }

        $this->assertTasksCanScheduleOrReschedule($project, $ids);
        $this->ensureInPublishingQueue($project, $ids);

        $scheduled = 0;
        DB::connection('omi_seo_ai')->transaction(function () use ($project, $itemScheduleMap, &$scheduled): void {
            foreach ($itemScheduleMap as $taskId => $at) {
                $id = (int) $taskId;
                if ($id <= 0) {
                    continue;
                }
                $carbon = $at instanceof Carbon
                    ? $at->copy()->utc()
                    : Carbon::parse((string) $at)->utc();

                $scheduled += $this->batchUpdate($project, [$id], $this->scheduleResetAttributes([
                    'scheduled_publish_at' => $carbon,
                    'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
                ]), onlyStatuses: [
                    ContentProjectPublishQueueStatus::None->value,
                    ContentProjectPublishQueueStatus::Waiting->value,
                    ContentProjectPublishQueueStatus::Retrying->value,
                    ContentProjectPublishQueueStatus::Failed->value,
                    ContentProjectPublishQueueStatus::Cancelled->value,
                    ContentProjectPublishQueueStatus::Skipped->value,
                    ContentProjectPublishQueueStatus::Processing->value,
                ]);
            }
        });

        return $scheduled;
    }

    /**
     * Plan schedule time. Always execution none — Publishing chỉ sau runner claim + dispatch.
     * Past/now at vẫn none; runner due query lấy cả none|waiting|retrying. Không gọi WordPress.
     *
     * @param  list<int>  $taskIds
     * @return int affected
     */
    public function schedule(SeoProject $project, array $taskIds, Carbon $at): int
    {
        return (int) ($this->scheduleWithReport($project, $taskIds, $at)['scheduled'] ?? 0);
    }

    /**
     * @param  list<int>  $taskIds
     * @return array{
     *     scheduled: int,
     *     skipped_active: int,
     *     cancelled_pending: int,
     *     failed: int,
     *     skipped_active_ids: list<int>,
     *     cancelled_pending_ids: list<int>
     * }
     */
    public function scheduleWithReport(SeoProject $project, array $taskIds, Carbon $at): array
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        $report = [
            'scheduled' => 0,
            'skipped_active' => 0,
            'cancelled_pending' => 0,
            'failed' => 0,
            'skipped_active_ids' => [],
            'cancelled_pending_ids' => [],
        ];
        if ($ids === []) {
            return $report;
        }

        $utc = $at->copy()->utc();
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->with(['article'])
            ->get();

        foreach ($tasks as $task) {
            $id = (int) $task->getKey();
            try {
                if ($this->strategyResolver->resolve($task, $task->article)->isImmediateUpdate()) {
                    $report['failed']++;
                    continue;
                }

                if ($this->activeProcessing()->isActivelyPublishing($task)) {
                    $report['skipped_active']++;
                    $report['skipped_active_ids'][] = $id;
                    continue;
                }

                if ($this->activeProcessing()->isQueuedAwaitingWorker($task)) {
                    $this->supersedeDeliveryAttempt($task, 'schedule_cancel_pending');
                    $report['cancelled_pending']++;
                    $report['cancelled_pending_ids'][] = $id;
                    $task = $task->fresh() ?? $task;
                }

                // Reschedule allowed for queue rows; Schedule action alone is for unscheduled.
                $status = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
                    ?? ContentProjectPublishQueueStatus::None;
                if ($status === ContentProjectPublishQueueStatus::Published
                    || $task->publish_published_at !== null
                ) {
                    $report['failed']++;
                    continue;
                }

                $updated = $this->batchUpdate($project, [$id], $this->scheduleResetAttributes([
                    'scheduled_publish_at' => $utc,
                    'publish_queue_status' => ContentProjectPublishQueueStatus::Waiting->value,
                ]), onlyStatuses: [
                    ContentProjectPublishQueueStatus::None->value,
                    ContentProjectPublishQueueStatus::Waiting->value,
                    ContentProjectPublishQueueStatus::Retrying->value,
                    ContentProjectPublishQueueStatus::Failed->value,
                    ContentProjectPublishQueueStatus::Cancelled->value,
                    ContentProjectPublishQueueStatus::Skipped->value,
                    ContentProjectPublishQueueStatus::Processing->value,
                    ContentProjectPublishQueueStatus::QueuedForDelivery->value,
                ]);
                if ($updated > 0) {
                    $fresh = SeoProjectTask::query()->whereKey($id)->first();
                    if ($fresh instanceof SeoProjectTask) {
                        $this->markerClearer()->applySideEffects($fresh, 'schedule_plus');
                    }
                    $report['scheduled'] += $updated;
                }
            } catch (\Throwable) {
                $report['failed']++;
            }
        }

        $this->ensureInPublishingQueue($project, $ids);

        return $report;
    }

    /**
     * Module handoff from Content Project — Unscheduled. No WP. No auto schedule.
     *
     * @param  list<int>  $taskIds
     */
    public function acceptHandoff(SeoProject $project, array $taskIds, ?int $actorUserId): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        return $this->batchUpdate($project, $ids, [
            'publishing_queued_at' => now(),
            'publishing_queued_by' => $actorUserId,
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
            'last_publish_error' => null,
        ], onlyStatuses: null, onlyWherePublishingQueued: false);
    }

    /**
     * Rewrite/improve handoff: queue membership + immediate update intent, without fake schedule.
     *
     * @param  list<int>  $taskIds
     */
    public function enqueueImmediateUpdateHandoff(SeoProject $project, array $taskIds, ?int $actorUserId): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        return $this->batchUpdate($project, $ids, $this->markerClearer()->mergeInto([
            'publishing_queued_at' => now(),
            'publishing_queued_by' => $actorUserId,
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Waiting->value,
            'last_publish_error' => null,
        ]), onlyStatuses: null, onlyWherePublishingQueued: false);
    }

    /**
     * Rewrite/improve must fail closed when the source WP post is missing.
     *
     * @param  list<int>  $taskIds
     */
    public function failMissingRemotePostHandoff(SeoProject $project, array $taskIds, ?int $actorUserId): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        return $this->batchUpdate($project, $ids, $this->markerClearer()->mergeInto([
            'publishing_queued_at' => now(),
            'publishing_queued_by' => $actorUserId,
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Failed->value,
            'last_publish_error' => 'Khong tim thay bai WordPress goc de cap nhat.',
            'last_publish_attempt_at' => now(),
        ]), onlyStatuses: null, onlyWherePublishingQueued: false);
    }

    /**
     * Return to Content Project working set before Published.
     *
     * @param  list<int>  $taskIds
     */
    public function returnToContentProject(SeoProject $project, array $taskIds): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        return $this->batchUpdate($project, $ids, [
            'publishing_queued_at' => null,
            'publishing_queued_by' => null,
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
            'last_publish_error' => null,
        ], onlyStatuses: null, onlyWherePublishingQueued: true);
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function unschedule(SeoProject $project, array $taskIds): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $this->assertTasksCan($project, $ids, ContentProjectItemAction::Unschedule);

        return $this->batchUpdate($project, $ids, [
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
            'last_publish_error' => null,
        ], onlyStatuses: [
            ContentProjectPublishQueueStatus::Waiting->value,
            ContentProjectPublishQueueStatus::Retrying->value,
            ContentProjectPublishQueueStatus::None->value,
            ContentProjectPublishQueueStatus::Failed->value,
        ]);
    }

    /**
     * Explicit Publish Now — normalize due time + Waiting, then runner publishes via WP.
     * Past/null scheduled_publish_at must not block this path.
     *
     * @param  list<int>  $taskIds
     */
    public function publishNow(SeoProject $project, array $taskIds): int
    {
        return $this->enqueueExplicitPublish($project, $taskIds, asRetry: false);
    }

    /**
     * Explicit Retry Publish — Failed/Cancelled (and stale due Waiting) become queue-eligible.
     * Past/null scheduled_publish_at must not block; clears stale failure fields.
     *
     * @param  list<int>  $taskIds
     */
    public function retry(SeoProject $project, array $taskIds): int
    {
        return $this->enqueueExplicitPublish($project, $taskIds, asRetry: true);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function enqueueExplicitPublish(SeoProject $project, array $taskIds, bool $asRetry): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $this->assertTasksCan(
            $project,
            $ids,
            $asRetry ? ContentProjectItemAction::RetryPublish : ContentProjectItemAction::PublishNow,
        );

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            return $this->batchUpdate($project, $ids, [
                'scheduled_publish_at' => now(),
            ]);
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->get();

        $affected = 0;
        $now = now();

        foreach ($tasks as $task) {
            $from = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;

            if ($this->activeProcessing()->isActivelyPublishing($task)) {
                continue;
            }

            if ($asRetry) {
                $retryable = in_array($from, [
                    ContentProjectPublishQueueStatus::Failed,
                    ContentProjectPublishQueueStatus::Cancelled,
                    ContentProjectPublishQueueStatus::Waiting,
                    ContentProjectPublishQueueStatus::Retrying,
                    ContentProjectPublishQueueStatus::None,
                    ContentProjectPublishQueueStatus::Published,
                    // Expired/stale processing only reaches here after isActivelyPublishing=false.
                    ContentProjectPublishQueueStatus::Processing,
                ], true);
                if (! $retryable) {
                    continue;
                }

                // Manual / explicit retry = due ngay (Waiting), không để Retrying thiếu next_publish_retry_at.
                $to = ContentProjectPublishQueueStatus::Waiting;
            } else {
                // Published → Waiting = update existing WP post with latest local content.
                // Expired processing → Waiting = immediate requeue.
                $to = ContentProjectPublishQueueStatus::Waiting;
            }

            $this->transitionGuard->assertCanTransition($from, $to);

            $payload = [
                'scheduled_publish_at' => $now,
                'publish_queue_status' => $to->value,
                'last_publish_error' => null,
            ];

            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
                $payload['next_publish_retry_at'] = null;
            }
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'last_publish_error_code')) {
                $payload['last_publish_error_code'] = null;
                $payload['last_publish_error_message'] = null;
                $payload['last_publish_http_status'] = null;
            }

            $payload = $this->markerClearer()->mergeInto($payload);

            if ($asRetry && $from === ContentProjectPublishQueueStatus::Failed) {
                $payload['publish_retry_count'] = (int) ($task->publish_retry_count ?? 0) + 1;
            }

            $updated = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->whereKey((int) $task->getKey())
                ->whereNull('archived_at')
                ->update($payload);

            if ($updated > 0) {
                $task->forceFill(array_merge($task->getAttributes(), $payload));
                $this->markerClearer()->applySideEffects($task, $asRetry ? 'explicit_retry' : 'publish_now');
            }

            $affected += (int) $updated;
        }

        RuntimeLogger::info($asRetry ? 'content_project_publish_retry' : 'content_project_publish_now', [
            'project_id' => (int) $project->getKey(),
            'affected' => $affected,
            'as_retry' => $asRetry,
        ]);

        return $affected;
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function skip(SeoProject $project, array $taskIds): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $this->assertTasksCan($project, $ids, ContentProjectItemAction::SkipPublish);
        $this->assertTransitionForTasks($project, $ids, ContentProjectPublishQueueStatus::Skipped);

        return $this->batchUpdate($project, $ids, [
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Skipped->value,
            'last_publish_error' => null,
        ], onlyStatuses: array_merge(
            ContentProjectPublishQueueStatus::activeValues(),
            [ContentProjectPublishQueueStatus::Failed->value, ContentProjectPublishQueueStatus::Waiting->value],
        ));
    }

    /**
     * Cancel Scheduled/Failed only — không dùng cho Publishing (processing) đang chạy.
     * Stuck processing → recoverStuckPublishing().
     *
     * @param  list<int>  $taskIds
     */
    public function cancelPublish(SeoProject $project, array $taskIds): int
    {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->get();

        foreach ($tasks as $task) {
            if ($this->activeProcessing()->isActivelyPublishing($task)) {
                throw new RuntimeException(
                    'publishing.busy_cannot_reschedule: Bài đang được xuất bản nên không thể đổi lịch.',
                );
            }
        }

        $this->assertTasksCan($project, $ids, ContentProjectItemAction::CancelPublish);
        $this->assertTransitionForTasks($project, $ids, ContentProjectPublishQueueStatus::Cancelled);

        return $this->batchUpdate($project, $ids, $this->markerClearer()->mergeInto([
            'scheduled_publish_at' => null,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Cancelled->value,
            'last_publish_error' => null,
        ]), onlyStatuses: [
            ContentProjectPublishQueueStatus::Waiting->value,
            ContentProjectPublishQueueStatus::Retrying->value,
            ContentProjectPublishQueueStatus::Failed->value,
            ContentProjectPublishQueueStatus::None->value,
        ]);
    }

    /**
     * Recover stuck Publishing without WordPress and without normal Cancel transition.
     *
     * @param  list<int>  $taskIds
     * @param  'scheduled'|'unscheduled'|'failed'  $target
     */
    public function recoverStuckPublishing(
        SeoProject $project,
        array $taskIds,
        string $target,
        ?Carbon $rescheduleAt = null,
    ): int {
        $this->assertProjectActive($project);
        $ids = $this->normalizeIds($taskIds);
        if ($ids === []) {
            return 0;
        }

        $target = strtolower(trim($target));
        if (! in_array($target, ['scheduled', 'unscheduled', 'failed'], true)) {
            throw new RuntimeException('Invalid stuck recovery target.');
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->get();

        $affected = 0;
        foreach ($tasks as $task) {
            $id = (int) $task->getKey();
            if ((string) ($task->publish_queue_status ?? '') !== ContentProjectPublishQueueStatus::Processing->value) {
                continue;
            }

            $payload = match ($target) {
                'scheduled' => $this->markerClearer()->mergeInto([
                    'scheduled_publish_at' => $rescheduleAt ?? (
                        $task->scheduled_publish_at !== null && $task->scheduled_publish_at->gt(now())
                            ? $task->scheduled_publish_at
                            : now()->addMinutes(5)
                    ),
                    'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
                    'last_publish_error' => null,
                    'last_publish_attempt_at' => null,
                ]),
                'unscheduled' => $this->markerClearer()->mergeInto([
                    'scheduled_publish_at' => null,
                    'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
                    'last_publish_error' => null,
                    'last_publish_attempt_at' => null,
                ]),
                default => $this->markerClearer()->mergeInto([
                    'publish_queue_status' => ContentProjectPublishQueueStatus::Failed->value,
                    'last_publish_error' => 'stale_processing: Tiến trình xuất bản đã quá hạn. Hãy khôi phục trạng thái trước.',
                    'last_publish_attempt_at' => now(),
                ]),
            };

            $updated = SeoProjectTask::query()
                ->whereKey((int) $task->getKey())
                ->where('project_id', (int) $project->getKey())
                ->where('publish_queue_status', ContentProjectPublishQueueStatus::Processing->value)
                ->update($payload);

            if ($updated > 0) {
                $task->forceFill(array_merge($task->getAttributes(), $payload));
                $this->markerClearer()->applySideEffects($task, 'recover_stuck_'.$target);
            }

            $affected += (int) $updated;
        }

        RuntimeLogger::info('content_project_publish_stuck_recovered', [
            'project_id' => (int) $project->getKey(),
            'target' => $target,
            'affected' => $affected,
            'task_ids' => $ids,
        ]);

        return $affected;
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function moveTime(SeoProject $project, array $taskIds, Carbon $at): int
    {
        return $this->schedule($project, $taskIds, $at);
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function clearSchedule(SeoProject $project, array $taskIds): int
    {
        return $this->unschedule($project, $taskIds);
    }

    /**
     * Scanner claim → queued_for_delivery (dispatch only).
     * Does NOT create publisher lease and does NOT increment publish_attempt_count.
     * Publisher lease + attempt are owned by beginPublisherAttempt() when WP worker starts.
     *
     * @deprecated Prefer claimForDispatch() structured result.
     */
    public function claimForPublishing(SeoProjectTask $task): ?SeoProjectTask
    {
        $result = $this->claimForDispatch($task);

        return $result->isClaimed() ? $result->task : null;
    }

    /**
     * Atomic claim for downstream delivery dispatch (not active publishing).
     * Always returns an explicit rejection code — never silent null.
     */
    public function claimForDispatch(SeoProjectTask $task): DispatchClaimResult
    {
        $taskId = (int) $task->getKey();

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use ($taskId): DispatchClaimResult {
                /** @var SeoProjectTask|null $locked */
                $locked = SeoProjectTask::query()->whereKey($taskId)->lockForUpdate()->first();
                if (! $locked instanceof SeoProjectTask) {
                    return DispatchClaimResult::rejected(DispatchClaimResult::NOT_FOUND, 'Task không tồn tại.');
                }

                if (! $locked->article instanceof SeoArticle && (int) ($locked->article_id ?? 0) <= 0) {
                    return DispatchClaimResult::rejected(
                        DispatchClaimResult::MISSING_ARTICLE,
                        'Task không có article.',
                        $locked,
                    );
                }

                $status = ContentProjectPublishQueueStatus::tryFrom((string) ($locked->publish_queue_status ?? ''))
                    ?? ContentProjectPublishQueueStatus::None;

                if ($this->activeProcessing()->isActivelyPublishing($locked)) {
                    return DispatchClaimResult::rejected(
                        DispatchClaimResult::ACTIVE_PUBLISH,
                        'Item đang xuất bản (publisher lease còn hiệu lực).',
                        $locked,
                        ['publish_queue_status' => $status->value],
                    );
                }

                if ($this->activeProcessing()->hasStaleProcessingMarkers($locked)) {
                    // Allow reclaim below for stalled/false-active — log category for health.
                    RuntimeLogger::info('publishing.claim_stale_markers', [
                        'task_id' => $taskId,
                        'status' => $status->value,
                    ]);
                }

                if ($status === ContentProjectPublishQueueStatus::QueuedForDelivery
                    && ! $this->activeProcessing()->isDeliveryWorkerStalled($locked)
                ) {
                    return DispatchClaimResult::rejected(
                        DispatchClaimResult::AWAITING_WORKER,
                        'Item đã queued_for_delivery, đang chờ worker.',
                        $locked,
                    );
                }

                $attempts = (int) ($locked->publish_attempt_count ?? 0);
                if ($status === ContentProjectPublishQueueStatus::Retrying
                    && ! $this->retryPolicy()->canRetry($attempts)
                ) {
                    return DispatchClaimResult::rejected(
                        DispatchClaimResult::ATTEMPTS_EXHAUSTED,
                        'Đã hết số lần thử xuất bản.',
                        $locked,
                        ['publish_attempt_count' => $attempts],
                    );
                }

                if (! in_array($status, [
                    ContentProjectPublishQueueStatus::Waiting,
                    ContentProjectPublishQueueStatus::Retrying,
                    ContentProjectPublishQueueStatus::None,
                    ContentProjectPublishQueueStatus::QueuedForDelivery,
                    ContentProjectPublishQueueStatus::Processing, // legacy false-active reclaim
                ], true)) {
                    return DispatchClaimResult::rejected(
                        DispatchClaimResult::INVALID_STATUS,
                        'Status không claim được: '.$status->value,
                        $locked,
                        ['publish_queue_status' => $status->value],
                    );
                }

                $to = ContentProjectPublishQueueStatus::QueuedForDelivery;
                try {
                    $this->transitionGuard->assertCanTransition($status, $to);
                } catch (RuntimeException $e) {
                    return DispatchClaimResult::rejected(
                        DispatchClaimResult::INVALID_STATUS,
                        $e->getMessage(),
                        $locked,
                    );
                }

                $now = now('UTC');
                $attemptToken = (string) \Illuminate\Support\Str::ulid();
                $payload = [
                    'publish_queue_status' => $to->value,
                    'last_publish_attempt_at' => $now,
                ];

                // Clear publisher lease — scanner must not own active publisher lease.
                $payload = $this->markerClearer()->mergeInto($payload, clearPublishingStartedAt: true);

                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'delivery_dispatched_at')) {
                    $payload['delivery_dispatched_at'] = $now;
                }
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publisher_started_at')) {
                    $payload['publisher_started_at'] = null;
                }
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_attempt_token')) {
                    $payload['publish_attempt_token'] = $attemptToken;
                }
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'dispatch_count')) {
                    $payload['dispatch_count'] = (int) ($locked->dispatch_count ?? 0) + 1;
                }
                // Intentionally do NOT increment publish_attempt_count here.
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
                    $payload['next_publish_retry_at'] = null;
                }
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_operation_key')) {
                    $existingKey = trim((string) ($locked->publish_operation_key ?? ''));
                    $payload['publish_operation_key'] = $existingKey !== ''
                        ? $existingKey
                        : $this->operationKeys()->forTask($locked, $locked->article instanceof SeoArticle ? $locked->article : null);
                }

                $locked->forceFill($payload)->saveQuietly();

                RuntimeLogger::info('publishing.dispatched', [
                    'task_id' => $taskId,
                    'dispatch_count' => (int) ($locked->dispatch_count ?? 0),
                    'publisher_attempt_count' => (int) ($locked->publish_attempt_count ?? 0),
                    'attempt_token' => $attemptToken,
                    'operation_key' => (string) ($locked->publish_operation_key ?? ''),
                ]);

                return DispatchClaimResult::claimed($locked->fresh() ?? $locked);
            });
        } catch (\Throwable $e) {
            RuntimeLogger::warning('publishing.claim_dispatch_failed', [
                'task_id' => $taskId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return DispatchClaimResult::rejected(
                DispatchClaimResult::DISPATCH_FAILED,
                $e->getMessage(),
                null,
                ['exception' => $e::class],
            );
        }
    }

    /**
     * Publisher worker start — creates active lease and increments publisher attempt.
     *
     * @return 'started'|'already_active'|'superseded'|'not_found'
     */
    public function beginPublisherAttempt(SeoProjectTask $task, ?string $attemptToken = null): string
    {
        $taskId = (int) $task->getKey();

        return DB::connection('omi_seo_ai')->transaction(function () use ($taskId, $attemptToken): string {
            /** @var SeoProjectTask|null $locked */
            $locked = SeoProjectTask::query()->whereKey($taskId)->lockForUpdate()->first();
            if (! $locked instanceof SeoProjectTask) {
                return 'not_found';
            }

            if ($this->activeProcessing()->isActivelyPublishing($locked)) {
                return 'already_active';
            }

            $currentToken = trim((string) ($locked->publish_attempt_token ?? ''));
            $incoming = trim((string) ($attemptToken ?? ''));
            if ($currentToken !== '' && $incoming !== '' && ! hash_equals($currentToken, $incoming)) {
                RuntimeLogger::info('publishing.attempt_superseded', [
                    'task_id' => $taskId,
                    'expected_token_prefix' => substr($currentToken, 0, 8),
                    'incoming_token_prefix' => substr($incoming, 0, 8),
                ]);

                return 'superseded';
            }

            $from = ContentProjectPublishQueueStatus::tryFrom((string) ($locked->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;
            $this->transitionGuard->assertCanTransition($from, ContentProjectPublishQueueStatus::Processing);

            $now = now('UTC');
            $payload = [
                'publish_queue_status' => ContentProjectPublishQueueStatus::Processing->value,
                'last_publish_attempt_at' => $now,
            ];
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publisher_started_at')) {
                $payload['publisher_started_at'] = $now;
            }
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_started_at')) {
                $payload['publishing_started_at'] = $now;
            }
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_lease_expires_at')) {
                $payload['publish_lease_expires_at'] = $this->retryPolicy()->leaseExpiresAt($now);
            }
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_attempt_count')) {
                $payload['publish_attempt_count'] = (int) ($locked->publish_attempt_count ?? 0) + 1;
            }
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_retry_count')
                && (int) ($locked->publish_attempt_count ?? 0) > 0
            ) {
                $payload['publish_retry_count'] = (int) ($locked->publish_retry_count ?? 0) + 1;
            }
            if ($incoming !== '' && Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_attempt_token')) {
                $payload['publish_attempt_token'] = $incoming;
            } elseif ($currentToken === ''
                && Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_attempt_token')
            ) {
                $payload['publish_attempt_token'] = (string) \Illuminate\Support\Str::ulid();
            }

            $locked->forceFill($payload)->saveQuietly();

            RuntimeLogger::info('publishing.publisher_started', [
                'task_id' => $taskId,
                'attempt' => (int) ($locked->publish_attempt_count ?? 0),
                'lease_expires_at' => $locked->publish_lease_expires_at?->toIso8601String(),
                'attempt_token' => (string) ($locked->publish_attempt_token ?? ''),
            ]);

            return 'started';
        });
    }

    /**
     * Supersede pending delivery so old queued automation jobs become no-ops.
     */
    public function supersedeDeliveryAttempt(SeoProjectTask $task, string $reason = 'supersede'): string
    {
        $newToken = (string) \Illuminate\Support\Str::ulid();
        $payload = $this->markerClearer()->mergeInto([
            'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
        ]);
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_attempt_token')) {
            $payload['publish_attempt_token'] = $newToken;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'delivery_dispatched_at')) {
            $payload['delivery_dispatched_at'] = null;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publisher_started_at')) {
            $payload['publisher_started_at'] = null;
        }

        $task->forceFill($payload)->saveQuietly();
        $this->markerClearer()->applySideEffects($task, $reason);

        RuntimeLogger::info('publishing.attempt_superseded_by_operator', [
            'task_id' => (int) $task->getKey(),
            'reason' => $reason,
            'new_token_prefix' => substr($newToken, 0, 8),
        ]);

        return $newToken;
    }

    public function markProcessing(SeoProjectTask $task): void
    {
        // Legacy callers — start publisher attempt (owns lease + attempt count).
        $outcome = $this->beginPublisherAttempt($task, (string) ($task->publish_attempt_token ?? ''));
        if ($outcome === 'started' || $outcome === 'already_active') {
            $fresh = $task->fresh();
            if ($fresh instanceof SeoProjectTask) {
                $task->forceFill($fresh->getAttributes());
            }
        }
    }

    public function extendPublishLease(SeoProjectTask $task): void
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_lease_expires_at')) {
            return;
        }

        $task->forceFill([
            'publish_lease_expires_at' => $this->retryPolicy()->leaseExpiresAt(),
            'last_publish_attempt_at' => now(),
        ])->saveQuietly();
    }

    public function markPublished(SeoProjectTask $task): void
    {
        $this->markPublishedFromReconcile($task);
    }

    public function markPublishedFromReconcile(SeoProjectTask $task): void
    {
        $payload = $this->markerClearer()->mergeInto([
            'publish_queue_status' => ContentProjectPublishQueueStatus::Published->value,
            'publish_published_at' => $task->publish_published_at ?? now(),
            'scheduled_publish_at' => null,
            'last_publish_error' => null,
        ]);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
            $payload['next_publish_retry_at'] = null;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'last_publish_error_code')) {
            $payload['last_publish_error_code'] = null;
            $payload['last_publish_error_message'] = null;
            $payload['last_publish_http_status'] = null;
        }
        // Do NOT reset publish_published_at if already set; do NOT clear publish_operation_key.

        $task->forceFill($payload)->saveQuietly();
        $this->markerClearer()->applySideEffects($task, 'published');
    }

    public function markFailed(SeoProjectTask $task, string $error): void
    {
        $classification = new PublishFailureClassification(
            retryable: false,
            code: 'publish_failed',
            message: mb_substr(trim($error), 0, 500),
        );
        $this->markFailedFromClassification($task, $classification);
    }

    public function markRetryWait(
        SeoProjectTask $task,
        PublishFailureClassification $classification,
        ?CarbonInterface $nextAt,
    ): void {
        $from = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
            ?? ContentProjectPublishQueueStatus::None;
        // processing → retrying allowed via extended guard.
        $this->transitionGuard->assertCanTransition($from, ContentProjectPublishQueueStatus::Retrying);

        $payload = $this->markerClearer()->mergeInto([
            'publish_queue_status' => ContentProjectPublishQueueStatus::Retrying->value,
            'last_publish_attempt_at' => now(),
            'last_publish_error' => $classification->message,
        ]);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
            $payload['next_publish_retry_at'] = $nextAt;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'last_publish_error_code')) {
            $payload['last_publish_error_code'] = $classification->code;
            $payload['last_publish_error_message'] = $classification->message;
            $payload['last_publish_http_status'] = $classification->httpStatus;
            $payload['last_publish_failed_at'] = now();
        }

        $task->forceFill($payload)->saveQuietly();
        $this->markerClearer()->applySideEffects($task, 'retry_wait');
    }

    public function markFailedFromClassification(
        SeoProjectTask $task,
        PublishFailureClassification $classification,
    ): void {
        $payload = $this->markerClearer()->mergeInto([
            'publish_queue_status' => ContentProjectPublishQueueStatus::Failed->value,
            'last_publish_attempt_at' => now(),
            'last_publish_error' => $classification->message,
        ]);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
            $payload['next_publish_retry_at'] = null;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'last_publish_error_code')) {
            $payload['last_publish_error_code'] = $classification->code;
            $payload['last_publish_error_message'] = $classification->message;
            $payload['last_publish_http_status'] = $classification->httpStatus;
            $payload['last_publish_failed_at'] = now();
        }

        $task->forceFill($payload)->saveQuietly();
        $this->markerClearer()->applySideEffects($task, 'failed');
    }

    private function releasePublishIdempotency(SeoProjectTask $task): void
    {
        $this->markerClearer()->applySideEffects($task, 'idempotency_release');
    }

    private function assertProjectActive(SeoProject $project): void
    {
        if ($project->archived_at !== null || $project->isArchive()) {
            throw new RuntimeException('Project đã Archived — không thao tác Publishing Queue.');
        }
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function assertTasksCan(SeoProject $project, array $taskIds, ContentProjectItemAction $action): void
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $taskIds)
            ->with(['article'])
            ->get();

        foreach ($tasks as $task) {
            $this->actionGuard->assertCan(
                $action,
                $task,
                $task->relationLoaded('article') ? $task->article : null,
            );
        }
    }

    /**
     * Schedule (unscheduled) hoặc reschedule (đã có lịch, chưa processing/published).
     *
     * @param  list<int>  $taskIds
     */
    private function assertTasksCanScheduleOrReschedule(SeoProject $project, array $taskIds): void
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $taskIds)
            ->with(['article'])
            ->get();

        foreach ($tasks as $task) {
            $status = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;

            if ($this->strategyResolver->resolve($task, $task->article)->isImmediateUpdate()) {
                throw new RuntimeException('publishing.update_existing_not_schedulable: Bai viet lai se cap nhat ngay, khong len lich.');
            }

            if ($this->activeProcessing()->isActivelyPublishing($task)) {
                throw new RuntimeException(
                    'publishing.busy_cannot_reschedule: Bài đang được xuất bản nên không thể đổi lịch.',
                );
            }
            if ($status === ContentProjectPublishQueueStatus::Published
                || $task->publish_published_at !== null
            ) {
                throw new RuntimeException('publishing.already_published: Bài đã xuất bản — không đổi lịch.');
            }

            try {
                $this->actionGuard->assertCan(
                    ContentProjectItemAction::Schedule,
                    $task,
                    $task->relationLoaded('article') ? $task->article : null,
                );
            } catch (RuntimeException) {
                // Reschedule path: Waiting/None with schedule — Unschedule allowed ⇒ may reschedule.
                if ($task->scheduled_publish_at === null
                    && ! in_array($status, [
                        ContentProjectPublishQueueStatus::None,
                        ContentProjectPublishQueueStatus::Failed,
                        ContentProjectPublishQueueStatus::Cancelled,
                        ContentProjectPublishQueueStatus::Skipped,
                    ], true)
                ) {
                    throw new RuntimeException(
                        'publishing.not_schedulable: Item không đủ điều kiện lên lịch.',
                    );
                }
                if (in_array($status, [
                    ContentProjectPublishQueueStatus::Waiting,
                    ContentProjectPublishQueueStatus::Retrying,
                    ContentProjectPublishQueueStatus::None,
                    ContentProjectPublishQueueStatus::Failed,
                ], true) || $task->scheduled_publish_at !== null) {
                    continue;
                }
                throw new RuntimeException(
                    'publishing.not_schedulable: Item không đủ điều kiện lên lịch.',
                );
            }
        }
    }

    /**
     * Clear publish error / lease / retry clocks when (re)scheduling.
     * Prevents stale "WordPress has no matching published post" under Scheduled rows.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function scheduleResetAttributes(array $base): array
    {
        $attrs = array_merge($base, [
            'last_publish_error' => null,
        ]);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'last_publish_error_code')) {
            $attrs['last_publish_error_code'] = null;
            $attrs['last_publish_error_message'] = null;
            $attrs['last_publish_http_status'] = null;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'last_publish_failed_at')) {
            $attrs['last_publish_failed_at'] = null;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
            $attrs['next_publish_retry_at'] = null;
        }

        return $this->markerClearer()->mergeInto($attrs);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $onlyStatuses
     */
    private function batchUpdate(
        SeoProject $project,
        array $ids,
        array $attributes,
        ?array $onlyStatuses = null,
        ?bool $onlyWherePublishingQueued = null,
    ): int {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            // Fallback: chỉ stamp schedule.
            $query = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->whereIn('id', $ids);

            return (int) $query->update([
                'scheduled_publish_at' => $attributes['scheduled_publish_at'] ?? null,
            ]);
        }

        $query = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at');

        if ($onlyStatuses !== null) {
            $query->where(function ($q) use ($onlyStatuses): void {
                $q->whereIn('publish_queue_status', $onlyStatuses)
                    ->orWhereNull('publish_queue_status');
            });
        }

        if ($onlyWherePublishingQueued === true
            && Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')
        ) {
            $query->whereNotNull('publishing_queued_at');
        }
        if ($onlyWherePublishingQueued === false
            && Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')
        ) {
            $query->whereNull('publishing_queued_at');
        }

        return (int) $query->update($attributes);
    }

    /**
     * Compat: stamp handoff if legacy schedule/publish rows lack publishing_queued_at.
     *
     * @param  list<int>  $ids
     */
    private function ensureInPublishingQueue(SeoProject $project, array $ids): void
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
            return;
        }

        SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('publishing_queued_at')
            ->whereNull('archived_at')
            ->update([
                'publishing_queued_at' => now(),
            ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertTransitionForTasks(
        SeoProject $project,
        array $ids,
        ContentProjectPublishQueueStatus $to,
    ): void {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->get();

        foreach ($tasks as $task) {
            $from = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;
            $this->transitionGuard->assertCanTransition($from, $to);
        }
    }

    /**
     * @param  list<int|string>  $taskIds
     * @return list<int>
     */
    private function normalizeIds(array $taskIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
