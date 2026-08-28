<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Enums;

enum KeywordMetaKey: string
{
    case MainArticleId = 'main_article_id';
    case Tags = 'tags';
    case QualityFlags = 'quality_flags';
    /** Soft-hide: excluded from clustering / SEO planning lists, data preserved. */
    case SeoHidden = 'seo_keyword_hidden';

    /** Soft-skip: still SEO-eligible, excluded from Site MCP payload / share / tokens. */
    case McpExcluded = 'mcp_keyword_excluded';

    public static function siteTargetUrl(int $siteId): string
    {
        return "site.{$siteId}.target_url";
    }

    public static function siteSearchVolume(int $siteId): string
    {
        return "site.{$siteId}.search_volume";
    }

    public static function siteDifficulty(int $siteId): string
    {
        return "site.{$siteId}.difficulty";
    }

    public static function siteRescrapeKeep(int $siteId): string
    {
        return "site.{$siteId}.rescrape_keep";
    }

    public static function isSiteScopedKey(string $key): bool
    {
        return str_starts_with($key, 'site.');
    }

    public static function siteIdFromKey(string $key): ?int
    {
        if (! str_starts_with($key, 'site.')) {
            return null;
        }

        $parts = explode('.', $key, 3);
        if (count($parts) < 3 || ! is_numeric($parts[1])) {
            return null;
        }

        return (int) $parts[1];
    }
}
