<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Jobs;

use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Body media follow-up must run inside wordpress.article.sync Automation Action.
 */
final class SyncArticleBodyMediaToWordPressJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     */
    public function __construct(
        public int $articleId,
        public ?array $seoOverride = null,
        public int $manualUserId = 0,
        public string $manualRequestId = '',
        public string $manualReason = 'body_media_followup',
        public string $manualCorrelationId = '',
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(): void
    {
        Log::channel('wordpress-side-effect')->error('wordpress.side_effect.blocked', [
            'operation' => 'article.body_media_sync.legacy',
            'article_id' => $this->articleId,
            'queue_job_class' => self::class,
            'message' => 'DEPRECATED: SyncArticleBodyMediaToWordPressJob disabled — body media owned by wordpress.article.sync.',
        ]);
    }
}
