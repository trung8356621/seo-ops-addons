<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Jobs;

use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\SiteSync\Models\SeoLinkHealthRun;
use Omnichannel\Addons\SiteSync\Services\LinkHealth\LinkHealthRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessLinkHealthBatchJob implements ShouldQueue
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

    public function handle(LinkHealthRunService $service): void
    {
        $run = SeoLinkHealthRun::query()->find($this->runId);
        if (! $run instanceof SeoLinkHealthRun) {
            return;
        }

        $service->processNext($run);
    }
}
