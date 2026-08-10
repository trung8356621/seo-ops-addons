<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Services\ProductReview\Data\ProductReviewReconciliationResult;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Post-sync reviews owned by SyncArticleToWordPressPipeline.
 * Keep class for ops/CLI references — no WordPress side effect.
 */
final class ProductReviewPostSyncReconciler
{
    public const RULE_CODE = 'publish-pending-product-reviews-after-article-sync';

    public function reconcileAfterArticleSynced(SeoArticle $article, ?int $actorId = null): ?ProductReviewReconciliationResult
    {
        Log::info('product_review.post_sync_reconcile.skipped', [
            'article_id' => (int) $article->id,
            'actor_id' => $actorId,
            'reason' => 'owned_by_sync_pipeline',
        ]);

        return new ProductReviewReconciliationResult(
            articleId: (int) $article->id,
            foundReviewIds: [],
            queuedReviewIds: [],
            outcome: 'SKIPPED_DEPRECATED',
            message: 'Skipped — product reviews owned by SyncArticleToWordPressPipeline.',
        );
    }
}
