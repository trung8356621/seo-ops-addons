<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassification;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Diagnose / repair invisible dispatch-claimed rows (awaiting_delivery / unprojected).
 */
final class PublishingUnprojectedRepairService
{
    public function __construct(
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly PublishingStuckRecoveryService $stuckRecovery,
        private readonly PublishingActiveProcessing $active = new PublishingActiveProcessing,
        private readonly PublishingRetryPolicy $retryPolicy = new PublishingRetryPolicy,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     by_raw_status: array<string, int>,
     *     by_presenter_state: array<string, int>,
     *     unprojected_ids: list<int>,
     *     classifications: list<array<string, mixed>>,
     *     repaired: list<array<string, mixed>>,
     *     batch_id: string
     * }
     */
    public function diagnoseAndRepair(
        ?int $projectId = null,
        bool $dryRun = true,
        bool $onlyUnprojected = false,
    ): array {
        $batchId = (string) Str::ulid();
        $tasks = $this->queryQueueTasks($projectId);
        $byRaw = [];
        $byPresenter = PublishingQueueStateClassifier::countSummary([]);
        $unprojected = [];
        $classifications = [];
        $repaired = [];

        foreach ($tasks as $task) {
            $row = $this->toProjectionRow($task);
            $classified = PublishingQueueStateClassifier::classify($row);
            $state = $classified['state'];
            $raw = (string) ($task->publish_queue_status ?? 'null');
            $byRaw[$raw] = ($byRaw[$raw] ?? 0) + 1;

            $kind = $this->classifyKind($task, $state);
            $entry = [
                'task_id' => (int) $task->getKey(),
                'project_id' => (int) ($task->project_id ?? 0),
                'raw_status' => $raw,
                'presenter_state' => $state,
                'kind' => $kind,
                'publisher_started_at' => $task->publisher_started_at?->toIso8601String(),
                'delivery_dispatched_at' => $task->delivery_dispatched_at?->toIso8601String(),
                'publish_lease_expires_at' => $task->publish_lease_expires_at?->toIso8601String(),
                'next_publish_retry_at' => $task->next_publish_retry_at?->toIso8601String(),
                'scheduled_publish_at' => $task->scheduled_publish_at?->toIso8601String(),
                'publish_attempt_count' => (int) ($task->publish_attempt_count ?? 0),
                'publish_operation_key' => (string) ($task->publish_operation_key ?? ''),
                'publish_attempt_token' => (string) ($task->publish_attempt_token ?? ''),
            ];
            $classifications[] = $entry;

            if ($state === PublishingQueueStateClassifier::NEEDS_ATTENTION
                || ($onlyUnprojected && $state === PublishingQueueStateClassifier::AWAITING_DELIVERY
                    && in_array($kind, ['awaiting_delivery_stalled', 'stale_dispatch_claim'], true))
                || (! $onlyUnprojected && in_array($kind, [
                    'awaiting_delivery_stalled',
                    'stale_dispatch_claim',
                    'status_operation_mismatch',
                    'unknown',
                ], true))
            ) {
                $unprojected[] = (int) $task->getKey();
            }

            if ($onlyUnprojected && ! in_array($kind, [
                'awaiting_delivery_stalled',
                'stale_dispatch_claim',
                'status_operation_mismatch',
                'unknown',
                'valid_awaiting_delivery',
            ], true) && $state !== PublishingQueueStateClassifier::NEEDS_ATTENTION) {
                continue;
            }

            if ($dryRun) {
                continue;
            }

            $action = $this->repairOne($task, $kind, $batchId);
            if ($action !== null) {
                $repaired[] = array_merge($entry, ['repair' => $action]);
            }
        }

        $presenterCounts = PublishingQueueStateClassifier::countSummary(
            $tasks->map(fn (SeoProjectTask $t): array => $this->toProjectionRow($t))->all(),
        );

        RuntimeLogger::info('publishing.unprojected_repair_report', [
            'batch_id' => $batchId,
            'project_id' => $projectId,
            'dry_run' => $dryRun,
            'total' => $tasks->count(),
            'by_raw_status' => $byRaw,
            'by_presenter_state' => $presenterCounts,
            'unprojected_ids' => $unprojected,
            'repaired_count' => count($repaired),
        ]);

        return [
            'total' => $tasks->count(),
            'by_raw_status' => $byRaw,
            'by_presenter_state' => $presenterCounts,
            'unprojected_ids' => $unprojected,
            'classifications' => $classifications,
            'repaired' => $repaired,
            'batch_id' => $batchId,
        ];
    }

    /**
     * @return Collection<int, SeoProjectTask>
     */
    private function queryQueueTasks(?int $projectId): Collection
    {
        $q = SeoProjectTask::query()
            ->active()
            ->whereNotNull('publishing_queued_at')
            ->whereHas('project', static function ($query): void {
                $query->whereNull('archived_at');
            })
            ->with(['article', 'project'])
            ->orderBy('id');

        if ($projectId !== null && $projectId > 0) {
            $q->where('project_id', $projectId);
        }

        return $q->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function toProjectionRow(SeoProjectTask $task): array
    {
        return [
            'publish_queue_status' => (string) ($task->publish_queue_status ?? ''),
            'scheduled_publish_at' => $task->scheduled_publish_at,
            'scheduled_raw' => $task->scheduled_publish_at?->toIso8601String(),
            'publishing_queued_at' => $task->publishing_queued_at?->toIso8601String(),
            'publisher_started_at' => $task->publisher_started_at,
            'delivery_dispatched_at' => $task->delivery_dispatched_at?->toIso8601String(),
            'publishing_started_at' => $task->publishing_started_at?->toIso8601String(),
            'publish_lease_expires_at' => $task->publish_lease_expires_at?->toIso8601String(),
            'next_publish_retry_at' => $task->next_publish_retry_at?->toIso8601String(),
            'publish_attempt_count' => (int) ($task->publish_attempt_count ?? 0),
            'publish_published_at' => $task->publish_published_at,
        ];
    }

    private function classifyKind(SeoProjectTask $task, string $presenterState): string
    {
        if ($this->active->isActivelyPublishing($task)) {
            return 'active_publisher';
        }
        if ($this->active->isDeliveryWorkerStalled($task)) {
            return 'awaiting_delivery_stalled';
        }
        if ($presenterState === PublishingQueueStateClassifier::AWAITING_DELIVERY
            && $this->active->isQueuedAwaitingWorker($task)
        ) {
            return 'valid_awaiting_delivery';
        }
        if ($this->active->hasStaleProcessingMarkers($task)
            && in_array((string) ($task->publish_queue_status ?? ''), ['waiting', 'retrying', 'none'], true)
        ) {
            return 'stale_dispatch_claim';
        }
        if ($presenterState === PublishingQueueStateClassifier::NEEDS_ATTENTION) {
            return 'unknown';
        }
        if ($this->active->classifyStaleReason($task) === 'status_operation_mismatch') {
            return 'status_operation_mismatch';
        }

        return 'ok';
    }

    private function repairOne(SeoProjectTask $task, string $kind, string $batchId): ?string
    {
        return match ($kind) {
            'valid_awaiting_delivery', 'active_publisher', 'ok' => null,
            'awaiting_delivery_stalled' => $this->stuckRecovery->recoverOne($task, $batchId, dryRun: false),
            'stale_dispatch_claim' => $this->repairStaleClaim($task, $batchId),
            'status_operation_mismatch', 'unknown', 'superseded_attempt' => $this->repairStaleClaim($task, $batchId),
            default => null,
        };
    }

    private function repairStaleClaim(SeoProjectTask $task, string $batchId): string
    {
        $status = (string) ($task->publish_queue_status ?? '');
        $this->queue->supersedeDeliveryAttempt($task, 'stale_dispatch_claim_repair');
        $fresh = $task->fresh() ?? $task;
        $classification = new PublishFailureClassification(
            retryable: true,
            code: 'STALE_DISPATCH_CLAIM',
            message: 'Cleared stale dispatch claim; restored to retry/schedule path.',
        );

        if ($status === ContentProjectPublishQueueStatus::Retrying->value
            || ($fresh->next_publish_retry_at !== null)
        ) {
            $next = $this->retryPolicy->nextRetryAt(max(1, (int) ($fresh->publish_attempt_count ?? 0)));
            try {
                $this->queue->markRetryWait($fresh, $classification, $next ?? now()->addMinutes(5));
            } catch (\Throwable) {
                $fresh->forceFill([
                    'publish_queue_status' => ContentProjectPublishQueueStatus::Retrying->value,
                    'next_publish_retry_at' => $next ?? now()->addMinutes(5),
                    'last_publish_error' => $classification->message,
                ])->saveQuietly();
            }

            RuntimeLogger::info('publishing.stale_claim_repaired_retry_wait', [
                'task_id' => (int) $task->getKey(),
                'batch_id' => $batchId,
            ]);

            return 'retry_wait';
        }

        $fresh->forceFill([
            'publish_queue_status' => ContentProjectPublishQueueStatus::Waiting->value,
            'scheduled_publish_at' => $fresh->scheduled_publish_at ?? now()->addMinutes(5),
            'next_publish_retry_at' => null,
            'last_publish_error' => null,
        ])->saveQuietly();

        RuntimeLogger::info('publishing.stale_claim_repaired_scheduled', [
            'task_id' => (int) $task->getKey(),
            'batch_id' => $batchId,
        ]);

        return 'scheduled';
    }
}
