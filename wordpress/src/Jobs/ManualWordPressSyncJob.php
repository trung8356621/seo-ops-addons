<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Jobs;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncLeaseService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewAutomationSettingsResolver;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use Omnichannel\Addons\WordPress\Services\ManualSyncContext;
use Omnichannel\Addons\WordPress\Services\WpSyncLeaseHeartbeat;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manual WordPress sync — claim lease → heartbeat → terminal (complete/fail). Không để processing kẹt.
 */
final class ManualWordPressSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /** Prevent duplicate concurrent sync jobs for the same article. */
    public int $uniqueFor = 900;

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $auditMeta
     */
    public function __construct(
        public readonly int $articleId,
        public readonly int $userId,
        public readonly string $source,
        public readonly string $requestId,
        public readonly string $correlationId,
        public readonly int $domainId,
        public readonly string $requestedAt,
        public readonly int $syncJobId,
        public readonly array $settings = [],
        public readonly array $auditMeta = [],
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        // Per lease row — stale/dead worker must not block a new enqueue for same article.
        return 'manual-wp-sync:'.$this->articleId.':'.$this->syncJobId;
    }

    public function handle(
        ArticleWordPressBusinessSequence $sequence,
        BusinessHookEmitter $emitter,
        ArticleWpSyncLeaseService $lease,
        ProductReviewAutomationSettingsResolver $reviewSettingsResolver,
    ): void {
        $workerId = (string) Str::uuid();
        $claimed = $lease->claim($this->syncJobId, $workerId);
        if (! $claimed instanceof SeoArticleWpSyncJob) {
            Log::info('manual_wordpress_sync.claim_skipped', [
                'article_id' => $this->articleId,
                'sync_job_id' => $this->syncJobId,
                'request_id' => $this->requestId,
            ]);

            return;
        }

        $article = SeoArticle::query()->find($this->articleId);
        if (! $article instanceof SeoArticle) {
            $lease->fail($claimed, 'Article missing at claim time.');
            Log::warning('manual_wordpress_sync.article_missing', [
                'article_id' => $this->articleId,
                'sync_job_id' => $this->syncJobId,
                'request_id' => $this->requestId,
            ]);

            return;
        }

        $manual = new ManualSyncContext(
            initiatedBy: $this->userId,
            source: $this->source,
            articleId: $this->articleId,
            domainId: $this->domainId > 0 ? $this->domainId : (int) ($article->site_id ?? 0),
            correlationId: $this->correlationId,
            requestId: $this->requestId,
            requestedAt: $this->requestedAt,
            manual: true,
        );
        $sideEffect = $manual->toSideEffectContext('manual_sync:'.$this->source);
        $mode = (string) ($this->settings['mode'] ?? 'sync');
        /** @var array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null $seoOverride */
        $seoOverride = is_array($this->settings['seo_override'] ?? null)
            ? $this->settings['seo_override']
            : null;
        $slug = (string) ($this->settings['slug'] ?? $article->slug ?? '');
        $syncProductReviews = ($this->settings['sync_product_reviews'] ?? true) !== false;
        $reviewSettings = $reviewSettingsResolver->resolve(
            is_array($this->settings['product_review'] ?? null) ? $this->settings['product_review'] : [],
        );

        WpSyncLeaseHeartbeat::bind($claimed, $lease, ArticleWpSyncLeaseService::HEARTBEAT_INTERVAL_SECONDS);
        WpSyncLeaseHeartbeat::touch(force: true);

        try {
            $result = SeoQueueContext::runWpSyncFromQueue(function () use (
                $sequence,
                $mode,
                $article,
                $sideEffect,
                $seoOverride,
                $slug,
                $syncProductReviews,
                $reviewSettings,
            ): array {
                WpSyncLeaseHeartbeat::touch();

                return $sequence->run(
                    $article,
                    $sideEffect,
                    $mode,
                    $seoOverride,
                    $slug,
                    $syncProductReviews,
                    $reviewSettings,
                );
            });
        } catch (Throwable $e) {
            report($e);
            WpSyncLeaseHeartbeat::clear();
            if ($this->attempts() >= $this->tries) {
                $lease->fail($claimed, $e->getMessage());
                $emitter->wordpressSyncFailed($article, $e->getMessage(), $manual->toAuditMeta());
            } else {
                $lease->releaseForRetry($claimed);
            }
            throw $e;
        }

        WpSyncLeaseHeartbeat::clear();
        $article = $article->fresh() ?? $article;

        if (! ($result['success'] ?? false)) {
            $message = (string) ($result['message'] ?? 'WordPress sync failed.');
            $lease->fail($claimed, $message);
            $emitter->wordpressSyncFailed($article, $message, $manual->toAuditMeta());

            return;
        }

        $lease->complete($claimed, [
            'message' => (string) ($result['message'] ?? 'synced'),
            'wp_post_id' => (int) ($result['wp_post_id'] ?? $article->wordpressLink?->wp_post_id ?? 0) ?: null,
            'permalink' => (string) ($result['permalink'] ?? $article->permalink ?? '') ?: null,
            'origin' => 'manual',
        ]);

        $emitter->wordpressSyncedOnce($article, $this->requestId, [
            'wp_post_id' => (int) ($result['wp_post_id'] ?? $article->wordpressLink?->wp_post_id ?? 0) ?: null,
            'message' => (string) ($result['message'] ?? 'synced'),
            'origin' => 'manual',
            'manual' => true,
            'source' => $this->source,
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'sync_job_id' => $this->syncJobId,
            'product_review_create' => $result['product_review_create'] ?? null,
            'product_review_sync' => $result['product_review_sync'] ?? null,
        ], $manual->toAuditMeta());

        Log::info('manual_wordpress_sync.completed', array_merge($manual->toAuditMeta(), [
            'mode' => $mode,
            'sync_job_id' => $this->syncJobId,
            'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
        ]));
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $lease = app(ArticleWpSyncLeaseService::class);
            $job = $lease->find($this->syncJobId);
            if ($job instanceof SeoArticleWpSyncJob && $job->isActive()) {
                $lease->fail($job, $exception?->getMessage() ?? 'Queue worker failed permanently.');
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            WpSyncLeaseHeartbeat::clear();
        }
    }
}
