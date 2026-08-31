<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\ArticleWpContentCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary WP HTML cache for Article Editor (TTL 7 days).
 * Semantics: not canonical Article body — WP remains canonical when body is null.
 */
final class ArticleWpContentCacheService
{
    public const TTL_DAYS = 7;

    public function tableReady(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable('article_wp_content_cache');
        } catch (\Throwable) {
            return false;
        }
    }

    public function contentHash(string $html): string
    {
        return hash('sha256', $this->normalizeForHash($html));
    }

    public function normalizeForHash(string $html): string
    {
        $html = trim($html);
        $html = preg_replace('/\s+/u', ' ', $html) ?? $html;

        return trim($html);
    }

    /**
     * Valid (non-expired) cache row, or null.
     */
    public function findValid(SeoArticle $article): ?ArticleWpContentCache
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = ArticleWpContentCache::query()
            ->where('article_id', (int) $article->id)
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            $row->delete();

            return null;
        }

        // Sliding refresh of expiry on access.
        $row->expires_at = Carbon::now()->addDays(self::TTL_DAYS);
        $row->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function put(
        SeoArticle $article,
        string $renderedHtml,
        array $rawPayload = [],
        ?string $wpModifiedGmt = null,
        ?int $wpRevisionId = null,
    ): ?ArticleWpContentCache {
        if (! $this->tableReady()) {
            return null;
        }

        $html = trim($renderedHtml);
        if ($html === '') {
            return null;
        }

        $article->loadMissing('wordpressLink');
        $now = Carbon::now();

        return ArticleWpContentCache::query()->updateOrCreate(
            ['article_id' => (int) $article->id],
            [
                'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                'rendered_html' => $html,
                'raw_content_json' => $rawPayload !== [] ? $rawPayload : null,
                'wp_modified_gmt' => $wpModifiedGmt,
                'wp_content_hash' => $this->contentHash($html),
                'wp_revision_id' => $wpRevisionId,
                'fetched_at' => $now,
                'expires_at' => $now->copy()->addDays(self::TTL_DAYS),
            ],
        );
    }

    public function forget(SeoArticle $article): void
    {
        if (! $this->tableReady()) {
            return;
        }

        ArticleWpContentCache::query()
            ->where('article_id', (int) $article->id)
            ->delete();
    }

    /**
     * True when incoming editor HTML matches cached WP hash (no local edit).
     */
    public function matchesIncomingHtml(SeoArticle $article, string $html): bool
    {
        $row = $this->findValid($article);
        if ($row === null) {
            return false;
        }

        $stored = trim((string) ($row->wp_content_hash ?? ''));
        if ($stored === '') {
            return false;
        }

        return hash_equals($stored, $this->contentHash($html));
    }

    public function purgeExpired(): int
    {
        if (! $this->tableReady()) {
            return 0;
        }

        return (int) ArticleWpContentCache::query()
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }

    /**
     * Also drop cache rows for articles that already have local unsynced body.
     */
    public function purgeWhereBodyPresent(): int
    {
        if (! $this->tableReady()) {
            return 0;
        }

        return (int) ArticleWpContentCache::query()
            ->whereIn('article_id', function ($query): void {
                $query->select('id')
                    ->from('articles')
                    ->whereNotNull('body')
                    ->whereRaw("TRIM(body) != ''");
            })
            ->delete();
    }
}
