<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Publishing\Services\ArticleScheduleReconcileService;
use Omnichannel\Addons\Seo\Support\SeoDisplayTimezone;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Illuminate\Support\Carbon;

/**
 * JSON patch trả về client sau REST save — không cần Livewire refresh.
 */
final class ArticleEditorSavePatchService
{
    public function __construct(
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly SeoArticleRevisionService $revisions,
        private readonly ArticleScheduleReconcileService $scheduleReconcile,
    ) {}

    /**
     * @param  array<string, mixed>|null  $seoAnalysis
     * @return array<string, mixed>
     */
    public function build(SeoArticle $article, ArticleEditorSaveContext $context, ?array $seoAnalysis = null): array
    {
        $article = $article->fresh() ?? $article;
        $article->loadMissing('articleMetas');

        $status = (string) ($article->status ?? 'draft');
        $updatedAtUtc = $article->updated_at instanceof Carbon
            ? $article->updated_at->copy()->utc()
            : SeoDisplayTimezone::now()->utc();
        $updatedAtDisplay = $article->updated_at instanceof Carbon
            ? $article->updated_at->copy()->timezone(SeoDisplayTimezone::name())
            : SeoDisplayTimezone::now();
        $publishedAtDisplay = $article->publishingState?->published_at instanceof Carbon
            ? $article->publishingState->published_at->copy()->timezone(SeoDisplayTimezone::name())
            : null;

        $postType = SeoProjectTask::normalizePostType(
            (string) ($article->type ?? ArticlePostTypeResolver::resolve($article)),
        );

        $publishWhenLabel = '';
        if ($status === 'scheduled' && $publishedAtDisplay instanceof Carbon) {
            $publishWhenLabel = $this->formatScheduleLabel($publishedAtDisplay);
        }

        $publishedAtSidebarLabel = null;
        if ($this->scheduleReconcile->shouldShowPublishedAtLabel($status, $article->publishingState?->published_at)) {
            $publishedAtSidebarLabel = $publishedAtDisplay instanceof Carbon
                ? $this->formatScheduleLabel($publishedAtDisplay)
                : null;
        }

        $article->loadMissing('user');
        $authorName = $article->user_id === null
            ? ''
            : trim((string) ($article->user?->display_name ?? $article->user?->email ?? ''));

        return [
            'article' => [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? ''),
                'slug' => (string) ($article->slug ?? ''),
                'status' => $status,
                'post_type' => $postType,
                'visibility' => $context->visibility,
                // Conflict token: luôn UTC ISO — khớp bootstrap expectedUpdatedAt.
                'updated_at' => $updatedAtUtc->toIso8601String(),
                'updated_at_label' => $updatedAtDisplay->format('d/m/Y H:i'),
                'published_at' => $publishedAtDisplay?->toIso8601String(),
                'seo_score' => $article->seoProfile?->seo_score !== null ? (float) $article->seoProfile->seo_score : null,
                'user_id' => $article->user_id !== null ? (int) $article->user_id : null,
                'author' => $authorName !== '' ? $authorName : null,
            ],
            'publish_box' => [
                'status' => $status,
                'post_type' => $postType,
                'visibility' => $context->visibility,
                'publish_day' => $context->publishDay,
                'publish_month' => $context->publishMonth,
                'publish_year' => $context->publishYear,
                'publish_hour' => $context->publishHour,
                'publish_minute' => $context->publishMinute,
                'publish_when_label' => $publishWhenLabel,
                'published_at_sidebar_label' => $publishedAtSidebarLabel,
                'show_publish_schedule_row' => $this->scheduleReconcile->shouldShowScheduleLabel($status),
                'status_label' => $this->statusLabel($status),
                'saved_at_label' => 'Đã lưu lúc '.$updatedAtDisplay->format('H:i:s'),
            ],
            'flags' => [
                'local_edit_pending' => $this->syncFlags->hasLocalEditPending($article),
                'wp_data_out_of_sync' => $this->syncFlags->hasDataOutOfSync($article),
                'body_media_sync_pending' => $this->syncFlags->hasBodyMediaSyncPending($article),
            ],
            'revision_count' => $this->revisions->countForArticle((int) $article->id),
            'seo_analysis' => is_array($seoAnalysis) ? $seoAnalysis : null,
            'seo_analysis_pending' => ! is_array($seoAnalysis) || ! array_key_exists('violations', $seoAnalysis),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
            default => 'Draft',
        };
    }

    private function formatScheduleLabel(Carbon $dt): string
    {
        return SeoDisplayTimezone::formatScheduleLabel($dt);
    }
}
