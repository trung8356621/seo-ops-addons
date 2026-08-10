<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models\Concerns;

use Omnichannel\Addons\Media\Services\ArticleMediaStateWriter;
use Omnichannel\Addons\Publishing\Services\PublishingArticleStateWriter;
use Omnichannel\Addons\Seo\Services\SeoArticleProfileWriter;
use Omnichannel\Addons\WordPress\Services\WordpressArticleLinkWriter;

/**
 * Task 5/6: route legacy addon attribute writes to owner tables (seo_article_profiles,
 * wordpress_article_links, publishing_article_states, article_media_states) after the
 * addon columns were dropped from `articles`. Read accessors were removed in Task 6 —
 * all reads must go through the owner relations directly (`$article->seoProfile`,
 * `$article->wordpressLink`, `$article->publishingState`, `$article->featuredMediaState`,
 * `$article->contentArchiveItem`). Kept only as a forceFill/mass-assignment write safety
 * net for any dual-write attrs that still arrive under legacy keys.
 */
trait RoutesArticleExtensionAttributes
{
    /** @var array<string, mixed> */
    private array $pendingExtensionWrites = [];

    protected static function bootRoutesArticleExtensionAttributes(): void
    {
        static::saving(static function (self $article): void {
            $article->captureAndStripExtensionAttributes();
        });

        static::saved(static function (self $article): void {
            $article->flushPendingExtensionWrites();
        });
    }

    /**
     * @return list<string>
     */
    protected function extensionAttributeKeys(): array
    {
        return [
            'seo_score',
            'skip_seo_score',
            'internal_link_count',
            'external_link_count',
            'indexed_at',
            'previous_indexed_at',
            'wp_post_id',
            'wp_sync_status',
            'wp_sync_job_id',
            'last_synced_at',
            'published_at',
            'featured_thumb_url',
            'featured_media_id',
            'featured_image_status',
            'featured_image_source',
            'content_archived_at',
            'content_archived_by',
        ];
    }

    protected function captureAndStripExtensionAttributes(): void
    {
        foreach ($this->extensionAttributeKeys() as $key) {
            if (! $this->isDirty($key)) {
                continue;
            }
            $this->pendingExtensionWrites[$key] = $this->getAttribute($key);
            unset($this->attributes[$key], $this->original[$key]);
            if (property_exists($this, 'changes') && is_array($this->changes ?? null)) {
                unset($this->changes[$key]);
            }
        }
    }

    protected function flushPendingExtensionWrites(): void
    {
        if ($this->pendingExtensionWrites === [] || (int) $this->getKey() <= 0) {
            $this->pendingExtensionWrites = [];

            return;
        }

        $w = $this->pendingExtensionWrites;
        $this->pendingExtensionWrites = [];

        $seo = array_intersect_key($w, array_flip([
            'seo_score', 'skip_seo_score', 'internal_link_count', 'external_link_count',
            'indexed_at', 'previous_indexed_at',
        ]));
        if ($seo !== []) {
            app(SeoArticleProfileWriter::class)->upsert($this, $seo);
        }

        $wp = [];
        if (array_key_exists('wp_post_id', $w)) {
            $wp['wp_post_id'] = $w['wp_post_id'];
        }
        if (array_key_exists('wp_sync_status', $w)) {
            $wp['sync_status'] = $w['wp_sync_status'];
        }
        if (array_key_exists('wp_sync_job_id', $w)) {
            $wp['sync_job_id'] = $w['wp_sync_job_id'];
        }
        if (array_key_exists('last_synced_at', $w)) {
            $wp['last_synced_at'] = $w['last_synced_at'];
        }
        if ($wp !== []) {
            app(WordpressArticleLinkWriter::class)->upsert($this, $wp);
        }

        if (array_key_exists('published_at', $w)) {
            app(PublishingArticleStateWriter::class)->upsert($this, [
                'published_at' => $w['published_at'],
                'publication_status' => $this->getAttribute('status'),
            ]);
        }

        $featuredKeys = ['featured_thumb_url', 'featured_media_id', 'featured_image_status', 'featured_image_source'];
        if (array_intersect_key($w, array_flip($featuredKeys)) !== []) {
            app(ArticleMediaStateWriter::class)->upsertFeatured($this, [
                'display_url' => $w['featured_thumb_url'] ?? null,
                'media_id' => $w['featured_media_id'] ?? null,
                'status' => $w['featured_image_status'] ?? null,
                'source' => $w['featured_image_source'] ?? null,
            ]);
        }

        // Archive columns: keep writing seo_content_archive_items via dedicated services only.
        // Attribute writes to content_archived_* are ignored here to avoid orphan archive rows.
    }
}
