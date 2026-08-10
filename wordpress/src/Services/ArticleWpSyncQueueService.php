<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\WordPress\Enums\WpSyncJobStatus;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use Omnichannel\Addons\WordPress\Jobs\SyncArticleToWordPressFromQueueJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Seo\Support\SeoDisplayTimezone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Testing\Fakes\BusFake;
use Throwable;

final class ArticleWpSyncQueueService
{
    public const QUEUE_NAME = 'seo';

    public const META_KEY = 'wp_sync_queue';

    public const BUNDLE_META_KEY = 'wp_sync_queue_bundle';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_STALE = 'stale';

    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly ArticleWpSyncLeaseService $lease,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{success: bool, message: string, queued?: bool, queue?: array<string, mixed>}
     */
    public function enqueueFromEditorBundle(SeoArticle $article, array $bundle, ?ManualWordPressContext $manual = null): array
    {
        return [
            'success' => false,
            'message' => 'Legacy seo queue orchestration removed. Use WordPressManualSyncService / ManualWordPressSyncJob.',
        ];
    }

    public function markProcessing(SeoArticle $article): void
    {
        $job = $this->lease->activeJobForArticle((int) $article->id);
        if ($job instanceof SeoArticleWpSyncJob && $job->status === WpSyncJobStatus::Pending) {
            $this->lease->claim((int) $job->id);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function markQueued(SeoArticle $article, array $meta = []): array
    {
        $job = $this->lease->enqueue(
            article: $article,
            source: (string) ($meta['source'] ?? 'editor'),
            initiatedBy: (int) ($meta['initiated_by'] ?? 0),
            requestId: (string) ($meta['request_id'] ?? ''),
            correlationId: (string) ($meta['correlation_id'] ?? ''),
            settings: [
                'mode' => (string) ($meta['mode'] ?? 'sync'),
            ],
            auditMeta: $meta,
        );

        return $this->lease->toOperationPayload($job);
    }

    /**
     * Status còn trong Sync Queue badge / tab (chưa sync xong thành công).
     * Single source — badge count và list scope phải dùng chung.
     *
     * @return list<string>
     */
    public static function unfinishedStatuses(): array
    {
        return WpSyncJobStatus::unfinishedValues();
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $statusQuery
     */
    public static function applyUnfinishedMetaStatusConstraints($statusQuery): void
    {
        $statuses = self::unfinishedStatuses();
        foreach ($statuses as $index => $status) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $statusQuery->{$method}('meta_value', 'like', '%"status":"'.$status.'"%');
        }
    }

    public function isActive(SeoArticle $article): bool
    {
        $job = $this->lease->activeJobForArticle((int) $article->id);
        if ($job instanceof SeoArticleWpSyncJob) {
            if ($this->lease->isLeaseExpired($job)) {
                $this->lease->markStale($job, 'Active lease expired on isActive check');
                $this->lease->releaseArticleCacheLocks((int) $article->id);

                // Auto-retry có thể đã enqueue job mới — coi là vẫn active.
                return $this->lease->activeJobForArticle((int) $article->id) instanceof SeoArticleWpSyncJob;
            }

            return true;
        }

        // Meta cũ processing/pending không có lease → heal thành stale, không khóa sync.
        $this->lease->healArticleOrphanMeta($article);

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeOperation(SeoArticle $article): ?array
    {
        $job = $this->lease->activeJobForArticle((int) $article->id);
        if ($job instanceof SeoArticleWpSyncJob && $this->lease->isLeaseExpired($job)) {
            $this->lease->markStale($job, 'Active lease expired on activeOperation poll');
            $this->lease->releaseArticleCacheLocks((int) $article->id);

            $retried = $this->lease->activeJobForArticle((int) $article->id);
            if ($retried instanceof SeoArticleWpSyncJob) {
                return $this->lease->toOperationPayload($retried);
            }

            $job = $job->fresh();

            return $job instanceof SeoArticleWpSyncJob
                ? $this->lease->toOperationPayload($job)
                : null;
        }

        $healed = $this->lease->healArticleOrphanMeta($article);
        $job = $this->lease->activeJobForArticle((int) $article->id);
        if ($job instanceof SeoArticleWpSyncJob) {
            return $this->lease->toOperationPayload($job);
        }

        if (is_array($healed)) {
            $status = WpSyncJobStatus::tryFrom((string) ($healed['status'] ?? '')) ?? WpSyncJobStatus::Stale;

            return [
                'id' => (string) ($healed['request_id'] ?? ('wp-sync-meta-'.(int) $article->id)),
                'job_id' => (int) ($healed['job_id'] ?? $healed['sync_job_id'] ?? 0) ?: null,
                'article_id' => (int) $article->id,
                'operation' => 'wordpress_sync',
                'type' => 'wordpress_sync',
                'status' => $status->toPublicStatus(),
                'raw_status' => $status->value,
                'stage' => (string) ($healed['stage'] ?? $status->value),
                'error_message' => (string) ($healed['error_message'] ?? $healed['error'] ?? ''),
                'wordpress_post_id' => (int) ($healed['wordpress_post_id'] ?? 0) ?: null,
                'wordpress_permalink' => (string) ($healed['wordpress_permalink'] ?? '') ?: null,
                'queued_at' => $healed['queued_at'] ?? null,
                'started_at' => $healed['started_at'] ?? null,
                'finished_at' => $healed['finished_at'] ?? null,
                'worker_id' => null,
                'attempts' => (int) ($healed['attempts'] ?? 0),
                'request_id' => $healed['request_id'] ?? null,
                'correlation_id' => $healed['correlation_id'] ?? null,
            ];
        }

        $this->databaseConnection->bootstrapLegacySharedConnection();
        try {
            $latest = SeoArticleWpSyncJob::query()
                ->where('article_id', (int) $article->id)
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            $latest = null;
        }

        return $latest instanceof SeoArticleWpSyncJob
            ? $this->lease->toOperationPayload($latest)
            : null;
    }

    private function mapPublicStatus(string $status): string
    {
        $enum = WpSyncJobStatus::tryFrom($status);

        return $enum?->toPublicStatus() ?? $status;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markFailed(SeoArticle $article, string $error, bool $emitFailedEvent = true): void
    {
        $job = $this->resolveJobForArticle($article);
        if ($job instanceof SeoArticleWpSyncJob) {
            $this->lease->fail($job, $error);
        } else {
            // Không có lease job — vẫn bắt buộc update meta wp_sync_queue → failed.
            $this->writeTerminalMetaFallback($article, self::STATUS_FAILED, $error);
        }

        if (! $emitFailedEvent) {
            return;
        }

        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->wordpressSyncFailed($article, $error);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markCompleted(SeoArticle $article, array $result = [], bool $emitSyncedEvent = true): void
    {
        $job = $this->resolveJobForArticle($article);
        if ($job instanceof SeoArticleWpSyncJob) {
            $this->lease->complete($job, $result);
        } else {
            $this->writeTerminalMetaFallback(
                $article,
                self::STATUS_COMPLETED,
                null,
                $result,
            );
        }

        $article = $this->bootstrapArticleDatabase($article);
        $article->articleMetas()->where('meta_key', self::BUNDLE_META_KEY)->delete();

        if (! $emitSyncedEvent) {
            return;
        }

        $emitResult = is_array($result) ? $result : [];
        $emitResult['origin'] = $emitResult['origin'] ?? 'legacy_queue';
        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
            ->wordpressSynced($article, $emitResult);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeTerminalMetaFallback(
        SeoArticle $article,
        string $status,
        ?string $error,
        array $result = [],
    ): void {
        $article = $this->bootstrapArticleDatabase($article);
        $payload = $this->readQueueMeta($article);
        $now = now()->toIso8601String();
        $payload = array_merge($payload !== [] ? $payload : [
            'operation' => 'wordpress_sync',
            'queued_at' => $now,
        ], [
            'status' => $status,
            'stage' => $status,
            'finished_at' => $now,
            'error' => $error,
            'error_message' => $error,
            'worker_id' => null,
            'locked_until' => null,
            'heartbeat_at' => null,
            'wordpress_post_id' => (int) ($result['wp_post_id'] ?? $result['wordpress_post_id'] ?? $article->wordpressLink?->wp_post_id ?? 0) ?: null,
            'wordpress_permalink' => (string) ($result['permalink'] ?? $result['wordpress_permalink'] ?? '') ?: null,
        ]);

        $this->writeQueueMeta($article, $payload);
    }

    public function clearQueueEntry(SeoArticle $article): void
    {
        $this->lease->resetArticle($article, 'Queue entry cleared');
        // reset xóa meta; nếu vẫn còn orphan (reset không thấy active job) → heal.
        $this->lease->healArticleOrphanMeta($article);
        $this->purgeDispatchedJobsForArticle((int) $article->id);
    }

    public function retry(SeoArticle $article): bool
    {
        $status = (string) ($this->readQueueMeta($article)['status'] ?? '');
        if ($status !== self::STATUS_FAILED && $status !== self::STATUS_STALE && $status !== self::STATUS_CANCELLED) {
            return false;
        }

        return ($this->resync($article)['success'] ?? false);
    }

    /**
     * @return array{success: bool, message: string, queued?: bool}
     */
    public function resync(SeoArticle $article, ?ManualWordPressContext $manual = null): array
    {
        return [
            'success' => false,
            'message' => 'Legacy seo queue resync removed. Use WordPressManualSyncService / ManualWordPressSyncJob.',
        ];
    }

    public function cancel(SeoArticle $article): bool
    {
        $this->lease->forceUnlockArticle($article, 'Cancelled manually');
        $this->purgeDispatchedJobsForArticle((int) $article->id);

        return true;
    }

    private function resolveJobForArticle(SeoArticle $article): ?SeoArticleWpSyncJob
    {
        $active = $this->lease->activeJobForArticle((int) $article->id);
        if ($active instanceof SeoArticleWpSyncJob) {
            return $active;
        }

        $jobId = (int) ($article->wordpressLink?->sync_job_id ?? 0);
        if ($jobId <= 0) {
            $meta = $this->readQueueMeta($article);
            $jobId = (int) ($meta['job_id'] ?? $meta['sync_job_id'] ?? 0);
        }

        return $jobId > 0 ? $this->lease->find($jobId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function readQueueMeta(SeoArticle $article): array
    {
        $raw = $this->readQueueMetaRaw($article);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function readQueueBundle(SeoArticle $article): array
    {
        $raw = $this->readQueueBundleRaw($article);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function queueStatusLabel(SeoArticle $article): ?string
    {
        $status = (string) ($this->readQueueMeta($article)['status'] ?? '');
        if ($status === '') {
            return null;
        }

        return match ($status) {
            self::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
            self::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
            self::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
            self::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
            self::STATUS_CANCELLED => __('seo-content-ai::filament.article_list.queue_status_cancelled'),
            self::STATUS_STALE => __('seo-content-ai::filament.article_list.queue_status_stale'),
            default => $status,
        };
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function prepareBundleForImmediateSync(array $bundle): array
    {
        $now = SeoDisplayTimezone::now();
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $bundle['publish_box'] = array_merge($publishBox, [
            'publish_immediately' => false,
            'status' => 'published',
            'publish_day' => $now->format('d'),
            'publish_month' => $now->format('m'),
            'publish_year' => $now->format('Y'),
            'publish_hour' => $now->format('H'),
            'publish_minute' => $now->format('i'),
        ]);

        return $bundle;
    }

    /**
     * Đăng ngay: ép publish_box.status = published (đè draft/scheduled cũ).
     * Trả về bundle mới — caller phải gán lại (PHP array truyền theo copy).
     *
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function applyPublishImmediatelyToBundle(array $bundle): array
    {
        if (! $this->resolvePublishImmediately($bundle)) {
            return $bundle;
        }

        $now = SeoDisplayTimezone::now();
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $bundle['publish_box'] = array_merge($publishBox, [
            'publish_immediately' => true,
            'status' => 'published',
            'publish_day' => $now->format('d'),
            'publish_month' => $now->format('m'),
            'publish_year' => $now->format('Y'),
            'publish_hour' => $now->format('H'),
            'publish_minute' => $now->format('i'),
        ]);

        return $bundle;
    }

    private function bootstrapArticleDatabase(SeoArticle $article): SeoArticle
    {
        $this->databaseConnection->bootstrapLegacySharedConnection();

        $article = $article->fresh() ?? $article;
        if ((int) ($article->site_id ?? 0) <= 0) {
            return $article;
        }

        $this->databaseConnection->bootstrapSeoDatabaseConnection((int) $article->site_id);
        $fresh = SeoArticle::query()->find($article->getKey());

        return $fresh instanceof SeoArticle ? $fresh : $article;
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function resolvePublishImmediately(array $bundle): bool
    {
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        return filter_var($publishBox['publish_immediately'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function compactBundleForQueue(array $bundle): array
    {
        return [
            'html' => (string) ($bundle['html'] ?? ''),
            'seo_analysis' => $bundle['seo_analysis'] ?? null,
            'article_meta' => is_array($bundle['article_meta'] ?? null) ? $bundle['article_meta'] : [],
            'publish_box' => is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [],
            'category_ids' => $bundle['category_ids'] ?? null,
            'featured_image' => $bundle['featured_image'] ?? null,
            'product_album' => $bundle['product_album'] ?? null,
            'faqs' => $bundle['faqs'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeQueueMeta(SeoArticle $article, array $payload): void
    {
        $article = $this->bootstrapArticleDatabase($article);
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    private function readQueueMetaRaw(SeoArticle $article): string
    {
        if (isset($article->wp_sync_queue_meta) && is_string($article->wp_sync_queue_meta)) {
            return trim($article->wp_sync_queue_meta);
        }

        $article = $this->bootstrapArticleDatabase($article);
        $article->unsetRelation('articleMetas');

        return trim((string) $article->articleMetas()
            ->where('meta_key', self::META_KEY)
            ->value('meta_value') ?? '');
    }

    private function readQueueBundleRaw(SeoArticle $article): string
    {
        if (isset($article->wp_sync_queue_bundle) && is_string($article->wp_sync_queue_bundle)) {
            return trim($article->wp_sync_queue_bundle);
        }

        $article = $this->bootstrapArticleDatabase($article);
        $article->unsetRelation('articleMetas');

        return trim((string) $article->articleMetas()
            ->where('meta_key', self::BUNDLE_META_KEY)
            ->value('meta_value') ?? '');
    }

    public function purgeDispatchedJobsForArticle(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        try {
            $connection = $this->jobsConnection();

            foreach (
                DB::connection($connection)
                    ->table('jobs')
                    ->select(['id', 'payload'])
                    ->where(function ($query): void {
                        $query->where('payload', 'like', '%ManualWordPressSyncJob%')
                            ->orWhere('payload', 'like', '%SyncArticleToWordPressFromQueueJob%');
                    })
                    ->cursor() as $job
            ) {
                if ($this->extractArticleIdFromJobPayload((string) ($job->payload ?? '')) !== $articleId) {
                    continue;
                }

                DB::connection($connection)->table('jobs')->where('id', $job->id)->delete();
            }
        } catch (Throwable) {
            // Queue table may be unavailable in some environments.
        }
    }

    private function dispatchWpSyncJob(int $articleId): bool
    {
        Log::warning('wordpress.legacy_queue.dispatch_blocked', [
            'article_id' => $articleId,
            'message' => 'SyncArticleToWordPressFromQueueJob dispatch disabled — use WordPressManualSyncService.',
        ]);

        return false;
    }

    /**
     * Job đang chờ trong bảng `jobs` (queue `seo`) cho article — Manual hoặc legacy.
     */
    public function hasPendingWpSyncJob(int $articleId): bool
    {
        if ($articleId <= 0) {
            return false;
        }

        try {
            $jobs = DB::connection($this->jobsConnection())
                ->table('jobs')
                ->select(['payload'])
                ->where('queue', self::QUEUE_NAME)
                ->where(function ($query): void {
                    $query->where('payload', 'like', '%ManualWordPressSyncJob%')
                        ->orWhere('payload', 'like', '%SyncArticleToWordPressFromQueueJob%');
                })
                ->get();

            foreach ($jobs as $job) {
                if ($this->extractArticleIdFromJobPayload((string) ($job->payload ?? '')) === $articleId) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Không inspect được jobs → coi như còn job để tránh clear nhầm.
            return true;
        }

        return false;
    }

    public function extractArticleIdFromJobPayload(string $payload): ?int
    {
        if (preg_match('/s:\d+:"articleId";i:(\d+)/', $payload, $matches) === 1) {
            $articleId = (int) $matches[1];

            return $articleId > 0 ? $articleId : null;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        $command = $decoded['data']['command'] ?? null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        $job = @unserialize($command, [
            'allowed_classes' => [
                ManualWordPressSyncJob::class,
                SyncArticleToWordPressFromQueueJob::class,
            ],
        ]);
        if ($job instanceof ManualWordPressSyncJob) {
            return $job->articleId > 0 ? $job->articleId : null;
        }
        if ($job instanceof SyncArticleToWordPressFromQueueJob) {
            return $job->articleId > 0 ? $job->articleId : null;
        }

        return null;
    }

    private function jobsConnection(): string
    {
        $connection = (string) config('queue.connections.'.config('queue.default').'.connection');

        return $connection !== '' ? $connection : (string) config('database.default');
    }
}
