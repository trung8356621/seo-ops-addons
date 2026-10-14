<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\Seo\Services\SiteStatistics\SiteStatisticsRebuilder;
use Omnichannel\Addons\Seo\Services\SiteStatistics\SiteStatisticsSnapshotStore;
use Throwable;

final class RebuildSiteStatisticsSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public int $siteId,
    ) {}

    public function handle(SiteStatisticsSnapshotStore $store, SiteStatisticsRebuilder $rebuilder): void
    {
        if ($this->siteId <= 0) {
            return;
        }

        $lock = Cache::lock($store->lockKey($this->siteId), $this->timeout);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $guard = 0;
            while ($guard < 5) {
                $guard++;
                $seen = $store->generation($this->siteId);
                $payload = $rebuilder->build($this->siteId, $seen);
                if ($store->generation($this->siteId) !== $seen) {
                    continue;
                }

                $store->put($this->siteId, $payload);
                if ($store->generation($this->siteId) !== $seen) {
                    continue;
                }

                Cache::forget($store->pendingKey($this->siteId));
                if ($store->generation($this->siteId) !== $seen) {
                    Cache::add($store->pendingKey($this->siteId), 1, 600);

                    continue;
                }

                return;
            }

            self::dispatch($this->siteId);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $store = app(SiteStatisticsSnapshotStore::class);
        Cache::forget($store->pendingKey($this->siteId));
    }
}
