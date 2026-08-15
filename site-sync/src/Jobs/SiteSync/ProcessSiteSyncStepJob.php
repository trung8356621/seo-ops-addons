<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Jobs\SiteSync;

use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner;
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
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(SiteSyncStepRunner $runner): void
    {
        \App\Support\RuntimeLogger::warning('site_sync.job_tick', [
            'run_id' => $this->runId,
            'job' => 'ProcessSiteSyncStepJob',
        ]);
        $runner->runNext($this->runId, true);
    }
}
