<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAiFailureRepairService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

final class RepairContentProjectAiFailuresCommand extends Command
{
    protected $signature = 'seo:content-project:repair-ai-failures
        {--project= : Required seo_projects.id}
        {--item= : Optional seo_project_tasks.id}
        {--apply : Apply reruns (default dry-run)}
        {--site= : Optional site_id to bootstrap SEO DB}
        {--dry-run : Explicit dry-run (default)}';

    protected $description = 'Dry-run/apply rerun repair for historical transient AI failures in one Content Project';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectAiFailureRepairService $repair,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $siteId = (int) ($this->option('site') ?? 0);
        if ($siteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($siteId);
        }

        $projectId = (int) ($this->option('project') ?? 0);
        if ($projectId <= 0) {
            $this->error('--project=<seo_projects.id> is required (no tenant-wide repair).');

            return self::FAILURE;
        }

        $itemId = ($this->option('item') !== null && $this->option('item') !== '')
            ? (int) $this->option('item')
            : null;
        $apply = (bool) $this->option('apply');

        $report = $repair->scan($projectId, $itemId, $apply);

        $this->line($apply ? '=== APPLY ===' : '=== DRY-RUN ===');
        $this->line('project_id='.(int) $report['project_id'].($itemId !== null ? ' item_id='.$itemId : ''));

        if (! $report['project_found']) {
            foreach ($report['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->line('scanned:             '.(int) $report['scanned']);
        $this->line('retryable_failed:    '.(int) $report['retryable_failed']);
        $this->line('hard_failed_skipped: '.(int) $report['hard_failed_skipped']);
        $this->line('active_skipped:      '.(int) $report['active_skipped']);
        $this->line('published_skipped:   '.(int) $report['published_skipped']);
        $this->line('already_recovered:   '.(int) $report['already_recovered']);
        $this->line('archived_skipped:    '.(int) $report['archived_skipped']);
        $this->line('queued_for_rerun:    '.(int) $report['queued_for_rerun']);

        $retryableRows = array_values(array_filter(
            $report['items'],
            static fn (array $row): bool => ($row['classification'] ?? '') === ContentProjectAiFailureRepairService::CLASS_RETRYABLE,
        ));
        if ($retryableRows !== []) {
            $this->line('Retryable items:');
            foreach (array_slice($retryableRows, 0, 50) as $row) {
                $this->line(sprintf(
                    '  - item=%s run_item=%s task_status=%s exec_status=%s message=%s',
                    $row['task_id'] ?? '?',
                    $row['run_item_id'] ?? 'null',
                    $row['task_status'] ?? '?',
                    $row['exec_status'] ?? 'null',
                    $row['message'] ?? '',
                ));
            }
        }

        foreach ($report['errors'] as $error) {
            $this->warn($error);
        }

        if (! $apply) {
            $this->info('Dry-run only — no writes. Re-run with --apply to queue reruns.');

            return self::SUCCESS;
        }

        if ($report['errors'] !== []) {
            $this->error(sprintf(
                'Apply finished with %d error(s); queued %d of %d eligible item(s).',
                count($report['errors']),
                (int) $report['queued_for_rerun'],
                (int) $report['retryable_failed'],
            ));

            return self::FAILURE;
        }

        $this->info('Apply finished — queued '.(int) $report['queued_for_rerun'].' item(s) for rerun.');

        return self::SUCCESS;
    }
}
