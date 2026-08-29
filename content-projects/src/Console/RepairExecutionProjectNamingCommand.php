<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionNamingRepairService;
use Illuminate\Console\Command;

/**
 * Normalize Execution Project display names per writer + month (not global month).
 */
final class RepairExecutionProjectNamingCommand extends Command
{
    protected $signature = 'seo:repair-execution-project-naming
        {--month= : Execution month YYYY-MM or m/Y}
        {--user= : Limit to one writer user id}
        {--dry-run : Report only; no DB writes}';

    protected $description = 'Rename mutable Execution Projects so suffixes are scoped per writer+month.';

    public function handle(ContentProjectExecutionNamingRepairService $repair): int
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
            ? 'DRY-RUN — planning naming repair…'
            : 'Applying naming repair…');

        $result = $repair->repair($month, $userId, $dryRun);

        foreach ($result['groups'] as $group) {
            if (! ($group['needs_work'] ?? false)) {
                continue;
            }

            $this->line(sprintf(
                '%s / %s',
                (string) ($group['user_name'] ?? '#'.$group['user_id']),
                (string) ($group['month_label'] ?? $group['month']),
            ));
            $this->line('  Before: '.implode(', ', $group['before'] ?? []));
            $this->line('  After:  '.implode(', ', $group['after'] ?? []));
            foreach ($group['renames'] ?? [] as $rename) {
                $this->line(sprintf(
                    '  #%d: %s → %s',
                    (int) ($rename['project_id'] ?? 0),
                    (string) ($rename['from'] ?? ''),
                    (string) ($rename['to'] ?? ''),
                ));
            }
            if (! empty($group['reason']) && $group['reason'] !== 'dry_run') {
                $this->warn('  '.$group['reason']);
            }
            $this->newLine();
        }

        $this->info(sprintf(
            'Done. repaired=%d skipped_ok=%d dry_run=%s',
            (int) $result['repaired'],
            (int) $result['skipped'],
            $dryRun ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
