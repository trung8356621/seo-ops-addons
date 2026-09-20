<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Illuminate\Support\Facades\Cache;

/**
 * Shared eligibility + permalink SoT for placeable internal-link destinations.
 *
 * An article is a placeable link target only when it has a real WordPress post id
 * and a WordPress-provided permalink. Local slug / domain+slug guesses are never used.
 *
 * Semantic anchor candidates (Link Assistant idea pool) may include unsynced articles
 * with a null destination — that pool lives in ArticleLinkSuggestionCandidateRetriever
 * and must not require this eligibility gate.
 */
final class WordPressInternalLinkTargetPolicy
{
    public const SITE_INDEX_CACHE_PREFIX = 'article_link_suggest.site_index.v4.';

    public function wpPostId(SeoArticle $article): int
    {
        $article->loadMissing('wordpressLink');

        $fromLink = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($fromLink > 0) {
            return $fromLink;
        }

        return (int) ($article->getAttribute('wp_post_id') ?? 0);
    }

    public function isEligibleLinkTarget(SeoArticle $article): bool
    {
        return $this->wpPostId($article) > 0
            && $this->resolveAuthoritativePermalink($article) !== null;
    }

    /**
     * Authoritative public URL for internal-link insertion.
     *
     * Priority:
     * 1. wordpress_article_links.observed_permalink
     * 2. article_meta.wp_permalink (only when wp_post_id > 0)
     *
     * Returns null when WordPress has not provided a permalink — never fabricates
     * domain + local slug.
     */
    public function resolveAuthoritativePermalink(SeoArticle $article): ?string
    {
        if ($this->wpPostId($article) <= 0) {
            return null;
        }

        $article->loadMissing('wordpressLink');

        $observed = trim((string) ($article->wordpressLink?->observed_permalink ?? ''));
        if ($this->isValidPermalink($observed)) {
            return $observed;
        }

        $metaPermalink = $this->storedWpPermalink($article);
        if ($this->isValidPermalink($metaPermalink)) {
            return $metaPermalink;
        }

        return null;
    }

    public static function siteIndexCacheKey(int $siteId, ?string $language = null): string
    {
        $lang = ArticleLanguageCode::normalize((string) ($language ?? '')) ?: '_';

        return self::SITE_INDEX_CACHE_PREFIX.$siteId.'.'.$lang;
    }

    public static function forgetSiteIndexCache(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }

        // Forget language-scoped v4 keys + legacy unscoped v3 key if still present.
        Cache::forget('article_link_suggest.site_index.v3.'.$siteId);
        foreach (['_', 'vi', 'en', 'zh', 'ja', 'ko', 'fr', 'de', 'th', 'id'] as $lang) {
            Cache::forget(self::SITE_INDEX_CACHE_PREFIX.$siteId.'.'.$lang);
        }
    }

    public static function forgetSiteIndexCacheForArticle(SeoArticle $article): void
    {
        self::forgetSiteIndexCache((int) ($article->site_id ?? 0));
    }

    private function storedWpPermalink(SeoArticle $article): string
    {
        if ($article->relationLoaded('articleMetas')) {
            return trim((string) ($article->articleMetas
                ->firstWhere('meta_key', 'wp_permalink')
                ?->meta_value ?? ''));
        }

        return trim((string) ($article->articleMetas()
            ->where('meta_key', 'wp_permalink')
            ->value('meta_value') ?? ''));
    }

    private function isValidPermalink(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || $url === '/' || $url === '#') {
            return false;
        }

        $lower = strtolower($url);
        if (in_array($lower, ['http://', 'https://', 'http:///', 'https:///'], true)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return strlen($url) > 1;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $host = trim((string) ($parts['host'] ?? ''));

        return $host !== '';
    }
}
