<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Jobs;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;

final class PollWordPressHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(
        public readonly int $siteId,
    ) {
        $this->onQueue(AuditLinkStatusJob::QUEUE_NAME);
    }

    public function handle(WordPressHeartbeatPollService $service): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }

        $service->poll($site);
    }
}
