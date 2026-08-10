<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\SearchFoundation\Models\SeoPendingInternalLink;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn pending artifacts chỉ phục vụ AI workflow (link gợi ý, product review local pending).
 */
final class PendingArtifactsWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'pending_artifacts';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $articleIds = $context->articleIds();

        if (Schema::connection('omi_seo_ai')->hasTable('seo_pending_internal_links')) {
            $deletedLinks = SeoPendingInternalLink::query()
                ->whereIn('source_article_id', $articleIds)
                ->where(function ($query): void {
                    $query->whereNull('status')
                        ->orWhere('status', 'pending')
                        ->orWhere('status', 'suggested');
                })
                ->delete();
            $context->bumpStat('pending_internal_links_deleted', (int) $deletedLinks);
        }

        $deletedLocal = ArticleProductReview::query()
            ->whereIn('article_id', $articleIds)
            ->whereIn('status', [
                ArticleProductReviewStatus::Pending->value,
                ArticleProductReviewStatus::Draft->value,
                ArticleProductReviewStatus::PendingArticle->value,
                ArticleProductReviewStatus::Failed->value,
                ArticleProductReviewStatus::Syncing->value,
            ])
            ->delete();
        $context->bumpStat('pending_product_reviews_deleted', (int) $deletedLocal);
    }
}
