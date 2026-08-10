<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingDueItemSelector;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingStaleStateRepairer;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Support\RuntimeLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Safe repair: detect stale claims + requeue overdue scheduled/retry_wait via CommandBus.
 * Does not reset attempt counters. Does not touch active non-expired processing.
 */
final class RequeueOverduePublishingCommand extends Command
{
    protected $signature = 'seo:publishing:requeue-overdue
        {--dry-run : Report only}
        {--limit=50 : Max items to inspect/requeue}
        {--project= : Optional seo_projects.id}
        {--repair-stale-only : Only repair stale markers, skip due requeue}
        {--force-selected= : Comma-separated task IDs for confirmed force recover after WP reconcile}';

    protected $description = 'Detect/repair stale publish markers and requeue overdue Publishing Queue items.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        PublishingDueItemSelector $dueSelector,
        PublishingStaleStateRepairer $staleRepairer,
        ContentProjectCommandBus $commandBus,
        \Omnichannel\Addons\Publishing\Services\Publishing\PublishingStuckRecoveryService $stuckRecovery,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $projectId = ($this->option('project') !== null && $this->option('project') !== '')
            ? (int) $this->option('project')
            : null;
        $repairStaleOnly = (bool) $this->option('repair-stale-only');
        $forceSelected = trim((string) ($this->option('force-selected') ?? ''));

        $this->line($dryRun ? '=== DRY-RUN ===' : '=== APPLY ===');

        if ($forceSelected !== '') {
            $forceIds = array_values(array_filter(array_map('intval', explode(',', $forceSelected))));
            $this->line('--- force-selected recover ---');
            if ($forceIds === []) {
                $this->error('Invalid --force-selected');

                return self::FAILURE;
            }
            $byProject = SeoProjectTask::query()
                ->whereIn('id', $forceIds)
                ->get()
                ->groupBy(static fn (SeoProjectTask $t): int => (int) $t->project_id);
            foreach ($byProject as $pid => $tasks) {
                $project = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->find((int) $pid);
                if (! $project) {
                    continue;
                }
                $stats = $stuckRecovery->recoverNow(
                    $project,
                    $tasks->map(static fn (SeoProjectTask $t): int => (int) $t->getKey())->all(),
                    dryRun: $dryRun,
                    force: true,
                );
                $this->line(sprintf(
                    'project=%d recovered=%d skipped_active=%d skipped_ids=%s nearest_lease=%s',
                    (int) $pid,
                    (int) $stats['affected'],
                    (int) $stats['skipped'],
                    implode(',', $stats['skipped_ids'] ?? []),
                    (string) ($stats['nearest_lease_expires_at'] ?? 'null'),
                ));
            }
            $this->info('Done (force-selected).');

            return self::SUCCESS;
        }

        $stale = $staleRepairer->repair($projectId, $limit, $dryRun);
        $this->line('--- stale marker report ---');
        $byReason = [];
        foreach ($stale['rows'] as $row) {
            $reason = (string) ($row['reason'] ?? 'unknown');
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            $this->line(sprintf(
                'item=%d reason=%s status=%s repairable=%s action=%s attempts=%d next_retry=%s lease=%s',
                (int) $row['item_id'],
                $reason,
                (string) ($row['publish_queue_status'] ?? ''),
                ! empty($row['repairable']) ? 'yes' : 'no',
                (string) ($row['action'] ?? ''),
                (int) ($row['publish_attempt_count'] ?? 0),
                (string) ($row['next_publish_retry_at'] ?? 'null'),
                (string) ($row['publish_lease_expires_at'] ?? 'null'),
            ));
        }
        foreach ([
            'scheduled_with_stale_claim',
            'retry_wait_with_stale_claim',
            'expired_processing',
            'expired_publisher_lease',
            'queued_awaiting_worker',
            'queued_worker_stalled',
            'active_real_publisher',
            'active_non_expired_processing',
            'status_operation_mismatch',
            'superseded_attempt',
        ] as $reason) {
            $this->line(sprintf('reason_count.%s=%d', $reason, (int) ($byReason[$reason] ?? 0)));
        }
        $this->line(sprintf(
            'stale_summary repaired=%d skipped_active=%d',
            (int) $stale['repaired'],
            (int) $stale['skipped_active'],
        ));

        if ($repairStaleOnly) {
            $this->info('Done (repair-stale-only).');

            return self::SUCCESS;
        }

        $counts = $dueSelector->counts();
        $this->line('--- due requeue ---');
        $this->line('now_utc='.$counts['now_utc']);
        $this->line('due_scheduled='.$counts['due_scheduled_count'].' due_retry='.$counts['due_retry_count']);

        $tasks = $dueSelector->dueTasks(limit: $limit);
        if ($projectId !== null && $projectId > 0) {
            $tasks = $tasks->filter(
                static fn (SeoProjectTask $t): bool => (int) ($t->project_id ?? 0) === $projectId,
            )->values();
        }

        $requeued = 0;
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $this->line(sprintf(
                'task=%d status=%s scheduled=%s next_retry=%s attempts=%d',
                (int) $task->getKey(),
                (string) ($task->publish_queue_status ?? ''),
                $task->scheduled_publish_at?->utc()->toIso8601String() ?? 'null',
                $task->next_publish_retry_at?->utc()->toIso8601String() ?? 'null',
                (int) ($task->publish_attempt_count ?? 0),
            ));

            if ($dryRun) {
                $requeued++;
                continue;
            }

            // Clear expired lease only — never wipe attempt counters.
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_lease_expires_at')
                && (string) ($task->publish_queue_status ?? '') === ContentProjectPublishQueueStatus::Processing->value
                && ($task->publish_lease_expires_at === null || $task->publish_lease_expires_at->lte(now('UTC')))
            ) {
                $task->forceFill([
                    'publish_lease_expires_at' => null,
                    'publish_queue_status' => ContentProjectPublishQueueStatus::None->value,
                ])->saveQuietly();
            }

            $actor = new ActorContext(
                actorType: 'queue',
                actorId: null,
                siteId: (int) ($task->site_id ?? $task->project?->site_id ?? 0) ?: null,
                idempotencyKey: 'requeue-overdue-'.(int) $task->getKey().'-'.now('UTC')->format('YmdHi'),
                correlationId: 'requeue-overdue-'.(int) $task->getKey(),
            );

            $result = $commandBus->dispatch(
                new ProcessScheduledProjectItemPublishCommand(
                    itemRef: (int) $task->getKey(),
                    projectRef: (int) ($task->project_id ?? 0) ?: null,
                ),
                $actor,
            );

            RuntimeLogger::info('publishing.requeue_overdue_item', [
                'task_id' => (int) $task->getKey(),
                'success' => $result->success,
                'code' => $result->code,
                'message' => $result->message,
            ]);
            $requeued++;
        }

        $this->info('Done. stale_repaired='.$stale['repaired'].' due_items='.$requeued);

        return self::SUCCESS;
    }
}
