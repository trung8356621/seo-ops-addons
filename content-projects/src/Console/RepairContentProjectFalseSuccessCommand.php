<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFalseSuccessRepairService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Repair false-success generation rows + stale run ownership for one Content Project.
 * Never calls AI. Dry-run unless --apply.
 */
final class RepairContentProjectFalseSuccessCommand extends Command
{
    protected $signature = 'seo:content-project:repair-project-state
        {projectId : seo_projects.id}
        {--task= : Optional seo_project_tasks.id to scope false-success repair}
        {--fix-empty-success : Repair tasks marked generated/completed without article body}
        {--dry-run : Print plan only (default when --apply is absent)}
        {--apply : Persist repairs}
        {--site= : Optional site_id to bootstrap SEO DB}';

    protected $description = 'Diagnose/repair false-success Content Project items and stale run runtime for one project';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectFalseSuccessRepairService $repair,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $siteId = (int) ($this->option('site') ?? 0);
        if ($siteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($siteId);
        }

        $projectId = (int) $this->argument('projectId');
        if ($projectId <= 0) {
            $this->error('Invalid projectId.');

            return self::FAILURE;
        }

        // Auto-bootstrap site from project when --site omitted.
        if ($siteId <= 0) {
            $diagProbe = $repair->diagnose($projectId);
            $projectSite = (int) ($diagProbe['project']['site_id'] ?? 0);
            if ($projectSite > 0) {
                $databaseConnection->bootstrapSeoDatabaseConnection($projectSite);
            }
        }

        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $fixEmpty = (bool) $this->option('fix-empty-success');
        // Default: inspect empty-success candidates even if flag omitted.
        if (! $this->option('fix-empty-success')) {
            $fixEmpty = true;
        }

        $taskOpt = $this->option('task');
        $onlyTaskId = ($taskOpt !== null && $taskOpt !== '') ? (int) $taskOpt : null;

        $this->line($apply ? '=== APPLY ===' : '=== DRY-RUN ===');
        $report = $repair->repair(
            projectId: $projectId,
            apply: $apply,
            fixEmptySuccess: $fixEmpty,
            onlyTaskId: $onlyTaskId,
        );

        if (! ($report['ok'] ?? false)) {
            $this->error((string) ($report['error'] ?? 'repair_failed'));

            return self::FAILURE;
        }

        $before = is_array($report['before'] ?? null) ? $report['before'] : [];
        $after = is_array($report['after'] ?? null) ? $report['after'] : [];
        $changes = is_array($report['changes'] ?? null) ? $report['changes'] : [];

        $diag = $repair->diagnose($projectId);
        $project = is_array($diag['project'] ?? null) ? $diag['project'] : [];
        $this->info(sprintf(
            'Project #%d %s month=%s site=%s tasks=%d',
            (int) ($project['id'] ?? $projectId),
            (string) ($project['name'] ?? ''),
            (string) ($project['month'] ?? '—'),
            (string) ($project['site_id'] ?? '0'),
            (int) ($diag['task_count'] ?? 0),
        ));

        $this->newLine();
        $this->line('Status counts BEFORE: '.json_encode($before['status_counts'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('False-success BEFORE: '.json_encode($before['false_success_task_ids'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('Status counts AFTER:  '.json_encode($after['status_counts'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('False-success AFTER:  '.json_encode($after['false_success_task_ids'] ?? [], JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->line('--- Tasks ---');
        foreach (($diag['tasks'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->line(sprintf(
                '  #%d status=%s article=%s body=%s latest_ri=%s/%s msg=%s%s',
                (int) ($row['task_id'] ?? 0),
                (string) ($row['task_status'] ?? ''),
                (string) ($row['article_id'] ?? 0),
                ! empty($row['article_has_body']) ? 'yes' : 'no',
                (string) ($row['latest_run_item_id'] ?? '—'),
                (string) ($row['latest_run_item_status'] ?? '—'),
                mb_substr((string) ($row['latest_run_item_error'] ?? $row['latest_run_item_message'] ?? ''), 0, 80),
                ! empty($row['false_success']) ? ' [FALSE_SUCCESS]' : '',
            ));
        }

        $this->newLine();
        $this->line('--- Recent runs ---');
        foreach (($diag['runs'] ?? []) as $run) {
            if (! is_array($run)) {
                continue;
            }
            $this->line(sprintf(
                '  run #%d status=%s finished=%s dispatch=%s recoverable=%s final=%s',
                (int) ($run['run_id'] ?? 0),
                (string) ($run['status'] ?? ''),
                (string) ($run['finished_at'] ?? 'null'),
                $run['active_dispatch'] !== null ? 'yes' : 'no',
                $run['recoverable'] !== null ? 'yes' : 'no',
                (string) ($run['final_status'] ?? '—'),
            ));
        }

        $this->newLine();
        $this->line('Changes: '.count($changes));
        foreach ($changes as $change) {
            $this->line(json_encode($change, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        if (! $apply) {
            $this->info('Dry-run only. Re-run with --fix-empty-success --apply to persist.');
        } else {
            $this->info('Applied. Refresh Ops UI for project #'.$projectId.'.');
        }

        return self::SUCCESS;
    }
}
