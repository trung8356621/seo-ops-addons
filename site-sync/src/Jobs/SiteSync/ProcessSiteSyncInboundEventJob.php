<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Jobs\SiteSync;

use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncDeltaEventIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessSiteSyncInboundEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $eventId,
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return 'site-sync-inbound-event:'.$this->eventId;
    }

    public function handle(SiteSyncDeltaEventIngestor $ingestor): void
    {
        app(\Omnichannel\Addons\SiteSync\Services\Observability\SiteSyncHeartbeatService::class)
            ->touch('queue', ['job' => 'ProcessSiteSyncInboundEventJob', 'event_id' => $this->eventId]);
        $ingestor->process($this->eventId);
    }
}
