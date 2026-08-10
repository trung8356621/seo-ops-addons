<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncGscSiteSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public int $siteId,
        public int $userId,
    ) {}

    /**
     * @return array{ok: bool, message: string, query_count: int}
     */
    public function handle(GoogleSearchConsoleSyncService $syncService): array
    {
        return $syncService->syncSiteWithDetails($this->siteId, $this->userId);
    }
}
