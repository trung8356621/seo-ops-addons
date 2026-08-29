<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionPackingRepairService;
use Illuminate\Console\Command;

/**
 * Compact fragmented mutable Execution Projects for the same writer + month.
 */
final class RepairExecutionProjectPackingCommand extends Command
{
    protected $signature = 'seo:repair-execution-project-packing
        {--month= : Execution month YYYY-MM or m/Y}
        {--user= : Limit to one writer user id}
        {--dry-run : Report only; no DB writes}';

    protected $description = 'Repack mutable Execution Projects to max-30 chunks per writer/month (reuse first).';

    public function handle(ContentProjectExecutionPackingRepairService $repair): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $month = is_string($this->option('month')) ? trim((string) $this->option('month')) : null;
        if ($month === '') {
            $month = null;
        }
        $userId = (int) ($this->option('user') ?? 0);
        if ($userId <= 0) {
            $userId = null;
        }

        $this->info($dryRun
            ? 'DRY-RUN — planning packing repair…'
            : 'Applying packing repair…');

        $result = $repair->repair($month, $userId, $dryRun);

        foreach ($result['groups'] as $group) {
            if (! ($group['needs_work'] ?? false) && ($group['reason'] ?? '') === 'already_compact') {
                continue;
            }

            $this->line(sprintf(
                '%s / %s',
                (string) ($group['user_name'] ?? '#'.$group['user_id']),
                (string) ($group['month_label'] ?? $group['month']),
            ));
            $this->line('  Before: '.implode(', ', array_map('strval', $group['before'] ?? [])));
            $this->line('  After:  '.implode(', ', array_map('strval', $group['after'] ?? [])));
            if (($group['empty_project_ids'] ?? []) !== []) {
                $this->line('  Empty/remove: #'.implode(', #', $group['empty_project_ids']));
            }
            if (($group['skipped_projects'] ?? []) !== []) {
                foreach ($group['skipped_projects'] as $skipped) {
                    $this->warn(sprintf(
                        '  Skipped #%d (%s): %s',
                        (int) ($skipped['project_id'] ?? 0),
                        (string) ($skipped['name'] ?? ''),
                        (string) ($skipped['reason'] ?? ''),
                    ));
                }
            }
            if (! empty($group['reason']) && $group['reason'] !== 'dry_run') {
                $this->warn('  '.$group['reason']);
            }
            $this->newLine();
        }

        $this->info(sprintf(
            'Done. repaired=%d skipped_compact=%d dry_run=%s',
            (int) $result['repaired'],
            (int) $result['skipped'],
            $dryRun ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
