<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Jobs;

use Omnichannel\Addons\SearchIntelligence\Services\IncrementalDomainSyncRunner;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchFoundation\Support\IncrementalDomainSyncCache;
use App\Models\Site;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunIncrementalDomainSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public function __construct(
        public int $siteId,
        public int $userId,
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return 'seo-incr-sync:'.$this->siteId.':'.$this->userId;
    }

    public function handle(
        IncrementalDomainSyncRunner $runner,
        SeoDatabaseConnectionService $databaseConnection,
    ): void {
        $databaseConnection->bootstrapSeoDatabaseConnection($this->siteId);

        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user !== null) {
            auth()->setUser($user);
        }

        $runner->run($site, $this->userId);
    }

    public function failed(?Throwable $exception): void
    {
        $cacheKey = IncrementalDomainSyncCache::cacheKey($this->userId, $this->siteId);
        $state = Cache::get($cacheKey);

        if (! is_array($state)) {
            return;
        }

        if (($state['status'] ?? '') === IncrementalDomainSyncCache::STATUS_COMPLETED) {
            return;
        }

        $state['status'] = IncrementalDomainSyncCache::STATUS_FAILED;
        $state['message'] = $exception?->getMessage()
            ?? __('seo-content-ai::filament.domain.sync_incremental_failed');

        Cache::put(
            $cacheKey,
            IncrementalDomainSyncCache::touch($state),
            now()->addHours(2),
        );
    }
}
