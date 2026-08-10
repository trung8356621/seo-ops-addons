<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Publishing\Services\Publishing\DispatchClaimResult;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemOutcome;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemService;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingDueItemSelector;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Publishing Queue cho Content Project — dispatch due tasks qua PublishDueItemService.
 * Assumes caller already bootstrapped+verified SEO DB via SeoDatabaseConnectionService.
 */
final class ContentProjectPublishingQueueRunner
{
    public function __construct(
        private readonly ContentProjectQueueHealthService $health,
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly PublishDueItemService $dueItemService,
        private readonly PublishingDueItemSelector $dueSelector = new PublishingDueItemSelector,
        private readonly PublishingActiveProcessing $activeProcessing = new PublishingActiveProcessing,
    ) {}

    public function health(): ContentProjectQueueHealthService
    {
        return $this->health;
    }

    /**
     * @param  array<string, mixed>  $connectionMeta  safe bootstrap metadata from canonical resolver
     * @return array{
     *     processed: int,
     *     published: int,
     *     failed: int,
     *     skipped: int,
     *     outcomes: list<array<string, mixed>>
     * }
     */
    public function dispatchDue(array $connectionMeta = []): array
    {
        $stats = [
            'processed' => 0,
            'published' => 0, // legacy alias = published_confirmed (kept for callers)
            'published_confirmed' => 0,
            'dispatched' => 0,
            'claimed' => 0,
            'publisher_started' => 0,
            'retry_scheduled' => 0,
            'failed' => 0,
            'skipped' => 0,
            'recovered_published' => 0,
            'recovered_retry' => 0,
            'recovered_failed' => 0,
            'outcomes' => [],
        ];

        $connectionName = $this->databaseConnection->connectionName();

        try {
            if (! Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'scheduled_publish_at')) {
                RuntimeLogger::warning('content_project_publishing_queue_schema_unavailable', [
                    'phase' => 'missing_column',
                    'column' => 'scheduled_publish_at',
                    'connection_name' => $connectionName,
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                ]);

                return $stats;
            }
        } catch (Throwable $e) {
            if ($this->looksLikeConnectionFailure($e)) {
                RuntimeLogger::warning('publishing.connection_bootstrap_failed', [
                    'phase' => 'schema_probe',
                    'connection_name' => $connectionName,
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                    'resolver' => $connectionMeta['resolver'] ?? 'SeoDatabaseConnectionService',
                    'runtime' => $connectionMeta['runtime'] ?? 'console',
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $this->health->rememberBootstrapFailure(
                    $e->getMessage(),
                    (int) ($connectionMeta['connection_id'] ?? 0) ?: null,
                );
            } else {
                RuntimeLogger::warning('content_project_publishing_queue_schema_unavailable', [
                    'phase' => 'schema_probe',
                    'connection_name' => $connectionName,
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'hash_id' => $connectionMeta['hash_id'] ?? null,
                    'database' => $connectionMeta['database'] ?? null,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }

            throw $e;
        }

        $scopedConnectionId = (int) ($connectionMeta['connection_id'] ?? 0) ?: null;

        $this->health->rememberScannerRun($scopedConnectionId);

        $recoveryStats = ['scanned' => 0, 'published' => 0, 'retry_wait' => 0, 'failed' => 0, 'in_flight' => 0];
        try {
            $recoveryStats = app(\Omnichannel\Addons\Publishing\Services\Publishing\PublishingStuckRecoveryService::class)
                ->recoverExpiredLeases($connectionMeta);
            $stats['recovered_published'] = (int) ($recoveryStats['published'] ?? 0);
            $stats['recovered_retry'] = (int) ($recoveryStats['retry_wait'] ?? 0);
            $stats['recovered_failed'] = (int) ($recoveryStats['failed'] ?? 0);
        } catch (Throwable $e) {
            RuntimeLogger::warning('publishing.stuck_recovery_failed', [
                'connection_id' => $scopedConnectionId,
                'error' => $e->getMessage(),
            ]);
        }

        $dueCounts = $this->dueSelector->counts($connectionName);
        $dueTasks = $this->dueSelector->dueTasks($connectionName);

        $dueScheduledIds = [];
        $dueRetryIds = [];
        foreach ($dueTasks as $task) {
            $id = (int) $task->getKey();
            $status = (string) ($task->publish_queue_status ?? '');
            if ($status === 'retrying') {
                $dueRetryIds[] = $id;
            } else {
                $dueScheduledIds[] = $id;
            }
        }

        $claimAttemptedIds = [];
        $claimSuccessIds = [];
        $claimRejectedIds = [];
        /** @var array<int, string> $claimRejectionReasons */
        $claimRejectionReasons = [];
        $publisherInvokedIds = [];
        $dispatchSuccessIds = [];
        $dispatchFailedIds = [];
        /** @var array<string, int> $skipReasonCounts */
        $skipReasonCounts = [];

        RuntimeLogger::info('publishing.due_scan', [
            'connection_id' => $scopedConnectionId,
            'hash_id' => $connectionMeta['hash_id'] ?? null,
            'database' => $connectionMeta['database'] ?? null,
            'queue_connection' => 'sync-command-bus',
            'queue_name' => 'inline',
            'due_scheduled_count' => $dueCounts['due_scheduled_count'],
            'due_retry_count' => $dueCounts['due_retry_count'],
            'due_scheduled_ids' => $dueScheduledIds,
            'due_retry_ids' => $dueRetryIds,
            'now_utc' => $dueCounts['now_utc'],
            'selected_count' => $dueTasks->count(),
        ]);

        $this->health->rememberDueBacklog(
            (int) $dueCounts['due_scheduled_count'],
            (int) $dueCounts['due_retry_count'],
            $scopedConnectionId,
        );

        /** @var list<PublishDueItemOutcome> $outcomes */
        $outcomes = [];

        $dueTasks->each(function (SeoProjectTask $task) use (
            &$stats,
            &$claimAttemptedIds,
            &$claimSuccessIds,
            &$claimRejectedIds,
            &$claimRejectionReasons,
            &$publisherInvokedIds,
            &$dispatchSuccessIds,
            &$dispatchFailedIds,
            &$skipReasonCounts,
            &$outcomes,
            $scopedConnectionId,
        ): void {
            $stats['processed']++;
            $itemId = (int) $task->getKey();
            $claimAttemptedIds[] = $itemId;

            $outcome = $this->dueItemService->execute($itemId, PublishDueItemService::TRIGGER_SCHEDULER);
            $outcomes[] = $outcome;
            $stats['outcomes'][] = $outcome->toLogArray();

            if ($outcome->claimSuccess) {
                $claimSuccessIds[] = $itemId;
                $stats['claimed']++;
            } elseif ($outcome->claimCode !== '') {
                $claimRejectedIds[] = $itemId;
                $claimRejectionReasons[$itemId] = $outcome->claimCode;
                $skipReasonCounts[$outcome->claimCode] = ($skipReasonCounts[$outcome->claimCode] ?? 0) + 1;
            } elseif ($outcome->outcome === PublishDueItemOutcome::SKIPPED) {
                $claimRejectedIds[] = $itemId;
                $reason = $outcome->reason !== '' ? $outcome->reason : 'other';
                $claimRejectionReasons[$itemId] = $reason;
                $skipReasonCounts[$reason] = ($skipReasonCounts[$reason] ?? 0) + 1;
            }

            if ($outcome->publisherInvoked) {
                $publisherInvokedIds[] = $itemId;
                $stats['publisher_started']++;
            }

            match ($outcome->outcome) {
                PublishDueItemOutcome::PUBLISHED => (function () use (&$stats, &$dispatchSuccessIds, $itemId, $scopedConnectionId): void {
                    $stats['published_confirmed']++;
                    $stats['published']++; // legacy alias = confirmed only
                    $dispatchSuccessIds[] = $itemId;
                    $this->health->rememberSuccess(1, $scopedConnectionId);
                    $this->health->rememberPublisherProcessed(1, $scopedConnectionId);
                })(),
                PublishDueItemOutcome::AWAITING_DELIVERY => (function () use (&$stats, &$dispatchSuccessIds, $itemId): void {
                    $stats['dispatched']++;
                    $dispatchSuccessIds[] = $itemId;
                    // Health success = confirmed publish only — not dispatch acceptance.
                })(),
                PublishDueItemOutcome::RETRY_WAIT => (function () use (&$stats, &$dispatchSuccessIds, $itemId): void {
                    $stats['retry_scheduled']++;
                    $dispatchSuccessIds[] = $itemId;
                })(),
                PublishDueItemOutcome::FAILED => (function () use (&$stats, &$dispatchFailedIds, $itemId, $outcome, $scopedConnectionId): void {
                    $stats['failed']++;
                    $dispatchFailedIds[] = $itemId;
                    $this->health->rememberFailure($outcome->reason !== '' ? $outcome->reason : 'failed', $scopedConnectionId);
                })(),
                PublishDueItemOutcome::SKIPPED => (function () use (&$stats): void {
                    $stats['skipped']++;
                })(),
                default => (function () use (&$stats, &$dispatchFailedIds, $itemId, $outcome, $scopedConnectionId): void {
                    $stats['failed']++;
                    $dispatchFailedIds[] = $itemId;
                    $this->health->rememberFailure(
                        $outcome->exceptionMessage ?? $outcome->reason ?: 'error',
                        $scopedConnectionId,
                    );
                })(),
            };
        });

        $dominantRejection = $this->dominantReason($skipReasonCounts);
        $completePayload = [
            'connection_id' => $scopedConnectionId,
            'due_scheduled_ids' => $dueScheduledIds,
            'due_retry_ids' => $dueRetryIds,
            'claim_attempted_ids' => $claimAttemptedIds,
            'claim_success_ids' => $claimSuccessIds,
            'claim_rejected_ids' => $claimRejectedIds,
            'claim_rejection_reason' => $claimRejectionReasons,
            'publisher_invoked_ids' => $publisherInvokedIds,
            'dispatch_success_ids' => $dispatchSuccessIds,
            'dispatch_failed_ids' => $dispatchFailedIds,
            'claimed_count' => count($claimSuccessIds),
            'dispatched_count' => (int) $stats['dispatched'],
            'publisher_started_count' => (int) $stats['publisher_started'],
            'published_confirmed_count' => (int) $stats['published_confirmed'],
            'retry_wait_count' => (int) $stats['retry_scheduled'],
            'failed_count' => (int) $stats['failed'],
            'skipped_count' => (int) $stats['skipped'],
            'published' => (int) $stats['published_confirmed'], // legacy: confirmed only
            'failed' => $stats['failed'],
            'due_scheduled_count' => $dueCounts['due_scheduled_count'],
            'due_retry_count' => $dueCounts['due_retry_count'],
            'skip_reason_counts' => $skipReasonCounts,
            'dominant_rejection_reason' => $dominantRejection,
        ];

        RuntimeLogger::info('publishing.due_scan_complete', $completePayload);

        $dueTotal = (int) $dueCounts['due_scheduled_count'] + (int) $dueCounts['due_retry_count'];
        $progressCount = count($claimSuccessIds) + count($publisherInvokedIds);
        if ($dueTotal > 0 && $progressCount === 0) {
            RuntimeLogger::warning('publishing.due_scan_no_progress', [
                'connection_id' => $scopedConnectionId,
                'due_total' => $dueTotal,
                'dominant_rejection_reason' => $dominantRejection,
                'skip_reason_counts' => $skipReasonCounts,
            ]);
            $this->health->rememberScanNoProgress(
                $dueTotal,
                $dominantRejection,
                $skipReasonCounts,
                $scopedConnectionId,
            );
        }

        if (count($claimSuccessIds) > 0 || (int) $stats['published'] > 0) {
            $this->health->rememberSuccess(
                max(count($claimSuccessIds), (int) $stats['published']),
                $scopedConnectionId,
            );
        }

        $this->health->rememberWorkerRun($scopedConnectionId);

        return $stats;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function dominantReason(array $counts): ?string
    {
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $key = array_key_first($counts);

        return is_string($key) ? $key : null;
    }

    private function looksLikeConnectionFailure(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'access denied')
            || str_contains($message, '1045')
            || str_contains($message, '2002')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'unknown database')
            || str_contains($message, 'không kết nối được');
    }
}
