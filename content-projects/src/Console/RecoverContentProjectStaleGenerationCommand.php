<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class RecoverContentProjectStaleGenerationCommand extends Command
{
    protected $signature = 'seo:content-project:recover-stale-generation
        {--project= : Scope to seo_projects.id}
        {--site= : Optional site_id to bootstrap SEO DB}
        {--apply : Apply recovery (default dry-run)}';

    protected $description = 'Reconcile orphaned Writing/Generating Content Project items into Failed (recoverable).';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectGenerationRecoveryService $recovery,
        \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionStalenessPolicy $staleness,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $siteId = (int) ($this->option('site') ?? 0);
        if ($siteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($siteId);
        }

        $apply = (bool) $this->option('apply');
        $projectId = ($this->option('project') !== null && $this->option('project') !== '')
            ? (int) $this->option('project')
            : null;

        $query = SeoProject::query()->whereNull('archived_at')->orderBy('id');
        if ($projectId !== null && $projectId > 0) {
            $query->whereKey($projectId);
        }

        $this->line($apply ? '=== APPLY ===' : '=== DRY-RUN ===');
        $this->line('timeout_minutes='.$staleness->staleTimeoutMinutes());

        $totalStale = 0;
        $totalRecovered = 0;

        foreach ($query->cursor() as $project) {
            if (! $project instanceof SeoProject) {
                continue;
            }

            if (! $apply) {
                $tasks = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()
                    ->where('project_id', (int) $project->getKey())
                    ->where('status', \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::STATUS_WRITING)
                    ->whereNull('archived_at')
                    ->with(['article'])
                    ->get();
                foreach ($tasks as $task) {
                    $eval = $staleness->evaluateTask($task);
                    if (! ($eval['stale'] ?? false)) {
                        continue;
                    }
                    $totalStale++;
                    $this->line(sprintf(
                        'stale project=%d task=%d last_progress=%s',
                        (int) $project->getKey(),
                        (int) $task->id,
                        (string) ($eval['last_progress_at'] ?? 'null'),
                    ));
                }

                continue;
            }

            $result = $recovery->reconcileProject($project);
            $recovered = count($result['recovered_task_ids']);
            $totalRecovered += $recovered;
            $totalStale += $recovered + count(array_filter(
                $result['details'],
                static fn (array $row): bool => ($row['evaluation']['stale'] ?? false) === true
                    || ($row['reason'] ?? '') === \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionStalenessPolicy::REASON_STALE_RUNTIME,
            ));
            if ($recovered > 0) {
                $this->info(sprintf(
                    'project=%d recovered=%s',
                    (int) $project->getKey(),
                    implode(',', $result['recovered_task_ids']),
                ));
            }
        }

        $this->line('stale_found≈'.$totalStale);
        $this->line('recovered='.$totalRecovered);
        if (! $apply) {
            $this->info('Dry-run only. Re-run with --apply to recover.');
        }

        return self::SUCCESS;
    }
}
