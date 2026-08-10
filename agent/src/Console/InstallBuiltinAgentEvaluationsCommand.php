<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\BuiltinAgentEvaluationDatasetInstaller;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class InstallBuiltinAgentEvaluationsCommand extends Command
{
    protected $signature = 'agent:evaluations:install-builtin';

    protected $description = 'Install/update builtin Agent evaluation datasets (idempotent).';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        BuiltinAgentEvaluationDatasetInstaller $installer,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $stats = $installer->install();
        $this->info(sprintf(
            'datasets_created=%d cases_upserted=%d skipped=%d',
            $stats['datasets'],
            $stats['cases'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
