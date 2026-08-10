<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\ArticleMediaState;
use Omnichannel\Addons\Publishing\Models\PublishingArticleState;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;

/**
 * Sectioned Article Editor read model — composition without ownership mixing.
 *
 * @phpstan-type SectionedPayload array{
 *   article_id: int,
 *   content: array<string, mixed>,
 *   media: array<string, mixed>|null,
 *   seo: array<string, mixed>|null,
 *   wordpress: array<string, mixed>|null,
 *   publishing: array<string, mixed>|null,
 *   project: array<string, mixed>|null
 * }
 */
final class ArticleEditorView
{
    /**
     * @return SectionedPayload
     */
    public function forArticle(SeoArticle $article): array
    {
        $article->loadMissing([
            'seoProfile',
            'wordpressLink',
            'featuredMediaState',
            'publishingState',
            'contentArchiveItem',
        ]);

        $id = (int) $article->getKey();

        return [
            'article_id' => $id,
            'content' => [
                'title' => (string) ($article->title ?? ''),
                'slug' => $article->slug,
                'excerpt' => $article->excerpt,
                'body' => $article->body,
                'editor_document' => $article->editor_document,
                'type' => $article->type,
                'status' => $article->status,
                'review_status' => $article->review_status,
                'document_version' => (int) ($article->document_version ?? 1),
                'language' => $article->language,
                'site_id' => (int) ($article->site_id ?? 0),
                'last_manual_saved_at' => optional($article->last_manual_saved_at)?->toIso8601String(),
                'last_ai_content_at' => optional($article->last_ai_content_at)?->toIso8601String(),
            ],
            'media' => $this->mediaSection($article->featuredMediaState),
            'seo' => $this->seoSection($article->seoProfile),
            'wordpress' => $this->wordpressSection($article->wordpressLink),
            'publishing' => $this->publishingSection($article->publishingState, (string) ($article->status ?? '')),
            'project' => $article->contentArchiveItem ? [
                'archived_at' => optional($article->contentArchiveItem->archived_at)?->toIso8601String(),
                'archived_by' => $article->contentArchiveItem->archived_by,
                'from_project_id' => $article->contentArchiveItem->from_project_id ?? null,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mediaSection(?ArticleMediaState $state): ?array
    {
        if ($state === null) {
            return [
                'featured' => null,
                'gallery' => null,
            ];
        }

        return [
            'featured' => [
                'media_id' => $state->media_id,
                'url' => $state->display_url,
                'status' => $state->status,
                'source' => $state->source,
            ],
            'gallery' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function seoSection(?SeoArticleProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        return [
            'seo_score' => $profile->seo_score,
            'skip_seo_score' => (bool) $profile->skip_seo_score,
            'internal_link_count' => (int) $profile->internal_link_count,
            'external_link_count' => (int) $profile->external_link_count,
            'indexed_at' => optional($profile->indexed_at)?->toIso8601String(),
            'previous_indexed_at' => optional($profile->previous_indexed_at)?->toIso8601String(),
        ];
    }

    /**
     * WordPress section = remote facts only.
     *
     * @return array<string, mixed>|null
     */
    private function wordpressSection(?WordpressArticleLink $link): ?array
    {
        if ($link === null) {
            return null;
        }

        return [
            'wp_post_id' => $link->wp_post_id,
            'sync_status' => $link->sync_status,
            'sync_job_id' => $link->sync_job_id,
            'last_synced_at' => optional($link->last_synced_at)?->toIso8601String(),
            'external_modified_at' => optional($link->external_modified_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publishingSection(?PublishingArticleState $state, string $contentStatus): ?array
    {
        if ($state === null) {
            return [
                'content_status' => $contentStatus,
                'published_at' => null,
                'publication_status' => null,
            ];
        }

        return [
            'content_status' => $contentStatus,
            'published_at' => optional($state->published_at)?->toIso8601String(),
            'publication_status' => $state->publication_status,
            'platform' => $state->platform,
        ];
    }
}
