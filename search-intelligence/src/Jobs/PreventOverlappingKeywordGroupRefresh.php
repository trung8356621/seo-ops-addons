<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Support\Facades\Cache;

/**
 * One refresh per site. A synchronous caller must not look successful when
 * another run still holds the lock; queued callers are released for retry.
 */
final class PreventOverlappingKeywordGroupRefresh
{
    public function __construct(private readonly int $siteId) {}

    public function handle(object $job, callable $next): void
    {
        $lock = Cache::lock($this->key(), 3600);
        if (! $lock->get()) {
            if (($job->connection ?? null) === 'sync') {
                throw new KeywordGroupRefreshInProgressException(
                    'keyword group refresh already running for site '.$this->siteId,
                );
            }
            if (method_exists($job, 'release')) {
                $job->release(30);
            }

            return;
        }

        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }

    public function key(): string
    {
        return 'laravel-queue-overlap:'.RefreshKeywordGroupsJob::class.':keyword-group-refresh:'.$this->siteId;
    }
}
