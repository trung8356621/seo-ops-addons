<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassifier;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishOperationKeyFactory;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishReconcileResult;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingWordPressReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Auto recover stuck Publishing: reconcile WP first, then retry_wait or failed.
 * Also powers manual Recover now and CLI seo:publishing:reconcile-stuck.
 */
final class PublishingStuckRecoveryService
{
    public function __construct(
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly PublishingWordPressReconciler $reconciler,
        private readonly PublishFailureClassifier $classifier,
        private readonly PublishingRetryPolicy $retryPolicy,
        private readonly PublishOperationKeyFactory $operationKeys,
        private readonly PublishingRecoveryNotifier $notifier,
        private readonly ?PublishingOverdueInlineDeliveryService $overdueInlineDelivery = null,
    ) {}

    /**
     * Called each minute from ContentProjectPublishingQueueRunner.
     *
     * @param  array<string, mixed>  $connectionMeta
     * @return array{scanned: int, published: int, retry_wait: int, failed: int, in_flight: int, batch_id: string}
     */
    public function recoverExpiredLeases(array $connectionMeta = [], ?int $projectId = null, bool $dryRun = false): array
    {
        $batchId = (string) Str::ulid();
        $stats = [
            'scanned' => 0,
            'published' => 0,
            'retry_wait' => 0,
            'failed' => 0,
            'in_flight' => 0,
            'batch_id' => $batchId,
            'dry_run' => $dryRun,
        ];

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            return $stats;
        }

        $stuck = $this->queryStuckTasks($projectId);
        $stats['scanned'] = $stuck->count();
        if ($stuck->isEmpty()) {
            return $stats;
        }

        if (! $dryRun) {
            $this->notifier->notifyStuckDetected($stuck, $batchId);
        }

        $byProject = $stuck->groupBy(static fn (SeoProjectTask $t): int => (int) $t->project_id);

        foreach ($byProject as $pid => $tasks) {
            foreach ($tasks as $task) {
                $outcome = $this->recoverOne($task, $batchId, $dryRun);
                match ($outcome) {
                    'published' => $stats['published']++,
                    'retry_wait' => $stats['retry_wait']++,
                    'failed' => $stats['failed']++,
                    'in_flight' => $stats['in_flight']++,
                    default => null,
                };
            }

            if (! $dryRun) {
                $this->notifier->notifyBatchProgress(
                    (int) $pid,
                    $batchId,
                    published: (int) $stats['published'],
                    retryWait: (int) $stats['retry_wait'],
                    failed: (int) $stats['failed'],
                    total: (int) $stats['scanned'],
                );
            }
        }

        if (! $dryRun && ((int) $stats['failed'] > 0 || (int) $stats['published'] + (int) $stats['retry_wait'] + (int) $stats['failed'] >= (int) $stats['scanned'])) {
            $this->notifier->notifyBatchFinished($stuck, $batchId, $stats);
        }

        RuntimeLogger::info('publishing.stuck_recovery_batch', [
            'batch_id' => $batchId,
            'connection_id' => $connectionMeta['connection_id'] ?? null,
            'project_id' => $projectId,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Manual Recover now — expired lease / stalled delivery; optional force after WP reconcile.
     *
     * @param  list<int>  $taskIds
     * @return array{
     *     affected: int,
     *     published: int,
     *     retry_wait: int,
     *     failed: int,
     *     skipped: int,
     *     skipped_ids: list<int>,
     *     nearest_lease_expires_at: ?string,
     *     batch_id: string
     * }
     */
    public function recoverNow(SeoProject $project, array $taskIds, bool $dryRun = false, bool $force = false): array
    {
        $batchId = (string) Str::ulid();
        $stats = [
            'affected' => 0,
            'published' => 0,
            'retry_wait' => 0,
            'failed' => 0,
            'skipped' => 0,
            'skipped_ids' => [],
            'nearest_lease_expires_at' => null,
            'batch_id' => $batchId,
            'force' => $force,
        ];

        $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($ids === []) {
            return $stats;
        }

        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->with(['article', 'project'])
            ->get();

        if ($tasks->isNotEmpty() && ! $dryRun) {
            $this->notifier->notifyStuckDetected($tasks, $batchId);
        }

        $active = new PublishingActiveProcessing;
        $nearest = null;

        foreach ($tasks as $task) {
            $id = (int) $task->getKey();
            $lease = $task->publish_lease_expires_at;
            if ($lease !== null && ($nearest === null || $lease->lt($nearest))) {
                $nearest = $lease->copy();
            }

            $isActive = $active->isActivelyPublishing($task);
            $isStalled = $active->isDeliveryWorkerStalled($task)
                || $active->isQueuedAwaitingWorker($task);
            $expired = $this->isLeaseExpiredOrLegacyStuck($task);

            if ($isActive && ! $force) {
                $stats['skipped']++;
                $stats['skipped_ids'][] = $id;
                continue;
            }

            if (! $force && ! $expired && ! $isStalled) {
                $stats['skipped']++;
                $stats['skipped_ids'][] = $id;
                continue;
            }

            if ($force && $isActive) {
                // Force: supersede token then recover with WP reconcile.
                $this->queue->supersedeDeliveryAttempt($task, 'force_recover');
                $task = $task->fresh() ?? $task;
            }

            $outcome = $this->recoverOne($task, $batchId, $dryRun);
            if (in_array($outcome, ['published', 'retry_wait', 'failed'], true)) {
                $stats['affected']++;
                $stats[$outcome]++;
            } elseif ($outcome === 'in_flight') {
                $stats['skipped']++;
                $stats['skipped_ids'][] = $id;
            }
        }

        $stats['nearest_lease_expires_at'] = $nearest?->utc()->toIso8601String();

        if (! $dryRun) {
            $this->notifier->notifyBatchFinished($tasks, $batchId, [
                'scanned' => $tasks->count(),
                'published' => $stats['published'],
                'retry_wait' => $stats['retry_wait'],
                'failed' => $stats['failed'],
                'batch_id' => $batchId,
            ]);
        }

        return $stats;
    }

    /**
     * @return 'published'|'retry_wait'|'failed'|'in_flight'|'skipped'
     */
    public function recoverOne(SeoProjectTask $task, string $batchId, bool $dryRun = false): string
    {
        $status = (string) ($task->publish_queue_status ?? '');
        $active = new PublishingActiveProcessing;

        if ($active->isActivelyPublishing($task)) {
            return 'in_flight';
        }

        $isQueuedDelivery = $status === ContentProjectPublishQueueStatus::QueuedForDelivery->value;
        $isProcessing = $status === ContentProjectPublishQueueStatus::Processing->value;

        if (! $isQueuedDelivery && ! $isProcessing) {
            return 'skipped';
        }

        // Recent awaiting delivery (not stalled) — keep visible, do not steal.
        if ($isQueuedDelivery && $active->isQueuedAwaitingWorker($task) && ! $active->isDeliveryWorkerStalled($task)) {
            return 'skipped';
        }

        if ($isProcessing && ! $this->isLeaseExpiredOrLegacyStuck($task) && ! $active->isDeliveryWorkerStalled($task)) {
            return 'skipped';
        }

        RuntimeLogger::info('publishing.reconcile_started', [
            'task_id' => (int) $task->getKey(),
            'batch_id' => $batchId,
            'attempt' => (int) ($task->publish_attempt_count ?? 0),
            'operation_key' => (string) ($task->publish_operation_key ?? ''),
            'status' => $status,
            'publisher_started' => $task->publisher_started_at !== null,
        ]);

        if ($dryRun) {
            return 'retry_wait';
        }

        // Ensure stable operation key exists for legacy stuck rows.
        $this->ensureOperationKey($task);

        // Dispatch-only stall: no WP publisher attempt — supersede token, retry_wait, preserve attempts.
        $publisherStarted = $task->publisher_started_at ?? null;
        if ($isQueuedDelivery || ($isProcessing && ($publisherStarted === null || $publisherStarted === ''))) {
            return $this->recoverStalledDelivery($task, $batchId);
        }

        try {
            $reconcile = $this->reconciler->reconcile($task->fresh(['article']) ?? $task);
        } catch (Throwable $e) {
            $classification = $this->classifier->classify($e, [
                'code' => 'reconcile_exception',
                'message' => $e->getMessage(),
            ]);

            return $this->applyFailure($task, $classification, $batchId);
        }

        if ($reconcile->isInFlight()) {
            $this->queue->extendPublishLease($task);

            return 'in_flight';
        }

        if ($reconcile->isPublished()) {
            $this->finalizePublished($task, $reconcile);

            return 'published';
        }

        $classification = $this->classifier->classify(null, [
            'code' => $active->isDeliveryWorkerStalled($task) || $active->isQueuedAwaitingWorker($task)
                ? 'DELIVERY_WORKER_STALLED'
                : ($reconcile->code !== ''
                    ? $reconcile->code
                    : 'lease_expired'),
            'message' => $active->isQueuedAwaitingWorker($task)
                ? 'Delivery dispatched but WordPress worker did not start (DELIVERY_WORKER_STALLED).'
                : ($reconcile->message !== ''
                    ? $reconcile->message
                    : 'Publish lease expired; WordPress chưa xác nhận published.'),
        ]);

        return $this->applyFailure($task, $classification, $batchId);
    }

    /**
     * Stalled awaiting_delivery — do NOT burn publisher attempt count.
     *
     * @return 'retry_wait'|'failed'
     */
    private function recoverStalledDelivery(SeoProjectTask $task, string $batchId): string
    {
        $attempt = (int) ($task->publish_attempt_count ?? 0);

        $inlineOutcome = $this->overdueInlineDelivery()?->attempt($task, $batchId);
        if ($inlineOutcome === 'published') {
            return 'published';
        }
        if (in_array($inlineOutcome, ['retry_wait', 'failed'], true)) {
            return $inlineOutcome;
        }

        $this->queue->supersedeDeliveryAttempt($task, 'delivery_worker_stalled');
        $fresh = $task->fresh() ?? $task;

        $classification = $this->classifier->classify(null, [
            'code' => 'DELIVERY_WORKER_STALLED',
            'message' => 'Đã claim dispatch nhưng bộ xuất bản không khởi động trong thời gian cho phép.',
        ]);

        if ($this->retryPolicy->canRetry($attempt)) {
            $nextAt = $this->resolveStalledDeliveryRetryAt($fresh, $attempt);
            // Force status path: supersede left None — set retrying without bumping attempts.
            $from = ContentProjectPublishQueueStatus::tryFrom((string) ($fresh->publish_queue_status ?? ''))
                ?? ContentProjectPublishQueueStatus::None;
            try {
                $this->queue->markRetryWait($fresh, $classification, $nextAt);
            } catch (Throwable) {
                // If transition blocked, write retrying directly with clearer.
                $payload = app(PublishingProcessingMarkerClearer::class)->mergeInto([
                    'publish_queue_status' => ContentProjectPublishQueueStatus::Retrying->value,
                    'last_publish_error' => $classification->message,
                    'next_publish_retry_at' => $nextAt,
                    'last_publish_error_code' => $classification->code,
                    'last_publish_error_message' => $classification->message,
                    'last_publish_failed_at' => now(),
                ]);
                // Preserve publish_attempt_count explicitly.
                unset($payload['publish_attempt_count']);
                $fresh->forceFill($payload)->saveQuietly();
                app(PublishingProcessingMarkerClearer::class)->applySideEffects($fresh, 'delivery_worker_stalled');
            }

            RuntimeLogger::info('publishing.delivery_worker_stalled_recovered', [
                'task_id' => (int) $task->getKey(),
                'batch_id' => $batchId,
                'attempt_preserved' => $attempt,
                'next_publish_retry_at' => $nextAt?->toIso8601String(),
            ]);

            return 'retry_wait';
        }

        $this->queue->markFailedFromClassification($fresh, $classification);

        return 'failed';
    }

    private function overdueInlineDelivery(): ?PublishingOverdueInlineDeliveryService
    {
        return $this->overdueInlineDelivery ?? app(PublishingOverdueInlineDeliveryService::class);
    }

    private function resolveStalledDeliveryRetryAt(SeoProjectTask $task, int $attempt): ?\Carbon\CarbonInterface
    {
        $scheduled = $task->scheduled_publish_at;
        if ($scheduled !== null && $scheduled->lte(now('UTC'))) {
            return now('UTC');
        }

        $nextRetry = $task->next_publish_retry_at;
        if ($nextRetry !== null && $nextRetry->lte(now('UTC'))) {
            return now('UTC');
        }

        if ((int) ($task->dispatch_count ?? 0) >= 3) {
            return now('UTC');
        }

        return $this->retryPolicy->nextRetryAt(max(1, $attempt));
    }

    private function finalizePublished(SeoProjectTask $task, PublishReconcileResult $reconcile): void
    {
        $article = $task->article;
        if ($article instanceof SeoArticle && $reconcile->wpPostId !== null && $reconcile->wpPostId > 0) {
            if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
                $article->forceFill(['wp_post_id' => $reconcile->wpPostId])->saveQuietly();
            }
            $permalink = trim((string) ($reconcile->permalink ?? ''));
            if ($permalink !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_permalink'],
                    ['meta_value' => $permalink],
                );
            }
        }

        $this->queue->markPublishedFromReconcile($task->fresh() ?? $task);

        RuntimeLogger::info('publishing.reconcile_found_published', [
            'task_id' => (int) $task->getKey(),
            'wp_post_id' => $reconcile->wpPostId,
            'operation_key' => (string) ($task->publish_operation_key ?? ''),
        ]);
    }

    /**
     * @return 'retry_wait'|'failed'
     */
    private function applyFailure(
        SeoProjectTask $task,
        \Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassification $classification,
        string $batchId,
    ): string {
        $attempt = max(1, (int) ($task->publish_attempt_count ?? 0));

        if ($classification->retryable && $this->retryPolicy->canRetry($attempt)) {
            $nextAt = $this->retryPolicy->nextRetryAt($attempt, $classification->retryAfter);
            $this->queue->markRetryWait($task, $classification, $nextAt);

            RuntimeLogger::info('publishing.retry_scheduled', [
                'task_id' => (int) $task->getKey(),
                'batch_id' => $batchId,
                'attempt' => $attempt,
                'next_publish_retry_at' => $nextAt?->toIso8601String(),
                'error_code' => $classification->code,
            ]);

            return 'retry_wait';
        }

        $this->queue->markFailedFromClassification($task, $classification);

        RuntimeLogger::info('publishing.retry_exhausted', [
            'task_id' => (int) $task->getKey(),
            'batch_id' => $batchId,
            'attempt' => $attempt,
            'error_code' => $classification->code,
        ]);

        return 'failed';
    }

    private function ensureOperationKey(SeoProjectTask $task): void
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_operation_key')) {
            return;
        }
        if (trim((string) ($task->publish_operation_key ?? '')) !== '') {
            return;
        }

        $key = $this->operationKeys->forTask($task, $task->article instanceof SeoArticle ? $task->article : null);
        SeoProjectTask::query()->whereKey((int) $task->getKey())->update([
            'publish_operation_key' => $key,
        ]);
        $task->publish_operation_key = $key;
    }

    /**
     * @return Collection<int, SeoProjectTask>
     */
    private function queryStuckTasks(?int $projectId): Collection
    {
        $query = SeoProjectTask::query()
            ->active()
            ->whereIn('publish_queue_status', [
                ContentProjectPublishQueueStatus::Processing->value,
                ContentProjectPublishQueueStatus::QueuedForDelivery->value,
            ])
            ->whereHas('project', static function ($q): void {
                $q->whereNull('archived_at');
            })
            ->with(['article', 'project'])
            ->orderBy('id')
            ->limit(100);

        if ($projectId !== null && $projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $active = new PublishingActiveProcessing;

        return $query->get()->filter(function (SeoProjectTask $task) use ($active): bool {
            if ($active->isActivelyPublishing($task)) {
                return false;
            }

            return $active->isDeliveryWorkerStalled($task)
                || $active->isQueuedAwaitingWorker($task)
                || $this->isLeaseExpiredOrLegacyStuck($task);
        })->values();
    }

    public function isLeaseExpiredOrLegacyStuck(SeoProjectTask $task): bool
    {
        $active = new PublishingActiveProcessing;
        $status = (string) ($task->publish_queue_status ?? '');

        if ($status === ContentProjectPublishQueueStatus::QueuedForDelivery->value) {
            return $active->isDeliveryWorkerStalled($task) || $active->isQueuedAwaitingWorker($task);
        }

        if ($status !== ContentProjectPublishQueueStatus::Processing->value) {
            return false;
        }

        if ($active->isActivelyPublishing($task)) {
            return false;
        }

        // Dispatch-only processing (no publisher_started_at) after migration.
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publisher_started_at')
            && array_key_exists('publisher_started_at', $task->getAttributes())
            && $task->publisher_started_at === null
        ) {
            return true;
        }

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_lease_expires_at')
            && $task->publish_lease_expires_at !== null
        ) {
            return $task->publish_lease_expires_at->isPast();
        }

        $row = [
            'publish_queue_status' => $status,
            'last_publish_attempt_at' => $task->last_publish_attempt_at,
            'publishing_started_at' => $task->publishing_started_at ?? null,
            'scheduled_publish_at' => $task->scheduled_publish_at,
        ];

        return PublishingQueueStuckPublishingDefinition::matches($row, PublishingRetryPolicy::LEASE_MINUTES)
            || ($task->last_publish_attempt_at === null)
            || $task->last_publish_attempt_at->lte(now()->subMinutes(PublishingRetryPolicy::LEASE_MINUTES));
    }
}
