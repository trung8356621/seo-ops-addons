<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemsReader;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Bulk WordPress sync for completed Content Project Run items (manual, queued).
 */
final class ContentProjectRunBulkSyncService
{
    public function __construct(
        private readonly SeoProjectRunItemsReader $runItemsReader,
        private readonly ArticleWpSyncQueueService $syncQueue,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly WordPressManualSyncService $manualSync,
    ) {}

    /**
     * @return array{queued: int, skipped: int, deduplicated: int, article_ids: list<int>}
     */
    public function dispatchEligibleArticles(SeoProjectRun $run, User $actor): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);

        $lock = Cache::lock('content-project-run-bulk-sync:'.(int) $run->id, 120);
        if (! $lock->get()) {
            return ['queued' => 0, 'skipped' => 0, 'deduplicated' => 0, 'article_ids' => []];
        }

        try {
            $queued = 0;
            $skipped = 0;
            $deduplicated = 0;
            $articleIds = [];

            foreach ($this->runItemsReader->forRunAsArrays($run) as $item) {
                $articleId = (int) ($item['article_id'] ?? 0);
                $status = (string) ($item['status'] ?? '');

                if ($status !== 'success' || $articleId <= 0) {
                    $skipped++;

                    continue;
                }

                $article = SeoArticle::query()->find($articleId);
                if (! $article instanceof SeoArticle) {
                    $skipped++;

                    continue;
                }

                if (! SeoAccessControl::canAccessArticle($article)) {
                    $skipped++;

                    continue;
                }

                if (! $this->isEligible($article)) {
                    $skipped++;

                    continue;
                }

                $result = $this->manualSync->resyncQueued(
                    $article,
                    $actor,
                    'content_project_run_bulk_sync',
                );

                if (($result['success'] ?? false) && ($result['queued'] ?? false)) {
                    $queued++;
                    $articleIds[] = $articleId;

                    continue;
                }

                if (($result['status'] ?? '') === 'deduplicated') {
                    $deduplicated++;
                    $skipped++;

                    continue;
                }

                $skipped++;
            }

            return [
                'queued' => $queued,
                'skipped' => $skipped,
                'deduplicated' => $deduplicated,
                'article_ids' => $articleIds,
            ];
        } finally {
            $lock->release();
        }
    }

    public function isEligible(SeoArticle $article): bool
    {
        if ($this->syncQueue->isActive($article)) {
            return false;
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return true;
        }

        if ($this->syncFlags->hasLocalEditPending($article)) {
            return true;
        }

        if ($this->syncFlags->hasBodyMediaSyncPending($article)) {
            return true;
        }

        if ($this->syncFlags->hasDataOutOfSync($article)) {
            return true;
        }

        $meta = $this->syncQueue->activeOperation($article);
        if ($meta === null) {
            return true;
        }

        $rawStatus = (string) ($meta['raw_status'] ?? '');
        if ($rawStatus === ArticleWpSyncQueueService::STATUS_FAILED) {
            return true;
        }

        return false;
    }

    public function countEligible(SeoProjectRun $run): int
    {
        $count = 0;
        foreach ($this->runItemsReader->forRunAsArrays($run) as $item) {
            if ((string) ($item['status'] ?? '') !== 'success') {
                continue;
            }

            $articleId = (int) ($item['article_id'] ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $article = SeoArticle::query()->find($articleId);
            if ($article instanceof SeoArticle && $this->isEligible($article)) {
                $count++;
            }
        }

        return $count;
    }
}
