<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Jobs;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchOrchestrator;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Support\RuntimeLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delayed wake for Auto Cost waiting_retry / Free circuit cooldown.
 * Does not keep a PHP request alive overnight.
 */
final class WakeGlobalMonthlyBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $batchId,
    ) {
        $this->onQueue(ContentProjectRunEngineFeature::queueName());
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        GlobalMonthlyBatchOrchestrator $orchestrator,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $result = $orchestrator->wakeForAutoRetry(
            $this->batchId,
            ActorContext::queue('global_batch_wake:'.$this->batchId),
        );

        RuntimeLogger::info('global_monthly_batch.woke', [
            'batch_id' => $this->batchId,
            'ok' => $result['ok'] ?? false,
            'message' => $result['message'] ?? null,
            'metadata' => $result['metadata'] ?? [],
        ]);
    }
}
