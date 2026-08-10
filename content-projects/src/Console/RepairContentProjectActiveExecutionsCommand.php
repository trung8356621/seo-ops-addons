<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveExecutionRepairService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class RepairContentProjectActiveExecutionsCommand extends Command
{
    protected $signature = 'seo:content-project:repair-active-executions
        {--apply : Apply safe repairs (default dry-run)}
        {--run= : Scope to seo_project_runs.id}
        {--article= : Scope to article_id}
        {--site= : Optional site_id to bootstrap SEO DB}';

    protected $description = 'Dry-run/apply repair for false-active content-project step executions';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectActiveExecutionRepairService $repair,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();

        $siteId = (int) ($this->option('site') ?? 0);
        if ($siteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($siteId);
        }

        $runId = ($this->option('run') !== null && $this->option('run') !== '')
            ? (int) $this->option('run')
            : null;
        $articleId = ($this->option('article') !== null && $this->option('article') !== '')
            ? (int) $this->option('article')
            : null;
        $apply = (bool) $this->option('apply');

        $report = $repair->inspect($runId, $articleId, $apply);
        $counts = $report['counts'];

        $this->line($apply ? '=== APPLY ===' : '=== DRY-RUN ===');
        $this->line('stale_minutes='.(int) config('seo-content-ai.content_project.run_item_stale_minutes', 30));
        $this->line('False active terminal items: '.(int) $counts['false_active_terminal']);
        $this->line('Orphan running items: '.(int) $counts['orphan_running']);
        $this->line('Inconsistent finished_at: '.(int) $counts['inconsistent_finished_at']);
        $this->line('Inconsistent active flag: '.(int) $counts['inconsistent_active_flag']);
        $this->line('Lock without active execution: '.(int) $counts['lock_without_active']);
        $this->line('Active execution without lock: '.(int) $counts['active_without_lock']);
        $this->line('Repaired: '.(int) $counts['repaired']);

        if ((int) $counts['orphan_running'] > 0) {
            $staleOrphans = array_values(array_filter(
                $report['orphan_running'],
                static fn (array $row): bool => (bool) ($row['officially_stale'] ?? false)
                    || (bool) ($row['upstream_blocked_leftover'] ?? false),
            ));
            $freshOrphans = array_values(array_filter(
                $report['orphan_running'],
                static fn (array $row): bool => ! (bool) ($row['officially_stale'] ?? false)
                    && ! (bool) ($row['upstream_blocked_leftover'] ?? false),
            ));
            $this->warn(sprintf(
                'Orphan running/pending: %d (safe=%d có thể --apply; fresh=%d giữ lại — chờ stale_minutes hoặc kiểm tra worker).',
                (int) $counts['orphan_running'],
                count($staleOrphans),
                count($freshOrphans),
            ));
            foreach (array_slice($report['orphan_running'], 0, 20) as $row) {
                $this->line(sprintf(
                    '  - id=%s run=%s task=%s status=%s stale=%s leftover=%s started_at=%s updated_at=%s action=%s',
                    $row['id'] ?? '?',
                    $row['run_id'] ?? '?',
                    $row['task_id'] ?? '?',
                    $row['status'] ?? '?',
                    ! empty($row['officially_stale']) ? 'yes' : 'no',
                    ! empty($row['upstream_blocked_leftover']) ? 'yes' : 'no',
                    $row['started_at'] ?? 'null',
                    $row['updated_at'] ?? 'null',
                    $row['action'] ?? '?',
                ));
            }
        }

        if (! $apply) {
            $this->info('Dry-run only. Re-run with --apply để sửa safe inconsistencies.');
        }

        return self::SUCCESS;
    }
}
