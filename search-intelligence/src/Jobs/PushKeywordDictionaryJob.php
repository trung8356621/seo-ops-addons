<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;

/**
 * Retired: keyword dictionary push depended on classification/cluster builders.
 * Kept as a no-op queue job so existing dispatches drain cleanly.
 */
final class PushKeywordDictionaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly int $siteId,
    ) {
        $this->onQueue(AuditLinkStatusJob::QUEUE_NAME);
    }

    public function handle(): void
    {
        // Classification / dictionary push retired with KI cluster strip.
    }
}
