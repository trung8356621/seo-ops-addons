<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\WordPress\Enums\WpSyncJobStatus;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lease + heartbeat + terminal transitions cho WordPress sync jobs.
 * Mọi đường code kết thúc processing phải đi qua complete / fail / cancel / markStale.
 */
final class ArticleWpSyncLeaseService
{
    public const LEASE_SECONDS = 120;

    public const HEARTBEAT_INTERVAL_SECONDS = 20;

    public const ARTICLE_IDLE = 'idle';

    /** Stale (heartbeat lost / orphan pending) — tự enqueue lại tối đa N lần. */
    public const MAX_STALE_AUTO_RETRIES = 3;

    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $auditMeta
     */
    public function enqueue(
        SeoArticle $article,
        string $source,
        int $initiatedBy,
        string $requestId,
        string $correlationId,
        array $settings = [],
        array $auditMeta = [],
    ): SeoArticleWpSyncJob {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        $articleId = (int) $article->id;
        $siteId = (int) ($article->site_id ?? 0);
        $mode = (string) ($settings['mode'] ?? 'sync');

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $article,
            $articleId,
            $siteId,
            $source,
            $initiatedBy,
            $requestId,
            $correlationId,
            $settings,
            $auditMeta,
            $mode,
        ): SeoArticleWpSyncJob {
            $active = SeoArticleWpSyncJob::query()
                ->where('article_id', $articleId)
                ->whereIn('status', WpSyncJobStatus::activeValues())
                ->lockForUpdate()
                ->first();

            if ($active instanceof SeoArticleWpSyncJob) {
                return $active;
            }

            $job = SeoArticleWpSyncJob::query()->create([
                'article_id' => $articleId,
                'site_id' => $siteId > 0 ? $siteId : null,
                'status' => WpSyncJobStatus::Pending,
                'idempotency_key' => SeoArticleWpSyncJob::makeIdempotencyKey($siteId, $articleId),
                'mode' => $mode,
                'source' => $source,
                'initiated_by' => $initiatedBy > 0 ? $initiatedBy : null,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'worker_id' => null,
                'attempts' => 0,
                'queued_at' => now(),
                'started_at' => null,
                'heartbeat_at' => null,
                'locked_until' => null,
                'finished_at' => null,
                'error_message' => null,
                'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                'wordpress_permalink' => null,
                'stage' => 'queued',
                'settings' => $settings,
                'audit_meta' => $auditMeta,
            ]);

            $this->bindArticle($article, $job, WpSyncJobStatus::Pending);
            $this->projectMeta($article, $job);

            return $job->fresh() ?? $job;
        });
    }

    public function claim(int $jobId, ?string $workerId = null): ?SeoArticleWpSyncJob
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();
        $workerId = $workerId !== null && $workerId !== '' ? $workerId : (string) Str::uuid();

        return DB::connection('omi_seo_ai')->transaction(function () use ($jobId, $workerId): ?SeoArticleWpSyncJob {
            /** @var SeoArticleWpSyncJob|null $job */
            $job = SeoArticleWpSyncJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if (! $job instanceof SeoArticleWpSyncJob) {
                return null;
            }

            if ($job->status === WpSyncJobStatus::Cancelled) {
                return null;
            }

            if ($job->status === WpSyncJobStatus::Processing) {
                // Cùng worker retry / duplicate pop — gia hạn lease.
                if ($job->worker_id === $workerId) {
                    $this->applyHeartbeat($job);

                    return $job->fresh() ?? $job;
                }

                return null;
            }

            if ($job->status !== WpSyncJobStatus::Pending) {
                return null;
            }

            $now = now();
            $job->forceFill([
                'status' => WpSyncJobStatus::Processing,
                'worker_id' => $workerId,
                'attempts' => (int) $job->attempts + 1,
                'started_at' => $job->started_at ?? $now,
                'heartbeat_at' => $now,
                'locked_until' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                'stage' => 'processing',
                'error_message' => null,
                'finished_at' => null,
            ])->save();

            $article = SeoArticle::query()->find($job->article_id);
            if ($article instanceof SeoArticle) {
                $this->bindArticle($article, $job, WpSyncJobStatus::Processing);
                $this->projectMeta($article, $job);
            }

            return $job->fresh() ?? $job;
        });
    }

    public function heartbeat(int $jobId): void
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        try {
            DB::connection('omi_seo_ai')->transaction(function () use ($jobId): void {
                /** @var SeoArticleWpSyncJob|null $job */
                $job = SeoArticleWpSyncJob::query()->whereKey($jobId)->lockForUpdate()->first();
                if (! $job instanceof SeoArticleWpSyncJob || $job->status !== WpSyncJobStatus::Processing) {
                    return;
                }

                $this->applyHeartbeat($job);

                $article = SeoArticle::query()->find($job->article_id);
                if ($article instanceof SeoArticle) {
                    $this->projectMeta($article, $job->fresh() ?? $job);
                }
            });
        } catch (Throwable $e) {
            Log::warning('wordpress.sync_lease.heartbeat_failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function releaseForRetry(SeoArticleWpSyncJob $job): SeoArticleWpSyncJob
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        return DB::connection('omi_seo_ai')->transaction(function () use ($job): SeoArticleWpSyncJob {
            /** @var SeoArticleWpSyncJob|null $locked */
            $locked = SeoArticleWpSyncJob::query()->whereKey($job->id)->lockForUpdate()->first();
            if (! $locked instanceof SeoArticleWpSyncJob) {
                return $job;
            }
            if ($locked->isTerminal()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => WpSyncJobStatus::Pending,
                'worker_id' => null,
                'locked_until' => null,
                'heartbeat_at' => null,
                'stage' => 'queued_retry',
                'error_message' => null,
                'finished_at' => null,
            ])->save();

            $article = SeoArticle::query()->find($locked->article_id);
            if ($article instanceof SeoArticle) {
                $this->bindArticle($article, $locked, WpSyncJobStatus::Pending);
                $this->projectMeta($article, $locked);
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function complete(SeoArticleWpSyncJob $job, array $result = []): SeoArticleWpSyncJob
    {
        return $this->finalize($job, WpSyncJobStatus::Completed, null, $result);
    }

    public function fail(SeoArticleWpSyncJob $job, string $errorMessage): SeoArticleWpSyncJob
    {
        return $this->finalize($job, WpSyncJobStatus::Failed, $errorMessage);
    }

    public function cancel(SeoArticleWpSyncJob $job, string $reason = 'Cancelled manually'): SeoArticleWpSyncJob
    {
        return $this->finalize($job, WpSyncJobStatus::Cancelled, $reason);
    }

    public function markStale(
        SeoArticleWpSyncJob $job,
        string $reason = 'Worker heartbeat expired',
        bool $autoRetry = true,
    ): SeoArticleWpSyncJob {
        $finalized = $this->finalize($job, WpSyncJobStatus::Stale, $reason);
        if ($autoRetry) {
            $this->maybeAutoRetryAfterStale($finalized, $reason);
        }

        return $finalized;
    }

    /**
     * Cancel mọi job active của article + idle article + purge Laravel jobs.
     */
    public function resetArticle(SeoArticle $article, string $reason = 'Cancelled manually'): bool
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();
        $articleId = (int) $article->id;
        $changed = false;

        DB::connection('omi_seo_ai')->transaction(function () use ($article, $articleId, $reason, &$changed): void {
            $jobs = SeoArticleWpSyncJob::query()
                ->where('article_id', $articleId)
                ->whereIn('status', WpSyncJobStatus::activeValues())
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                $this->finalizeWithinTransaction($job, WpSyncJobStatus::Cancelled, $reason);
                $changed = true;
            }

            $fresh = $article->fresh() ?? $article;
            $hadMeta = $fresh->articleMetas()
                ->whereIn('meta_key', [
                    ArticleWpSyncQueueService::META_KEY,
                    ArticleWpSyncQueueService::BUNDLE_META_KEY,
                ])
                ->exists();

            $updates = [];
            if ((string) ($fresh->wordpressLink?->sync_status ?? '') !== self::ARTICLE_IDLE) {
                $updates['wp_sync_status'] = self::ARTICLE_IDLE;
            }
            if ($fresh->wordpressLink?->sync_job_id !== null) {
                $updates['wp_sync_job_id'] = null;
            }
            if ($updates !== []) {
                // Eloquent path: RoutesArticleExtensionAttributes strips → WordpressArticleLinkWriter.
                $fresh->forceFill($updates)->save();
                $changed = true;
            }

            if ($hadMeta) {
                $this->clearLegacyMeta($fresh);
                $changed = true;
            }
        });

        return $changed;
    }

    public function activeJobForArticle(int $articleId): ?SeoArticleWpSyncJob
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        return SeoArticleWpSyncJob::query()
            ->where('article_id', $articleId)
            ->whereIn('status', WpSyncJobStatus::activeValues())
            ->orderByDesc('id')
            ->first();
    }

    public function find(int $jobId): ?SeoArticleWpSyncJob
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        $job = SeoArticleWpSyncJob::query()->find($jobId);

        return $job instanceof SeoArticleWpSyncJob ? $job : null;
    }

    /**
     * Watchdog: lease hết hạn / processing không heartbeat / meta mồ côi.
     *
     * @return array{stale_jobs: int, orphan_metas: int, force_unlocked: int}
     */
    public function recoverExpiredLeases(bool $forceAllStuckMeta = false): array
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();
        $staleJobs = 0;
        $forceUnlocked = 0;

        try {
            // 1) processing + locked_until hết hạn
            SeoArticleWpSyncJob::query()
                ->where('status', WpSyncJobStatus::Processing)
                ->whereNotNull('locked_until')
                ->where('locked_until', '<', now())
                ->orderBy('id')
                ->chunkById(50, function ($jobs) use (&$staleJobs): void {
                    foreach ($jobs as $job) {
                        if (! $job instanceof SeoArticleWpSyncJob) {
                            continue;
                        }
                        $this->markStale($job, 'Worker heartbeat expired');
                        $this->releaseArticleCacheLocks((int) $job->article_id);
                        $staleJobs++;
                    }
                });

            // 2) processing nhưng locked_until NULL (legacy / claim lỗi) — stale sau 2 phút
            SeoArticleWpSyncJob::query()
                ->where('status', WpSyncJobStatus::Processing)
                ->whereNull('locked_until')
                ->where(function ($query): void {
                    $query->where('started_at', '<', now()->subMinutes(2))
                        ->orWhere(function ($inner): void {
                            $inner->whereNull('started_at')
                                ->where('queued_at', '<', now()->subMinutes(2));
                        })
                        ->orWhere(function ($inner): void {
                            $inner->whereNull('started_at')->whereNull('queued_at')
                                ->where('updated_at', '<', now()->subMinutes(2));
                        });
                })
                ->orderBy('id')
                ->chunkById(50, function ($jobs) use (&$staleJobs): void {
                    foreach ($jobs as $job) {
                        if (! $job instanceof SeoArticleWpSyncJob) {
                            continue;
                        }
                        $this->markStale($job, 'Processing without lease lock (locked_until null)');
                        $this->releaseArticleCacheLocks((int) $job->article_id);
                        $staleJobs++;
                    }
                });

            // 3) processing còn locked_until tương lai nhưng không heartbeat quá 5 phút (clock skew / zombie)
            SeoArticleWpSyncJob::query()
                ->where('status', WpSyncJobStatus::Processing)
                ->where(function ($query): void {
                    $query->where('heartbeat_at', '<', now()->subMinutes(5))
                        ->orWhere(function ($inner): void {
                            $inner->whereNull('heartbeat_at')
                                ->where('started_at', '<', now()->subMinutes(5));
                        });
                })
                ->orderBy('id')
                ->chunkById(50, function ($jobs) use (&$staleJobs): void {
                    foreach ($jobs as $job) {
                        if (! $job instanceof SeoArticleWpSyncJob) {
                            continue;
                        }
                        if (! $this->isLeaseExpired($job)) {
                            continue;
                        }
                        $this->markStale($job, 'Worker heartbeat stale (>5m)');
                        $this->releaseArticleCacheLocks((int) $job->article_id);
                        $staleJobs++;
                    }
                });

            // 4) pending: không còn job trong bảng Laravel `jobs` (worker crash / cache fail lúc dispatch)
            //    hoặc pending > 3 phút — không chờ 30 phút mới nhả overlay.
            SeoArticleWpSyncJob::query()
                ->where('status', WpSyncJobStatus::Pending)
                ->where('queued_at', '<', now()->subSeconds(90))
                ->orderBy('id')
                ->chunkById(50, function ($jobs) use (&$staleJobs): void {
                    foreach ($jobs as $job) {
                        if (! $job instanceof SeoArticleWpSyncJob) {
                            continue;
                        }

                        $queuedTooLong = $job->queued_at !== null
                            && $job->queued_at->lt(now()->subMinutes(3));
                        $missingQueuePayload = ! $this->laravelQueueHasManualSyncJob(
                            (int) $job->id,
                            (int) $job->article_id,
                        );

                        if (! $queuedTooLong && ! $missingQueuePayload) {
                            continue;
                        }

                        $reason = $missingQueuePayload
                            ? 'Pending lease without Laravel jobs row (worker/cache failed)'
                            : 'Pending job expired without claim (>3m)';
                        $this->markStale($job, $reason);
                        $this->releaseArticleCacheLocks((int) $job->article_id);
                        $staleJobs++;
                    }
                });
        } catch (Throwable $e) {
            Log::warning('wordpress.sync_lease.recover_jobs_failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $orphanMetas = $this->recoverOrphanWpSyncQueueMetas($forceAllStuckMeta);
        if ($forceAllStuckMeta) {
            $forceUnlocked = $orphanMetas;
        }

        return [
            'stale_jobs' => $staleJobs,
            'orphan_metas' => $orphanMetas,
            'force_unlocked' => $forceUnlocked,
        ];
    }

    public function isLeaseExpired(SeoArticleWpSyncJob $job): bool
    {
        if ($job->status === WpSyncJobStatus::Pending) {
            $queuedAt = $job->queued_at;
            if ($queuedAt === null) {
                return true;
            }

            if ($queuedAt->lt(now()->subMinutes(3))) {
                return true;
            }

            // Pending > 90s mà không còn payload trong bảng jobs → worker/cache gãy.
            if (
                $queuedAt->lt(now()->subSeconds(90))
                && ! $this->laravelQueueHasManualSyncJob((int) $job->id, (int) $job->article_id)
            ) {
                return true;
            }

            return false;
        }

        if ($job->status !== WpSyncJobStatus::Processing) {
            return false;
        }

        if ($job->locked_until !== null && $job->locked_until->lt(now())) {
            return true;
        }

        if ($job->locked_until === null) {
            $anchor = $job->started_at ?? $job->queued_at ?? $job->updated_at;
            if ($anchor === null) {
                return true;
            }

            return $anchor->lt(now()->subMinutes(2));
        }

        $heartbeat = $job->heartbeat_at ?? $job->started_at;
        if ($heartbeat !== null && $heartbeat->lt(now()->subMinutes(5))) {
            return true;
        }

        return false;
    }

    /**
     * Có ManualWordPressSyncJob đang chờ trong bảng jobs (queue seo) cho lease này không.
     */
    public function laravelQueueHasManualSyncJob(int $syncJobId, int $articleId): bool
    {
        if ($syncJobId <= 0 && $articleId <= 0) {
            return false;
        }

        try {
            $default = (string) config('queue.default');
            $driver = (string) config('queue.connections.'.$default.'.driver');
            if ($driver !== 'database') {
                // Redis/etc — không inspect được dễ dàng; coi như còn job.
                return true;
            }

            $connection = (string) config('queue.connections.'.$default.'.connection');
            if ($connection === '') {
                $connection = (string) config('database.default');
            }

            $query = DB::connection($connection)
                ->table('jobs')
                ->where('queue', ArticleWpSyncQueueService::QUEUE_NAME)
                ->where('payload', 'like', '%ManualWordPressSyncJob%');

            if ($syncJobId > 0) {
                $query->where(function ($inner) use ($syncJobId, $articleId): void {
                    $inner->where('payload', 'like', '%syncJobId";i:'.$syncJobId.'%')
                        ->orWhere('payload', 'like', '%"syncJobId";i:'.$syncJobId.'%');
                    if ($articleId > 0) {
                        $inner->orWhere('payload', 'like', '%articleId";i:'.$articleId.'%');
                    }
                });
            } elseif ($articleId > 0) {
                $query->where('payload', 'like', '%articleId";i:'.$articleId.'%');
            }

            return $query->exists();
        } catch (Throwable $e) {
            Log::warning('wordpress.sync_lease.jobs_table_inspect_failed', [
                'sync_job_id' => $syncJobId,
                'article_id' => $articleId,
                'error' => $e->getMessage(),
            ]);

            // Không đọc được jobs → đừng stale nhầm.
            return true;
        }
    }

    /**
     * Force unlock 1 article: stale/cancel lease + ghi meta terminal + nhả cache lock.
     */
    public function forceUnlockArticle(SeoArticle $article, string $reason = 'Force unlock stuck sync'): array
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();
        $articleId = (int) $article->id;

        $activeJobs = SeoArticleWpSyncJob::query()
            ->where('article_id', $articleId)
            ->whereIn('status', WpSyncJobStatus::activeValues())
            ->get();

        foreach ($activeJobs as $job) {
            if ($job instanceof SeoArticleWpSyncJob) {
                $this->markStale($job, $reason, autoRetry: false);
            }
        }

        $raw = trim((string) ($article->fresh() ?? $article)->articleMetas()
            ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
            ->value('meta_value') ?? '');
        $payload = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        if (! is_array($payload)) {
            $payload = [];
        }

        $meta = $this->writeOrphanMetaTerminal(
            $article->fresh() ?? $article,
            $payload !== [] ? $payload : ['operation' => 'wordpress_sync', 'status' => 'processing'],
            WpSyncJobStatus::Stale,
            $reason,
        );

        $this->releaseArticleCacheLocks($articleId);

        return $meta;
    }

    public function releaseArticleCacheLocks(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        try {
            Cache::lock('manual-wp-sync:'.$articleId)->forceRelease();
        } catch (Throwable) {
        }

        try {
            Cache::lock('seo-wp-publish-article-'.$articleId)->forceRelease();
        } catch (Throwable) {
        }
    }

    /**
     * Meta `wp_sync_queue` status pending/processing nhưng không có lease job active → ghi stale + unlock.
     * Nếu có lease nhưng lease đã hết hạn → markStale rồi update meta.
     */
    public function recoverOrphanWpSyncQueueMetas(bool $force = false): int
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();
        $count = 0;

        try {
            $rows = DB::connection('omi_seo_ai')
                ->table('article_meta')
                ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
                ->where(function ($query): void {
                    $query->where('meta_value', 'like', '%"status":"pending"%')
                        ->orWhere('meta_value', 'like', '%"status": "pending"%')
                        ->orWhere('meta_value', 'like', '%"status":"processing"%')
                        ->orWhere('meta_value', 'like', '%"status": "processing"%');
                })
                ->orderBy('id')
                ->limit(500)
                ->get(['id', 'article_id', 'meta_value']);
        } catch (Throwable $e) {
            Log::warning('wordpress.sync_lease.orphan_meta_scan_failed', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        foreach ($rows as $row) {
            $articleId = (int) ($row->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $payload = json_decode((string) ($row->meta_value ?? ''), true);
            if (! is_array($payload)) {
                continue;
            }

            $status = (string) ($payload['status'] ?? '');
            if (! in_array($status, WpSyncJobStatus::activeValues(), true)) {
                continue;
            }

            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $active = $this->activeJobForArticle($articleId);
            if ($active instanceof SeoArticleWpSyncJob) {
                if ($force || $this->isLeaseExpired($active)) {
                    $this->markStale(
                        $active,
                        $force
                            ? 'Force unlock stuck sync'
                            : 'Lease expired while meta still active',
                        autoRetry: ! $force,
                    );
                    $this->releaseArticleCacheLocks($articleId);
                    $count++;
                    continue;
                }

                // Lease còn sống — đồng bộ meta theo lease.
                $this->projectMeta($article, $active);
                continue;
            }

            // Không có lease job — meta mồ côi (đúng case article 3004/3884).
            $this->writeOrphanMetaTerminal(
                $article,
                $payload,
                WpSyncJobStatus::Stale,
                'Orphan wp_sync_queue meta: no active lease job (worker lost / legacy stuck).',
            );
            $this->releaseArticleCacheLocks($articleId);
            $count++;
        }

        return $count;
    }

    /**
     * Heal 1 article nếu meta còn pending/processing mà không có lease / lease hết hạn.
     *
     * @return array<string, mixed>|null payload meta sau heal (hoặc null nếu không orphan)
     */
    public function healArticleOrphanMeta(SeoArticle $article): ?array
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();
        $article = $article->fresh() ?? $article;

        $raw = trim((string) $article->articleMetas()
            ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
            ->value('meta_value') ?? '');
        if ($raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return null;
        }

        $status = (string) ($payload['status'] ?? '');
        if (! in_array($status, WpSyncJobStatus::activeValues(), true)) {
            return null;
        }

        $active = $this->activeJobForArticle((int) $article->id);
        if ($active instanceof SeoArticleWpSyncJob) {
            if (! $this->isLeaseExpired($active)) {
                return null;
            }

            $this->markStale($active, 'Lease expired on read heal');
            $this->releaseArticleCacheLocks((int) $article->id);

            $retried = $this->activeJobForArticle((int) $article->id);
            if ($retried instanceof SeoArticleWpSyncJob) {
                return $this->toOperationPayload($retried);
            }

            return $this->toOperationPayload($active->fresh() ?? $active);
        }

        $meta = $this->writeOrphanMetaTerminal(
            $article,
            $payload,
            WpSyncJobStatus::Stale,
            'Orphan wp_sync_queue meta healed on read.',
        );
        $this->releaseArticleCacheLocks((int) $article->id);

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeOrphanMetaTerminal(
        SeoArticle $article,
        array $payload,
        WpSyncJobStatus $status,
        string $reason,
    ): array {
        $now = now()->toIso8601String();
        $next = array_merge($payload, [
            'operation' => (string) ($payload['operation'] ?? 'wordpress_sync'),
            'status' => $status->value,
            'stage' => $status->value,
            'finished_at' => $now,
            'error' => $reason,
            'error_message' => $reason,
            'worker_id' => null,
            'locked_until' => null,
            'heartbeat_at' => null,
        ]);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => ArticleWpSyncQueueService::META_KEY],
            ['meta_value' => json_encode($next, JSON_UNESCAPED_UNICODE)],
        );

        // Eloquent path: RoutesArticleExtensionAttributes strips → WordpressArticleLinkWriter.
        $article->forceFill([
            'wp_sync_status' => self::ARTICLE_IDLE,
            'wp_sync_job_id' => null,
        ])->save();

        Log::warning('wordpress.sync_lease.orphan_meta_healed', [
            'article_id' => (int) $article->id,
            'previous_status' => $payload['status'] ?? null,
            'reason' => $reason,
        ]);

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function toOperationPayload(SeoArticleWpSyncJob $job): array
    {
        $status = $job->status instanceof WpSyncJobStatus
            ? $job->status
            : WpSyncJobStatus::tryFrom((string) $job->status) ?? WpSyncJobStatus::Failed;

        return [
            'id' => (string) $job->id,
            'job_id' => (int) $job->id,
            'article_id' => (int) $job->article_id,
            'operation' => 'wordpress_sync',
            'type' => 'wordpress_sync',
            'status' => $status->toPublicStatus(),
            'raw_status' => $status->value,
            'stage' => (string) ($job->stage ?? $status->value),
            'error_message' => (string) ($job->error_message ?? ''),
            'wordpress_post_id' => (int) ($job->wp_post_id ?? 0) ?: null,
            'wordpress_permalink' => (string) ($job->wordpress_permalink ?? '') ?: null,
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'heartbeat_at' => $job->heartbeat_at?->toIso8601String(),
            'locked_until' => $job->locked_until?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'worker_id' => $job->worker_id,
            'attempts' => (int) $job->attempts,
            'idempotency_key' => (string) $job->idempotency_key,
            'request_id' => $job->request_id,
            'correlation_id' => $job->correlation_id,
            'mode' => (string) ($job->mode ?? 'sync'),
        ];
    }

    private function applyHeartbeat(SeoArticleWpSyncJob $job): void
    {
        $now = now();
        $job->forceFill([
            'heartbeat_at' => $now,
            'locked_until' => $now->copy()->addSeconds(self::LEASE_SECONDS),
        ])->save();
    }

    /**
     * Sau stale: enqueue lại ManualWordPressSyncJob nếu chưa vượt MAX_STALE_AUTO_RETRIES.
     * Đếm qua settings.stale_auto_retries (0 = lần stale đầu → retry #1).
     */
    private function maybeAutoRetryAfterStale(SeoArticleWpSyncJob $staleJob, string $reason): void
    {
        $settings = is_array($staleJob->settings) ? $staleJob->settings : [];
        $retryCount = max(0, (int) ($settings['stale_auto_retries'] ?? 0));
        if ($retryCount >= self::MAX_STALE_AUTO_RETRIES) {
            Log::warning('wordpress.sync_lease.stale_auto_retry_exhausted', [
                'article_id' => (int) $staleJob->article_id,
                'stale_job_id' => (int) $staleJob->id,
                'retries' => $retryCount,
                'max' => self::MAX_STALE_AUTO_RETRIES,
                'reason' => $reason,
            ]);

            $exhaustedMsg = trim($reason.' (auto-retry exhausted '.$retryCount.'/'.self::MAX_STALE_AUTO_RETRIES.')');
            if ((string) ($staleJob->error_message ?? '') !== $exhaustedMsg) {
                $staleJob->forceFill(['error_message' => $exhaustedMsg])->save();
                $article = SeoArticle::query()->find((int) $staleJob->article_id);
                if ($article instanceof SeoArticle) {
                    $this->projectMeta($article, $staleJob->fresh() ?? $staleJob);
                }
            }

            return;
        }

        $article = SeoArticle::query()->find((int) $staleJob->article_id);
        if (! $article instanceof SeoArticle) {
            return;
        }

        if ($this->activeJobForArticle((int) $article->id) instanceof SeoArticleWpSyncJob) {
            return;
        }

        $nextRetry = $retryCount + 1;
        $requestId = (string) Str::uuid();
        $correlationId = trim((string) ($staleJob->correlation_id ?? ''));
        if ($correlationId === '') {
            $correlationId = (string) Str::uuid();
        }

        $nextSettings = array_merge($settings, [
            'stale_auto_retries' => $nextRetry,
            'stale_auto_retry_of' => (int) $staleJob->id,
            'stale_auto_retry_reason' => $reason,
        ]);
        $auditMeta = is_array($staleJob->audit_meta) ? $staleJob->audit_meta : [];
        $auditMeta['stale_auto_retry'] = $nextRetry;
        $auditMeta['stale_auto_retry_of'] = (int) $staleJob->id;
        $auditMeta['stale_auto_retry_reason'] = $reason;

        try {
            $newJob = $this->enqueue(
                article: $article,
                source: 'stale_auto_retry',
                initiatedBy: (int) ($staleJob->initiated_by ?? 0),
                requestId: $requestId,
                correlationId: $correlationId,
                settings: $nextSettings,
                auditMeta: $auditMeta,
            );

            if ((string) $newJob->request_id !== $requestId) {
                return;
            }

            ManualWordPressSyncJob::dispatch(
                articleId: (int) $article->id,
                userId: max(0, (int) ($staleJob->initiated_by ?? 0)),
                source: 'stale_auto_retry',
                requestId: $requestId,
                correlationId: $correlationId,
                domainId: (int) ($article->site_id ?? $staleJob->site_id ?? 0),
                requestedAt: now()->toIso8601String(),
                syncJobId: (int) $newJob->id,
                settings: $nextSettings,
                auditMeta: $auditMeta,
            )->afterCommit();

            Log::info('wordpress.sync_lease.stale_auto_retried', [
                'article_id' => (int) $article->id,
                'stale_job_id' => (int) $staleJob->id,
                'new_job_id' => (int) $newJob->id,
                'retry' => $nextRetry,
                'max' => self::MAX_STALE_AUTO_RETRIES,
                'reason' => $reason,
            ]);
        } catch (Throwable $e) {
            Log::warning('wordpress.sync_lease.stale_auto_retry_failed', [
                'article_id' => (int) $staleJob->article_id,
                'stale_job_id' => (int) $staleJob->id,
                'retry' => $nextRetry,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finalize(
        SeoArticleWpSyncJob $job,
        WpSyncJobStatus $status,
        ?string $errorMessage = null,
        array $result = [],
    ): SeoArticleWpSyncJob {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        return DB::connection('omi_seo_ai')->transaction(function () use ($job, $status, $errorMessage, $result): SeoArticleWpSyncJob {
            /** @var SeoArticleWpSyncJob|null $locked */
            $locked = SeoArticleWpSyncJob::query()->whereKey($job->id)->lockForUpdate()->first();
            if (! $locked instanceof SeoArticleWpSyncJob) {
                return $job;
            }

            if ($locked->isTerminal()) {
                return $locked;
            }

            return $this->finalizeWithinTransaction($locked, $status, $errorMessage, $result);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finalizeWithinTransaction(
        SeoArticleWpSyncJob $job,
        WpSyncJobStatus $status,
        ?string $errorMessage = null,
        array $result = [],
    ): SeoArticleWpSyncJob {
        $now = now();
        $job->forceFill([
            'status' => $status,
            'finished_at' => $now,
            'worker_id' => null,
            'locked_until' => null,
            'heartbeat_at' => $status === WpSyncJobStatus::Completed ? ($job->heartbeat_at ?? $now) : null,
            'error_message' => $errorMessage,
            'stage' => $status->value,
            'wp_post_id' => (int) ($result['wp_post_id'] ?? $result['wordpress_post_id'] ?? $job->wp_post_id ?? 0) ?: $job->wp_post_id,
            'wordpress_permalink' => (string) ($result['permalink'] ?? $result['wordpress_permalink'] ?? $job->wordpress_permalink ?? '') ?: $job->wordpress_permalink,
        ])->save();

        $article = SeoArticle::query()->find($job->article_id);
        if ($article instanceof SeoArticle) {
            // Eloquent path: RoutesArticleExtensionAttributes strips → WordpressArticleLinkWriter.
            $article->forceFill([
                'wp_sync_status' => self::ARTICLE_IDLE,
                'wp_sync_job_id' => null,
            ])->save();
            $this->projectMeta($article, $job);
        }

        return $job->fresh() ?? $job;
    }

    private function bindArticle(SeoArticle $article, SeoArticleWpSyncJob $job, WpSyncJobStatus $status): void
    {
        app(WordpressArticleLinkWriter::class)->upsert($article, [
            'sync_status' => $status->value,
            'sync_job_id' => (int) $job->id,
            'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
        ]);

        // Eloquent path: RoutesArticleExtensionAttributes strips columns → writer again (idempotent).
        $article->forceFill([
            'wp_sync_status' => $status->value,
            'wp_sync_job_id' => (int) $job->id,
        ])->save();
    }

    private function projectMeta(SeoArticle $article, SeoArticleWpSyncJob $job): void
    {
        $status = $job->status instanceof WpSyncJobStatus
            ? $job->status->value
            : (string) $job->status;

        $payload = [
            'operation' => 'wordpress_sync',
            'status' => $status,
            'stage' => (string) ($job->stage ?? $status),
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'heartbeat_at' => $job->heartbeat_at?->toIso8601String(),
            'locked_until' => $job->locked_until?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'error' => $job->error_message,
            'error_message' => $job->error_message,
            'wordpress_post_id' => $job->wp_post_id,
            'wordpress_permalink' => $job->wordpress_permalink,
            'request_id' => $job->request_id,
            'correlation_id' => $job->correlation_id,
            'worker_id' => $job->worker_id,
            'attempts' => (int) $job->attempts,
            'idempotency_key' => $job->idempotency_key,
            'job_id' => (int) $job->id,
            'sync_job_id' => (int) $job->id,
        ];

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => ArticleWpSyncQueueService::META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    private function clearLegacyMeta(SeoArticle $article): void
    {
        $article->articleMetas()
            ->whereIn('meta_key', [
                ArticleWpSyncQueueService::META_KEY,
                ArticleWpSyncQueueService::BUNDLE_META_KEY,
            ])
            ->delete();
    }
}
