<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Legacy delayed publish. Safe no-op — reviews sync via SyncArticleToWordPressPipeline.
 */
final class DispatchScheduledProductReviewPublishJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $settingsSnapshot
     */
    public function __construct(
        public readonly int $reviewId,
        public readonly int $articleId,
        public readonly int $siteId,
        public readonly int $connectionId,
        public readonly string $publishIntent,
        public readonly array $settingsSnapshot = [],
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('automation-external');
    }

    public function handle(): void
    {
        Log::info('product_review.legacy_delayed_job.skipped', [
            'review_id' => $this->reviewId,
            'article_id' => $this->articleId,
            'reason' => 'owned_by_sync_pipeline',
        ]);
    }
}
