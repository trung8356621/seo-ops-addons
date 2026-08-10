<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationHealthService;
use Illuminate\Console\Command;

final class AutomationHealthCommand extends Command
{
    protected $signature = 'automation:health {--json : Output JSON only}';

    protected $description = 'Automation health: scheduler heartbeat, backlog, stale jobs, dead letters.';

    public function handle(AutomationHealthService $health): int
    {
        $report = $health->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ($report['healthy'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->info('checked_at: '.($report['checked_at'] ?? ''));
        $this->line('healthy: '.(($report['healthy'] ?? false) ? 'yes' : 'no'));

        $this->newLine();
        $this->comment('Scheduler heartbeats');
        foreach ($report['scheduler'] ?? [] as $name => $row) {
            $this->line(sprintf(
                '  %s last=%s age=%s healthy=%s',
                $name,
                $row['last_beat_at'] ?? 'never',
                isset($row['age_seconds']) ? (string) $row['age_seconds'] : 'n/a',
                ($row['healthy'] ?? false) ? 'yes' : 'no',
            ));
        }

        $this->newLine();
        $this->comment('Backlog');
        foreach ($report['backlog'] ?? [] as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        $this->newLine();
        $this->comment('Stale');
        foreach ($report['stale'] ?? [] as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        $this->newLine();
        $this->comment('Dead letters');
        foreach ($report['dead_letters'] ?? [] as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        return ($report['healthy'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
