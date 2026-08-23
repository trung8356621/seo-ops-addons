<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Jobs\SiteSync;

use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncRunExecution;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner;
use App\Support\RuntimeLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessSiteSyncStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $runId,
        /** @var int 0 = legacy job (status-only guard) */
        public readonly int $executionGeneration = 0,
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(SiteSyncStepRunner $runner, SiteSyncRunExecution $execution): void
    {
        if ($execution->shouldSkipJob($this->runId, $this->executionGeneration)) {
            $run = $execution->freshRun($this->runId);
            RuntimeLogger::warning('site_sync.job_skipped_canceled', [
                'run_id' => $this->runId,
                'job' => 'ProcessSiteSyncStepJob',
                'job_generation' => $this->executionGeneration,
                'run_generation' => $run !== null ? $execution->readGeneration($run) : null,
                'run_status' => $run !== null ? (string) $run->status : null,
                'queue_job_id' => $this->job?->getJobId(),
            ]);

            return;
        }

        RuntimeLogger::warning('site_sync.job_tick', [
            'run_id' => $this->runId,
            'job' => 'ProcessSiteSyncStepJob',
            'job_generation' => $this->executionGeneration,
        ]);

        $runner->runNext($this->runId, true, $this->executionGeneration);
    }
}
