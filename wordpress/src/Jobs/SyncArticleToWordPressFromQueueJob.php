<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Jobs;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorSyncOrchestrator;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Legacy seo-queue orchestration. New clicks must use WordPressManualSyncService.
 * Remaining queued jobs fail closed without WordPress side effects.
 */
final class SyncArticleToWordPressFromQueueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public int $articleId,
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ArticleWpSyncQueueService $queueService,
        ArticleEditorSyncOrchestrator $syncOrchestrator,
    ): void {
        unset($syncOrchestrator);
        $databaseConnection->bootstrapLegacySharedConnection();

        $article = SeoArticle::query()->find($this->articleId);
        $message = 'DEPRECATED: SyncArticleToWordPressFromQueueJob disabled. Use WordPressManualSyncService → ManualWordPressSyncJob.';
        Log::channel('wordpress-side-effect')->error('wordpress.side_effect.blocked', [
            'operation' => 'queue.sync_article.legacy',
            'article_id' => $this->articleId,
            'queue_job_class' => self::class,
            'message' => $message,
        ]);

        if ($article instanceof SeoArticle) {
            $queueService->markFailed($article, $message);
        }
    }
}
