<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Illuminate\Support\Facades\Schema;

final class SeoLinkMapLinkTypeClassifier
{
    public static function forManagedArticle(int $sourceSiteId, SeoArticle $targetArticle): SeoLinkMapType
    {
        return (int) ($targetArticle->site_id ?? 0) === $sourceSiteId
            ? SeoLinkMapType::Internal
            : SeoLinkMapType::External;
    }

    public static function forUnresolvedUrl(string $absoluteUrl): SeoLinkMapType
    {
        $host = self::resolveHost($absoluteUrl);

        return self::isWikiTrustHost($host)
            ? SeoLinkMapType::WikiTrust
            : SeoLinkMapType::External;
    }

    public static function isWikiTrustHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        foreach (self::wikiTrustDomainPatterns() as $pattern) {
            if (self::hostMatchesPattern($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function wikiTrustDomainPatterns(): array
    {
        if (! Schema::hasTable('wp_options')) {
            return ArticleEditorHistoryService::DEFAULT_WIKI_TRUST_DOMAINS;
        }

        return app(ArticleEditorHistoryService::class)->getWikiTrustDomains();
    }

    public static function resolveHost(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $host = parse_url($href, PHP_URL_HOST);

        return is_string($host) ? self::normalizeDomainHost($host) : '';
    }

    public static function normalizeDomainHost(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    private static function hostMatchesPattern(string $host, string $pattern): bool
    {
        $host = self::normalizeDomainHost($host);
        $pattern = self::normalizeDomainHost($pattern);

        if ($host === '' || $pattern === '') {
            return false;
        }

        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1);

            return $host === substr($pattern, 2) || str_ends_with($host, $suffix);
        }

        if (str_contains($pattern, '*')) {
            $escaped = preg_quote($pattern, '#');
            $escaped = str_replace('\*', '.*', $escaped);

            return preg_match('#^'.$escaped.'$#i', $host) === 1;
        }

        return $host === $pattern || str_ends_with($host, '.'.$pattern);
    }
}
