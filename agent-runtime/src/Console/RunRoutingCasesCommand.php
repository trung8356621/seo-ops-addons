<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\AgentRuntime\Testing\RoutingCasesRunner;

final class RunRoutingCasesCommand extends Command
{
    protected $signature = 'agent:routing-cases {--service= : Run one registered service}';
    protected $description = 'Run service-owned Agent routing regression cases without executing tools or Answer Models';

    public function handle(RoutingCasesRunner $runner): int
    {
        $results = $runner->run(is_string($this->option('service')) ? $this->option('service') : null);
        foreach ($results as $result) {
            $this->line(sprintf('%s %s expected=%s/%s/%s actual=%s/%s/%s',
                $result['passed'] ? 'PASS' : 'FAIL', $result['service'].':'.$result['id'],
                $result['expected_module'] ?? '-', $result['expected_operation'] ?? '-', $result['expected_outcome'] ?? '-',
                $result['actual_module'] ?? '-', $result['actual_operation'] ?? '-', $result['actual_outcome'] ?? '-',
            ));
            if (! $result['passed']) $this->line(json_encode($result['semantic'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }
        return array_filter($results, static fn (array $row): bool => ! $row['passed']) === [] ? self::SUCCESS : self::FAILURE;
    }
}
