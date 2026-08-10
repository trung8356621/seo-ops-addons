<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Console;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRunStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionStalenessPolicy;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\GenerationStuckNotificationPublisher;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\RunnerHealthNotificationPublisher;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\SiteSyncIncidentNotificationPublisher;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\User;
use Illuminate\Console\Command;

final class ReconcileActiveOperationalNotificationsCommand extends Command
{
    protected $signature = 'seo:notifications:reconcile-active-incidents
        {--dry-run : Report only, do not write notifications}
        {--connection= : Optional SEO connection id}
        {--tenant= : Optional tenant owner user id}';

    protected $description = 'Backfill Notification Center for currently active operational incidents only.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        RunnerHealthNotificationPublisher $runnerHealth,
        GenerationStuckNotificationPublisher $generationStuck,
        SiteSyncIncidentNotificationPublisher $siteSync,
        ContentProjectExecutionStalenessPolicy $staleness,
        OperationalNotificationRecipientResolver $recipients,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $dryRun = (bool) $this->option('dry-run');
        $tenantOpt = $this->option('tenant');
        $tenantId = $tenantOpt !== null && $tenantOpt !== '' ? (int) $tenantOpt : null;

        $this->line($dryRun ? '=== DRY-RUN ===' : '=== APPLY ===');

        $tenants = $this->resolveTenants($tenantId);
        $actions = 0;

        foreach ($tenants as $tenantOwnerId) {
            if (! $dryRun) {
                $results = $runnerHealth->checkAll($tenantOwnerId);
                $actions += count($results);
                $this->line(sprintf('tenant=%d runners=%d', $tenantOwnerId, count($results)));
            } else {
                $this->line(sprintf('tenant=%d would_check_runners=4', $tenantOwnerId));
                $actions += 4;
            }
        }

        $staleProjects = 0;
        foreach (SeoProject::query()->whereNull('archived_at')->orderBy('id')->cursor() as $project) {
            if (! $project instanceof SeoProject) {
                continue;
            }
            $projectTenant = $recipients->tenantOwnerIdForProject($project);
            if ($tenantId !== null && $projectTenant !== $tenantId) {
                continue;
            }

            $staleIds = [];
            $tasks = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->where('status', SeoProjectTask::STATUS_WRITING)
                ->whereNull('archived_at')
                ->with(['article'])
                ->get();

            foreach ($tasks as $task) {
                $evaluation = $staleness->evaluateTask($task);
                if (($evaluation['stale'] ?? false) === true) {
                    $staleIds[] = (int) $task->id;
                }
            }

            if ($staleIds === []) {
                continue;
            }

            $staleProjects++;
            $batchId = 'reconcile-'.now()->format('YmdHi').'-'.$project->getKey();
            if ($dryRun) {
                $this->line(sprintf('project=%d stale_tasks=%d', (int) $project->getKey(), count($staleIds)));
            } else {
                $generationStuck->notifyRecoveryBatch($project, $batchId, $staleIds, []);
            }
            $actions++;
        }

        $stuckRuns = 0;
        try {
            $runs = SeoSiteSyncRun::query()
                ->whereIn('status', ['running', 'pending'])
                ->where('updated_at', '<', now()->subMinutes(5))
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($runs as $run) {
                if (! $run instanceof SeoSiteSyncRun) {
                    continue;
                }
                $step = SeoSiteSyncRunStep::query()
                    ->where('run_id', (int) $run->getKey())
                    ->whereIn('status', ['running', 'waiting', 'pending'])
                    ->orderByDesc('id')
                    ->first();
                $stepName = (string) ($step?->step_key ?? $run->current_step ?? 'unknown');
                $tenantOwnerId = $tenantId ?? (int) ($tenants[0] ?? 0);
                if ($tenantOwnerId <= 0) {
                    continue;
                }
                $counters = is_array($run->counters) ? $run->counters : [];
                $hasProgress = (int) ($counters['total_to_check'] ?? 0) > 0
                    || (int) ($counters['checked'] ?? 0) > 0
                    || (int) ($counters['fetched'] ?? 0) > 0
                    || (int) ($counters['created'] ?? 0) > 0
                    || (int) ($counters['updated'] ?? 0) > 0
                    || (int) ($counters['unchanged'] ?? 0) > 0
                    || (int) ($counters['failed'] ?? 0) > 0;
                $stuckRuns++;
                if ($dryRun) {
                    $this->line(sprintf('site_sync_run=%d step=%s', (int) $run->getKey(), $stepName));
                } else {
                    if (! $hasProgress) {
                        $meta = is_array($run->meta) ? $run->meta : [];
                        $meta['watchdog_failed_at'] = now()->toIso8601String();
                        $meta['watchdog_reason'] = 'No progress for 5 minutes: total=0 and counters unchanged.';
                        $run->forceFill([
                            'status' => 'failed',
                            'resumable' => true,
                            'error_message' => 'Site Sync stuck: no progress for 5 minutes while total remained 0.',
                            'meta' => $meta,
                        ])->save();
                    }
                    $siteSync->notifyStuck($run, $tenantOwnerId, $stepName);
                }
                $actions++;
            }
        } catch (\Throwable $e) {
            $this->warn('site_sync reconcile skipped: '.$e->getMessage());
        }

        $this->info(sprintf(
            'Done. actions=%d stale_projects=%d stuck_site_sync=%d',
            $actions,
            $staleProjects,
            $stuckRuns,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveTenants(?int $tenantId): array
    {
        if ($tenantId !== null && $tenantId > 0) {
            return [$tenantId];
        }

        return User::query()
            ->where('status', User::STATUS_NORMAL)
            ->where('seo_role', User::SEO_ROLE_MANAGER)
            ->where(function ($query): void {
                $query->whereNull('parent_id')->orWhere('parent_id', 0);
            })
            ->orderBy('id')
            ->limit(50)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
