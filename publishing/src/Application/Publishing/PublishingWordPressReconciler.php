<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use Omnichannel\Addons\WordPress\Enums\WpSyncJobStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncLeaseService;
use Omnichannel\Addons\WordPress\Services\SideEffect\SystemWordPressContext;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reconcile Content Project publish item with WordPress before any retry.
 * Never marks Published without WP evidence.
 */
final class PublishingWordPressReconciler
{
    public function __construct(
        private readonly WordPressArticleSyncService $syncService,
        private readonly ArticleWpSyncLeaseService $wpSyncLease,
        private readonly PublishOperationKeyFactory $operationKeys,
    ) {}

    public function reconcile(SeoProjectTask $task): PublishReconcileResult
    {
        $article = $task->relationLoaded('article')
            ? $task->article
            : SeoArticle::query()->find((int) ($task->article_id ?? 0));

        if (! $article instanceof SeoArticle) {
            return new PublishReconcileResult(
                PublishReconcileResult::OUTCOME_NOT_PUBLISHED,
                message: 'Task missing article.',
            );
        }

        $activeJob = $this->wpSyncLease->activeJobForArticle((int) $article->id);
        if ($activeJob instanceof SeoArticleWpSyncJob
            && in_array($activeJob->status, [WpSyncJobStatus::Pending, WpSyncJobStatus::Processing], true)
            && ! $this->wpSyncLease->isLeaseExpired($activeJob)
        ) {
            return new PublishReconcileResult(
                PublishReconcileResult::OUTCOME_IN_FLIGHT,
                wpPostId: (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                message: 'WordPress sync job still in flight.',
            );
        }

        $completedJob = SeoArticleWpSyncJob::query()
            ->where('article_id', (int) $article->id)
            ->where('status', WpSyncJobStatus::Completed)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->orderByDesc('id')
            ->first();
        if ($completedJob instanceof SeoArticleWpSyncJob) {
            $permalink = $this->storedPermalink($article);

            return new PublishReconcileResult(
                PublishReconcileResult::OUTCOME_PUBLISHED,
                wpPostId: (int) $completedJob->wp_post_id,
                permalink: $permalink !== '' ? $permalink : null,
                remoteStatus: 'publish',
                message: 'WP sync job completed with wp_post_id.',
            );
        }

        $fromAttempts = $this->findPublishedAttempt($task, $article);
        if ($fromAttempts !== null) {
            return $fromAttempts;
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId > 0) {
            $permalink = $this->storedPermalink($article);
            // Local wp_post_id alone is NOT enough — verify remote when possible.
            $remote = $this->probeRemote($article, $task);
            if ($remote->isPublished()) {
                return $remote;
            }
            if ($remote->outcome === PublishReconcileResult::OUTCOME_UNKNOWN) {
                // Probe unavailable: do not finalize from local wp_post_id alone.
                return new PublishReconcileResult(
                    PublishReconcileResult::OUTCOME_UNKNOWN,
                    wpPostId: $wpPostId,
                    permalink: $permalink !== '' ? $permalink : null,
                    message: 'Remote probe unavailable; refusing to finalize from local wp_post_id alone.',
                );
            }
        }

        return $this->probeRemote($article, $task);
    }

    private function findPublishedAttempt(SeoProjectTask $task, SeoArticle $article): ?PublishReconcileResult
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_publish_attempts')) {
            return null;
        }

        $opKey = trim((string) ($task->publish_operation_key ?? ''));
        $external = PublishAttemptRefs::forArticle((int) $article->id);

        $query = DB::connection('omi_seo_ai')->table('seo_content_project_publish_attempts')
            ->where('status', 'published')
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->orderByDesc('id');

        $row = (clone $query)->where('external_reference', $external)->first()
            ?? ($opKey !== '' ? (clone $query)->where('idempotency_key', $opKey)->first() : null);

        if ($row === null) {
            return null;
        }

        return new PublishReconcileResult(
            PublishReconcileResult::OUTCOME_PUBLISHED,
            wpPostId: (int) $row->wp_post_id,
            message: 'Publish attempt ledger confirms published.',
        );
    }

    private function probeRemote(SeoArticle $article, SeoProjectTask $task): PublishReconcileResult
    {
        try {
            $siteId = (int) ($article->site_id ?? $task->site_id ?? 0);
            if ($siteId <= 0) {
                return new PublishReconcileResult(
                    PublishReconcileResult::OUTCOME_UNKNOWN,
                    message: 'Missing site_id for WordPress probe.',
                );
            }

            $opKey = $this->operationKeys->forTask($task, $article);
            $sideEffect = new SystemWordPressContext(
                requestId: 'publish-reconcile-'.(int) $task->getKey(),
                articleId: (int) $article->id,
                siteId: $siteId,
                reason: 'publishing.reconcile',
                correlationId: $opKey,
            );

            /** @var array{found?: bool, wp_post_id?: int|null, permalink?: string, status?: string}|null $found */
            $found = $this->syncService->findPublishedPostForReconcile(
                $article,
                $sideEffect,
                $opKey,
            );

            if (! is_array($found) || ! ($found['found'] ?? false)) {
                return new PublishReconcileResult(
                    PublishReconcileResult::OUTCOME_NOT_PUBLISHED,
                    code: PublishReconcileResult::CODE_WP_PUBLISHED_POST_NOT_FOUND,
                    message: 'WordPress has no matching published post.',
                );
            }

            $wpPostId = (int) ($found['wp_post_id'] ?? 0);
            $status = strtolower(trim((string) ($found['status'] ?? '')));
            $permalink = trim((string) ($found['permalink'] ?? ''));

            if ($wpPostId <= 0) {
                return new PublishReconcileResult(
                    PublishReconcileResult::OUTCOME_NOT_PUBLISHED,
                    message: 'WordPress find returned empty wp_post_id.',
                );
            }

            // Accept publish / future as published evidence for queue finalize.
            if (! in_array($status, ['publish', 'future', 'private', ''], true) && $status !== '') {
                return new PublishReconcileResult(
                    PublishReconcileResult::OUTCOME_NOT_PUBLISHED,
                    wpPostId: $wpPostId,
                    permalink: $permalink !== '' ? $permalink : null,
                    remoteStatus: $status,
                    message: 'WordPress post exists but status='.$status,
                );
            }

            return new PublishReconcileResult(
                PublishReconcileResult::OUTCOME_PUBLISHED,
                wpPostId: $wpPostId,
                permalink: $permalink !== '' ? $permalink : null,
                remoteStatus: $status !== '' ? $status : 'publish',
                message: 'WordPress reconcile confirmed post.',
            );
        } catch (Throwable $e) {
            RuntimeLogger::warning('publishing.reconcile_probe_failed', [
                'task_id' => (int) $task->getKey(),
                'article_id' => (int) $article->id,
                'error' => $e->getMessage(),
            ]);

            return new PublishReconcileResult(
                PublishReconcileResult::OUTCOME_UNKNOWN,
                message: 'Reconcile probe failed: '.$e->getMessage(),
            );
        }
    }

    private function storedPermalink(SeoArticle $article): string
    {
        if ($article->relationLoaded('articleMetas')) {
            return trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
        }

        return trim((string) ($article->articleMetas()
            ->where('meta_key', 'wp_permalink')
            ->value('meta_value') ?? ''));
    }
}
