<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\SiteSync\Models\SeoLinkAnalysisRun;
use Omnichannel\Addons\SiteSync\Services\LinkAnalysis\LinkAnalysisRunService;

final class ProcessLinkAnalysisBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue(AuditLinkStatusJob::QUEUE_NAME);
    }

    public function handle(LinkAnalysisRunService $service): void
    {
        $run = SeoLinkAnalysisRun::query()->find($this->runId);
        if (! $run instanceof SeoLinkAnalysisRun) {
            return;
        }

        $service->processNext($run);
    }
}
