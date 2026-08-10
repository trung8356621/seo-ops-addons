<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentCapabilityCoverageAuditService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class AgentCapabilitiesAuditCommand extends Command
{
    protected $signature = 'agent:capabilities:audit
        {--module= : Filter by module}
        {--only-missing : Only missing/partial rows}
        {--json : Print JSON}
        {--fail-on-critical : Non-zero exit when critical gaps}
        {--sync : Write JSON report to storage}';

    protected $description = 'Audit Agent Workspace capability/skill coverage against v1 inventory.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentCapabilityCoverageAuditService $audit,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $report = $audit->audit(
            module: $this->option('module') !== null && $this->option('module') !== ''
                ? (string) $this->option('module')
                : null,
            onlyMissing: (bool) $this->option('only-missing'),
        );

        $summary = $report['summary'];
        $this->info(sprintf(
            'modules=%d features=%d complete=%d partial=%d missing=%d internal=%d deprecated=%d critical_gaps=%d',
            $summary['modules'],
            $summary['features'],
            $summary['complete'],
            $summary['partial'],
            $summary['missing'],
            $summary['internal'],
            $summary['deprecated'],
            $summary['critical_gaps'],
        ));

        if ($this->option('sync')) {
            $path = $audit->writeJson($report);
            $this->line('wrote='.$path);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        if ($this->option('fail-on-critical') && (int) $summary['critical_gaps'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
