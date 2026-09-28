<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Illuminate\Support\Facades\Schema;

final class SeoLinkMapLinkTypeClassifier
{
    /**
     * Classify a link to a managed article.
     *
     * Returns Internal when the article belongs to the same site.
     * Returns ManagedCrossSite when the article belongs to a different managed site.
     */
    public static function forManagedArticle(int $sourceSiteId, SeoArticle $targetArticle): SeoLinkMapType
    {
        return (int) ($targetArticle->site_id ?? 0) === $sourceSiteId
            ? SeoLinkMapType::Internal
            : SeoLinkMapType::ManagedCrossSite;
    }

    /**
     * Classify an unresolved (external) URL.
     *
     * Priority: social → wiki_trust → needs_review.
     */
    public static function forUnresolvedUrl(string $absoluteUrl, ?int $sourceSiteId = null): SeoLinkMapType
    {
        // Contact schemes
        if (LinkDestinationClassifier::isContactScheme($absoluteUrl)) {
            return SeoLinkMapType::Contact;
        }

        $host = self::resolveHost($absoluteUrl);

        // Social host check (delegated to SSOT)
        if (LinkDestinationClassifier::isSocialHost($host)) {
            return SeoLinkMapType::Social;
        }

        // Managed site check
        $managedSiteId = LinkDestinationClassifier::resolveManagedSiteId($host);
        if ($managedSiteId !== null && $managedSiteId > 0) {
            if ($sourceSiteId !== null && $managedSiteId === $sourceSiteId) {
                return SeoLinkMapType::Internal;
            }

            return SeoLinkMapType::ManagedCrossSite;
        }

        // Trusted/reference host check
        if (self::isWikiTrustHost($host)) {
            return SeoLinkMapType::WikiTrust;
        }

        // Unknown external host — flag for review
        return SeoLinkMapType::NeedsReview;
    }

    /**
     * Returns true when this link type is a social or contact CTA (i.e. not content).
     */
    public static function isSocialOrContactType(SeoLinkMapType $type): bool
    {
        return $type->isCta();
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
        $host    = self::normalizeDomainHost($host);
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
