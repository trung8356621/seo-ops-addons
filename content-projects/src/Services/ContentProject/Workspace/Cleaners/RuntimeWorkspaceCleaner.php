<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\WordPress\Models\WordPressSideEffectAttempt;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;

/**
 * Dọn Queue State / Sync Lease / Fingerprint / Runtime meta.
 */
final class RuntimeWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'runtime';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $articleIds = $context->articleIds();

        $deletedJobs = SeoArticleWpSyncJob::query()
            ->whereIn('article_id', $articleIds)
            ->delete();
        $context->bumpStat('wp_sync_jobs_deleted', (int) $deletedJobs);

        $deletedAttempts = WordPressSideEffectAttempt::query()
            ->whereIn('article_id', $articleIds)
            ->delete();
        $context->bumpStat('side_effect_attempts_deleted', (int) $deletedAttempts);

        $metaKeys = [
            ArticleWpSyncQueueService::META_KEY,
            ArticleWpSyncQueueService::BUNDLE_META_KEY,
            WordPressArticleSyncService::META_WP_EDITOR_SYNC_FINGERPRINT,
            WordPressArticleSyncService::META_WP_LOCAL_SAVE_FINGERPRINT,
        ];

        $deletedMeta = ArticleMeta::query()
            ->whereIn('article_id', $articleIds)
            ->whereIn('meta_key', $metaKeys)
            ->delete();
        $context->bumpStat('runtime_metas_deleted', (int) $deletedMeta);

        $clearedAi = SeoArticle::query()
            ->whereIn('id', $articleIds)
            ->update(['last_ai_content_at' => null]);
        $context->bumpStat('articles_ai_timestamp_cleared', (int) $clearedAi);
    }
}
