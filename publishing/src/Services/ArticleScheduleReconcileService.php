<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Carbon;

/**
 * Editor-load reconcile: Laravel status only.
 * Never calls WordPress — automatic WP side effects require Automation Engine.
 */
final class ArticleScheduleReconcileService
{
    /**
     * Đồng bộ trạng thái lên lịch khi mở editor (Laravel only).
     * Quá hạn scheduled + chưa có WP post → mark published local.
     * Có WP post → không auto-publish WP; user/manual hoặc automation rule phụ trách.
     */
    public function reconcileForEditor(SeoArticle $article): bool
    {
        $article->refresh();

        if ((string) ($article->status ?? '') !== 'scheduled') {
            return false;
        }

        if (! $article->publishingState?->published_at instanceof Carbon || $article->publishingState->published_at->isFuture()) {
            return false;
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId > 0) {
            // Local clock already due; WP flip left to ScheduledArticlePublishRunner (system cron)
            // or explicit manual/automation — never silent outbound from editor open.
            return false;
        }

        $article->update(['status' => 'published']);
        $article->refresh();

        return true;
    }

    public function shouldShowScheduleLabel(string $status): bool
    {
        return $status === 'scheduled';
    }

    public function shouldShowPublishedAtLabel(string $status, ?Carbon $publishedAt): bool
    {
        return $status === 'published' && $publishedAt instanceof Carbon;
    }
}
