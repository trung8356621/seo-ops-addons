<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use App\Models\ApiConnection;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure health checks — read-only, never mutates business state.
 */
final class ContentProjectOpsHealthService
{
    private const CONNECTION = 'omi_seo_ai';

    public function __construct(
        private readonly ContentProjectQueueHealthService $queueHealth,
    ) {}

    /**
     * @return list<array{key: string, ok: bool, message: string, latency_ms?: int}>
     */
    public function checks(): array
    {
        return [
            $this->checkDatabase(),
            $this->checkRedis(),
            $this->checkCache(),
            $this->checkQueue(),
            $this->checkWorker(),
            $this->checkStorage(),
            $this->checkWordPress(),
            $this->checkAiProvider(),
            $this->checkAutomation(),
            $this->checkScheduler(),
        ];
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkDatabase(): array
    {
        $started = microtime(true);
        try {
            DB::connection(self::CONNECTION)->selectOne('SELECT 1 AS ok');

            return [
                'key' => 'database',
                'ok' => true,
                'message' => 'omi_seo_ai reachable',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'key' => 'database',
                'ok' => false,
                'message' => 'omi_seo_ai unreachable',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkRedis(): array
    {
        $started = microtime(true);
        try {
            $store = Cache::getStore();
            $ok = method_exists($store, 'connection') || class_exists(\Illuminate\Cache\RedisStore::class);

            // Prefer actual ping when Redis store available
            if (method_exists($store, 'connection')) {
                /** @var mixed $connection */
                $connection = $store->connection();
                if (is_object($connection) && method_exists($connection, 'ping')) {
                    $connection->ping();
                    $ok = true;
                }
            }

            return [
                'key' => 'redis',
                'ok' => $ok,
                'message' => $ok ? 'redis reachable' : 'redis unknown',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable) {
            return [
                'key' => 'redis',
                'ok' => false,
                'message' => 'redis unavailable',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkCache(): array
    {
        $started = microtime(true);
        $key = 'seo.content_project.ops_health.ping';

        try {
            Cache::put($key, 'pong', now()->addMinute());
            $ok = Cache::get($key) === 'pong';

            return [
                'key' => 'cache',
                'ok' => $ok,
                'message' => $ok ? 'cache read/write ok' : 'cache ping mismatch',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable) {
            return [
                'key' => 'cache',
                'ok' => false,
                'message' => 'cache unavailable',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkQueue(): array
    {
        $health = $this->scopedQueueHealth();
        $lastRun = $health['last_worker_run'];
        $ok = false;

        if (is_string($lastRun) && $lastRun !== '') {
            try {
                $ok = Carbon::parse($lastRun)->greaterThan(now()->subMinutes(10));
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return [
            'key' => 'queue',
            'ok' => $ok,
            'message' => $ok ? 'queue heartbeat fresh' : 'queue heartbeat stale or unknown',
        ];
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkWorker(): array
    {
        $health = $this->scopedQueueHealth();
        $lastRun = $health['last_worker_run'];
        $ok = false;

        if (is_string($lastRun) && $lastRun !== '') {
            try {
                $ok = Carbon::parse($lastRun)->greaterThan(now()->subMinutes(10));
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return [
            'key' => 'worker',
            'ok' => $ok,
            'message' => $lastRun ? 'last_run='.$lastRun : 'no worker heartbeat',
        ];
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkStorage(): array
    {
        $path = storage_path('logs');
        $ok = is_dir($path) && is_writable($path);

        return [
            'key' => 'storage',
            'ok' => $ok,
            'message' => $ok ? 'storage writable' : 'storage not writable',
        ];
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkWordPress(): array
    {
        try {
            $configured = Site::query()
                ->whereHas('metas', static function ($q): void {
                    $q->whereIn('meta_key', ['seo_read_token', 'seo_migration_token'])
                        ->where('meta_value', '!=', '');
                })
                ->exists();

            return [
                'key' => 'wordpress',
                'ok' => true,
                'message' => $configured ? 'configured' : 'unknown',
            ];
        } catch (\Throwable) {
            return [
                'key' => 'wordpress',
                'ok' => true,
                'message' => 'unknown',
            ];
        }
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkAiProvider(): array
    {
        try {
            if (! class_exists(ApiConnection::class)) {
                return ['key' => 'ai_provider', 'ok' => true, 'message' => 'unknown'];
            }

            $query = ApiConnection::query()->whereNotNull('api_key');
            if (Schema::hasColumn('api_connections', 'status')) {
                $query->where('status', 'active');
            }
            $count = (int) $query->count();

            return [
                'key' => 'ai_provider',
                'ok' => $count > 0,
                'message' => $count > 0 ? "active_connections={$count}" : 'no active connections',
            ];
        } catch (\Throwable) {
            return ['key' => 'ai_provider', 'ok' => true, 'message' => 'unknown'];
        }
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkAutomation(): array
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('automation_executions')) {
            return ['key' => 'automation', 'ok' => true, 'message' => 'unknown'];
        }

        try {
            $failed = (int) DB::connection(self::CONNECTION)
                ->table('automation_executions')
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            return [
                'key' => 'automation',
                'ok' => $failed === 0,
                'message' => "recent_failed={$failed}",
            ];
        } catch (\Throwable) {
            return ['key' => 'automation', 'ok' => true, 'message' => 'unknown'];
        }
    }

    /**
     * @return array{key: string, ok: bool, message: string, latency_ms?: int}
     */
    private function checkScheduler(): array
    {
        $health = $this->scopedQueueHealth();
        $lastRun = $health['last_worker_run'];

        return [
            'key' => 'scheduler',
            'ok' => is_string($lastRun) && $lastRun !== '',
            'message' => $lastRun ?? 'no worker heartbeat',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopedQueueHealth(): array
    {
        $connectionId = null;
        $current = \Omnichannel\Addons\Seo\Support\SeoConnectionContext::current();
        if ($current instanceof \App\Models\SeoDatabaseConnection) {
            $connectionId = (int) $current->getKey();
        }

        return $this->queueHealth->snapshot(null, $connectionId);
    }
}
