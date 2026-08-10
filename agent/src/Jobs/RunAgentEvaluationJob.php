<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Jobs;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentEvaluationRunner;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Offline evaluation — never calls business command bus / business skills. */
final class RunAgentEvaluationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $datasetKey,
        public readonly ?string $candidateLabel = null,
        public readonly ?string $baselineRunHash = null,
        public readonly ?int $limit = null,
        public readonly bool $dryRun = false,
        public readonly ?int $createdBy = null,
    ) {}

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentEvaluationRunner $runner,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $runner->run(
            $this->datasetKey,
            $this->candidateLabel,
            $this->baselineRunHash,
            $this->limit,
            $this->dryRun,
            $this->createdBy,
        );
    }
}
