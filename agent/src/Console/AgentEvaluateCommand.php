<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentEvaluationRunner;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class AgentEvaluateCommand extends Command
{
    protected $signature = 'agent:evaluate
        {--dataset=core-routing : Dataset key}
        {--candidate= : Candidate label}
        {--baseline= : Baseline run hash}
        {--limit= : Max cases}
        {--dry-run : Do not persist case results}';

    protected $description = 'Run offline Agent planning evaluation (no business execution).';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentEvaluationRunner $runner,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $limit = $this->option('limit');
        $result = $runner->run(
            datasetKey: (string) $this->option('dataset'),
            candidateLabel: $this->option('candidate') !== null ? (string) $this->option('candidate') : null,
            baselineRunHash: $this->option('baseline') !== null ? (string) $this->option('baseline') : null,
            limit: $limit !== null && $limit !== '' ? (int) $limit : null,
            dryRun: (bool) $this->option('dry-run'),
        );

        if (! ($result['ok'] ?? false)) {
            $this->error((string) ($result['code'] ?? 'failed'));

            return self::FAILURE;
        }

        $this->info('run='.$result['run_hash_id']);
        $this->info('gate='.($result['gate']['status'] ?? 'n/a'));
        $this->line(json_encode($result['summary'] ?? [], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
