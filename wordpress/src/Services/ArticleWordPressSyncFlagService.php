<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Theo dõi bài đã chỉnh trên SEO chưa đồng bộ WordPress và xung đột khi WP đẩy dữ liệu mới.
 */
final class ArticleWordPressSyncFlagService
{
    public const META_LOCAL_EDIT_PENDING = 'seo_local_edit_pending';

    /** Flag = true: WordPress đã gửi bản mới nhưng Laravel không ghi đè vì bài đang chỉnh local. */
    public const META_WP_DATA_OUT_OF_SYNC = 'wp_data_out_of_sync';

    public const META_LOCAL_CONTENT_HASH = 'seo_local_content_hash';

    public const META_PUBLISHED_CONTENT_HASH = 'seo_published_content_hash';

    public function markLocalEditPending(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_LOCAL_EDIT_PENDING, true);
    }

    public function clearLocalEditPending(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_LOCAL_EDIT_PENDING, false);
    }

    public function hasLocalEditPending(SeoArticle $article): bool
    {
        return $this->readFlag($article, self::META_LOCAL_EDIT_PENDING);
    }

    public function rememberLocalContentHash(SeoArticle $article, string $hash): void
    {
        $normalized = trim($hash);
        if ($normalized === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_LOCAL_CONTENT_HASH],
            ['meta_value' => $normalized],
        );
    }

    public function rememberPublishedContentHash(SeoArticle $article, string $hash): void
    {
        $normalized = trim($hash);
        if ($normalized === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_PUBLISHED_CONTENT_HASH],
            ['meta_value' => $normalized],
        );
        $this->clearLocalEditPending($article);
    }

    public function publishedContentHash(SeoArticle $article): ?string
    {
        $article->loadMissing('articleMetas');
        $value = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_PUBLISHED_CONTENT_HASH)?->meta_value ?? ''));

        return $value !== '' ? $value : null;
    }

    public function localContentHash(SeoArticle $article): ?string
    {
        $article->loadMissing('articleMetas');
        $value = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_LOCAL_CONTENT_HASH)?->meta_value ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * True when local body differs from last successfully published WordPress content.
     * wp_post_id alone is insufficient.
     */
    public function hasUnpublishedChanges(SeoArticle $article): bool
    {
        if ($this->hasLocalEditPending($article)) {
            return true;
        }

        $publishedHash = $this->publishedContentHash($article);
        if ($publishedHash === null) {
            return (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0
                && trim((string) ($article->body ?? '')) !== '';
        }

        $currentHash = hash('sha256', trim((string) ($article->body ?? '')));

        return ! hash_equals($publishedHash, $currentHash);
    }

    public function markDataOutOfSync(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_WP_DATA_OUT_OF_SYNC, true);
    }

    public function clearDataOutOfSync(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_WP_DATA_OUT_OF_SYNC, false);
    }

    public function hasDataOutOfSync(SeoArticle $article): bool
    {
        return $this->readFlag($article, self::META_WP_DATA_OUT_OF_SYNC);
    }

    public function clearAll(SeoArticle $article): void
    {
        $this->clearLocalEditPending($article);
        $this->clearDataOutOfSync($article);
        $this->clearBodyMediaSyncPending($article);
    }

    public const META_BODY_MEDIA_SYNC_PENDING = 'wp_body_media_sync_pending';

    public function markBodyMediaSyncPending(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_BODY_MEDIA_SYNC_PENDING, true);
    }

    public function clearBodyMediaSyncPending(SeoArticle $article): void
    {
        $this->setFlag($article, self::META_BODY_MEDIA_SYNC_PENDING, false);
    }

    public function hasBodyMediaSyncPending(SeoArticle $article): bool
    {
        return $this->readFlag($article, self::META_BODY_MEDIA_SYNC_PENDING);
    }

    public function hasLocalEditorContent(SeoArticle $article): bool
    {
        return trim((string) ($article->body ?? '')) !== '';
    }

    public function shouldBlockWordPressImport(SeoArticle $article): bool
    {
        if (! $this->hasLocalEditPending($article)) {
            return false;
        }

        if (! $this->hasLocalEditorContent($article)) {
            $this->clearLocalEditPending($article);

            return false;
        }

        return true;
    }

    public function decodeWordPressText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function setFlag(SeoArticle $article, string $key, bool $active): void
    {
        if ($active) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => '1'],
            );

            return;
        }

        $article->articleMetas()->where('meta_key', $key)->delete();
    }

    private function readFlag(SeoArticle $article, string $key): bool
    {
        $article->loadMissing('articleMetas');

        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;

        if ($value === null || $value === '') {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }
}
