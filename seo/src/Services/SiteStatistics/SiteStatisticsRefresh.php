<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteStatistics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Seo\Jobs\RebuildSiteStatisticsSnapshotJob;

/**
 * The only runtime entry that may request a statistics rebuild.
 * Callers: successful Site Sync terminal status, successful Article Archive.
 */
final class SiteStatisticsRefresh
{
    /** @var list<string> */
    public const SUCCESSFUL_SYNC_STATUSES = [
        'completed',
        'completed_with_warnings',
    ];

    public function __construct(
        private readonly SiteStatisticsSnapshotStore $store,
    ) {}

    public function afterSuccessfulSync(int $siteId, string $status): void
    {
        if (! in_array($status, self::SUCCESSFUL_SYNC_STATUSES, true)) {
            return;
        }

        $this->request($siteId);
    }

    public function afterSuccessfulArchive(int $siteId): void
    {
        $this->request($siteId);
    }

    public function request(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }

        DB::afterCommit(function () use ($siteId): void {
            $this->store->bumpGeneration($siteId);
            if (! Cache::add($this->store->pendingKey($siteId), 1, 600)) {
                return;
            }

            RebuildSiteStatisticsSnapshotJob::dispatch($siteId);
        });
    }
}
