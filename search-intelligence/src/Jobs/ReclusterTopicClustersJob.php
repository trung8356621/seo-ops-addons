<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use RuntimeException;
use Throwable;

/**
 * Full-domain Topic Cluster repair.
 * Uses the default queue so a normal `queue:work` (without only automation queues) can consume it.
 * Do not put this on seo-audit — that queue is often flooded by heartbeats/audits.
 */
final class ReclusterTopicClustersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE_NAME = 'default';

    public int $tries = 2;

    public int $timeout = 600;

    /** Keep unique lock at least as long as timeout so UI "queued" is not unlocked early. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $siteId,
    ) {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return 'topic-cluster-recluster-'.$this->siteId;
    }

    public function handle(ReclusterTopicClustersService $service): void
    {
        Log::info('ReclusterTopicClustersJob started', [
            'site_id' => $this->siteId,
            'queue' => self::QUEUE_NAME,
        ]);

        $cacheKey = self::resultCacheKey($this->siteId);
        Cache::put($cacheKey, [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'queue' => self::QUEUE_NAME,
        ], 3600);

        try {
            $result = $service->recluster($this->siteId);
        } catch (Throwable $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now()->toIso8601String(),
                'queue' => self::QUEUE_NAME,
            ], 3600);

            throw $e;
        }

        if (! $result->success) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'metrics' => $result->metrics,
                'error' => $result->error,
                'finished_at' => now()->toIso8601String(),
                'queue' => self::QUEUE_NAME,
            ], 3600);

            Log::info('ReclusterTopicClustersJob finished', [
                'site_id' => $this->siteId,
                'status' => 'failed',
            ]);

            throw new RuntimeException(
                is_string($result->error) && $result->error !== ''
                    ? $result->error
                    : 'Recluster topic clusters failed',
            );
        }

        Cache::put($cacheKey, [
            'status' => 'completed',
            'metrics' => $result->metrics,
            'error' => $result->error,
            'finished_at' => now()->toIso8601String(),
            'queue' => self::QUEUE_NAME,
        ], 3600);

        Log::info('ReclusterTopicClustersJob finished', [
            'site_id' => $this->siteId,
            'status' => 'completed',
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Cache::put(self::resultCacheKey($this->siteId), [
            'status' => 'failed',
            'error' => $e?->getMessage(),
            'finished_at' => now()->toIso8601String(),
            'queue' => self::QUEUE_NAME,
        ], 3600);
    }

    public static function resultCacheKey(int $siteId): string
    {
        return 'topic_cluster_recluster:'.$siteId;
    }
}
